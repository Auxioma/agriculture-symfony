<?php

namespace App\Tests\Functional;

use App\Entity\Identity\User;
use App\Entity\Messaging\BlockedUser;
use App\Entity\Producer\ProducerProfile;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste GET /api/conversations, GET /api/conversations/{id}, POST .../messages et POST .../report
 * (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf §20.6, round 1). POST .../attachments est hors scope --
 * il attend l'architecture de stockage fichiers (S3/MinIO, cahier devops).
 */
final class ConversationControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    /**
     * Fait exister une conversation ouverte via le flux réel (demande, matching, réponse producteur) plutôt
     * que de la créer directement en fixture -- ConversationController n'expose aucune route de création,
     * la conversation n'existe donc que comme effet de bord de ProducerRequestController::replyToRequest().
     *
     * @return array{0: string, 1: string, 2: string, 3: User} [conversationId, tokenClient, tokenProducer, client]
     */
    private function setUpOpenConversation(): array
    {
        $tokenClient = $this->registerClientAndLogin('client');

        $country = $this->makeCountry();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $producerOwner = $this->makeUserWithPassword('producer', 'motdepasse123');
        $producerOwner->setRoles([User::ROLE_PRODUCER]);
        $producer = $this->makeProducerProfile($producerOwner, $country);
        $this->makeProducerProduct($producer, $product, true);
        $this->em->flush();

        $this->setGeographyPoint('producer.producer_profiles', 'location', $producer->getId()->toRfc4122(), 2.35, 48.85);

        $this->client->request('POST', '/api/requests', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient,
        ], content: json_encode([
            'needType' => 'price_request',
            'productId' => $product->getId()->toRfc4122(),
            'latitude' => 48.86,
            'longitude' => 2.36,
        ]));
        $requestId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $producerOwner->getEmail(),
            'password' => 'motdepasse123',
        ]));
        $tokenProducer = json_decode($this->client->getResponse()->getContent(), true)['token'];

        // ! ServicesResetter vide l'identity map après chaque requête HTTP -- $producer doit être rechargé
        // ! avant tout persist() ultérieur qui le référence (ex. makeActiveSubscription).
        $producer = $this->em->getRepository(ProducerProfile::class)->find($producer->getId());
        $this->makeActiveSubscription($producer, features: ['reply_to_requests' => true]);
        $this->em->flush();

        $this->client->request('POST', '/api/producer/requests/'.$requestId.'/reply', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenProducer,
        ], content: json_encode(['replyText' => 'Oui disponible']));
        self::assertResponseStatusCodeSame(201);

        $conversationId = $this->em->getConnection()->fetchOne(
            'SELECT id FROM messaging.conversations WHERE request_id = :requestId',
            ['requestId' => $requestId]
        );

        // * Plus simple de relire le client depuis la conversation fraîchement créée que de garder une
        // * référence à l'entité User d'origine, détachée depuis longtemps par les multiples requêtes HTTP
        // * ci-dessus (voir le commentaire ! plus haut sur $producer).
        $clientId = $this->em->getConnection()->fetchOne('SELECT client_id FROM messaging.conversations WHERE id = :id', ['id' => $conversationId]);
        $client = $this->em->getRepository(User::class)->find($clientId);

        return [$conversationId, $tokenClient, $tokenProducer, $client];
    }

    public function testListConversationsReturnsOnlyMine(): void
    {
        [, $tokenClient, $tokenProducer] = $this->setUpOpenConversation();

        $this->client->request('GET', '/api/conversations', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient]);
        self::assertResponseIsSuccessful();
        $asClient = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $asClient);

        $this->client->request('GET', '/api/conversations', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokenProducer]);
        self::assertResponseIsSuccessful();
        $asProducer = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $asProducer);
        self::assertSame($asClient[0]['id'], $asProducer[0]['id']);
    }

    public function testGetConversationReturnsMessagesInOrder(): void
    {
        [$conversationId, $tokenClient] = $this->setUpOpenConversation();

        $this->client->request('POST', '/api/conversations/'.$conversationId.'/messages', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient,
        ], content: json_encode(['content' => 'Bonjour, toujours disponible ?']));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/conversations/'.$conversationId, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data['messages']);
        self::assertSame('Bonjour, toujours disponible ?', $data['messages'][0]['content']);
    }

    public function testGetConversationRejectsNonParticipant(): void
    {
        [$conversationId] = $this->setUpOpenConversation();
        $tokenOther = $this->registerClientAndLogin('other');

        $this->client->request('GET', '/api/conversations/'.$conversationId, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokenOther]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testSendMessageOpensRequestConversationStatus(): void
    {
        [$conversationId, $tokenClient] = $this->setUpOpenConversation();

        $requestRow = $this->em->getConnection()->fetchAssociative(
            'SELECT request_id FROM messaging.conversations WHERE id = :id',
            ['id' => $conversationId]
        );

        $this->client->request('POST', '/api/conversations/'.$conversationId.'/messages', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient,
        ], content: json_encode(['content' => 'Bonjour !']));
        self::assertResponseStatusCodeSame(201);

        $statusRow = $this->em->getConnection()->fetchAssociative(
            'SELECT status FROM matching.client_requests WHERE id = :id',
            ['id' => $requestRow['request_id']]
        );
        self::assertSame('conversation_open', $statusRow['status']);
    }

    public function testSendMessageRejectsWhenRecipientHasBlockedSender(): void
    {
        [$conversationId, $tokenClient, , $client] = $this->setUpOpenConversation();

        $conversationRow = $this->em->getConnection()->fetchAssociative(
            'SELECT producer_id FROM messaging.conversations WHERE id = :id',
            ['id' => $conversationId]
        );
        $producer = $this->em->getRepository(ProducerProfile::class)->find($conversationRow['producer_id']);
        $producerOwner = $producer->getOwner();

        $blockedUser = new BlockedUser();
        $blockedUser->setBlocker($producerOwner);
        $blockedUser->setBlocked($client);
        $blockedUser->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($blockedUser);
        $this->em->flush();

        $this->client->request('POST', '/api/conversations/'.$conversationId.'/messages', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient,
        ], content: json_encode(['content' => 'Bonjour ?']));

        self::assertResponseStatusCodeSame(403);
    }

    public function testReportConversationSucceeds(): void
    {
        [$conversationId, $tokenClient] = $this->setUpOpenConversation();

        $this->client->request('POST', '/api/conversations/'.$conversationId.'/report', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient,
        ], content: json_encode(['reason' => 'contenu inapproprié']));

        self::assertResponseStatusCodeSame(201);
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status FROM messaging.conversations WHERE id = :id',
            ['id' => $conversationId]
        );
        self::assertSame('reported', $row['status']);
    }

    public function testReportConversationRejectsNonParticipant(): void
    {
        [$conversationId] = $this->setUpOpenConversation();
        $tokenOther = $this->registerClientAndLogin('other');

        $this->client->request('POST', '/api/conversations/'.$conversationId.'/report', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenOther,
        ], content: json_encode(['reason' => 'peu importe']));

        self::assertResponseStatusCodeSame(403);
    }
}
