<?php

namespace App\Tests\Functional\Controller\Messaging;

use App\Entity\Identity\User;
use App\Entity\Messaging\BlockedUser;
use App\Entity\Producer\ProducerProfile;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Teste GET /api/conversations, GET /api/conversations/{id}, POST .../messages, POST .../report et
 * POST .../attachments (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf, rounds 1 et 2).
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

    public function testSendMessageNotifiesOtherParty(): void
    {
        [$conversationId, $tokenClient] = $this->setUpOpenConversation();

        // * Le client envoie le message : cahier fonctionnel "Nouveau message" doit notifier l'autre partie, le
        // * producteur (propriétaire du profil lié à la conversation), pas l'expéditeur lui-même.
        $producerOwnerId = $this->em->getConnection()->fetchOne(
            'SELECT pp.owner_user_id FROM messaging.conversations c JOIN producer.producer_profiles pp ON pp.id = c.producer_id WHERE c.id = :id',
            ['id' => $conversationId]
        );

        $this->client->request('POST', '/api/conversations/'.$conversationId.'/messages', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient,
        ], content: json_encode(['content' => 'Bonjour !']));
        self::assertResponseStatusCodeSame(201);

        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT type FROM notification.notifications WHERE user_id = :userId AND type = 'new_message'",
            ['userId' => $producerOwnerId]
        );
        self::assertNotFalse($row);
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

    public function testUploadAttachmentSucceedsAndAppearsWithSignedUrl(): void
    {
        [$conversationId, $tokenClient] = $this->setUpOpenConversation();

        $file = new UploadedFile(__DIR__.'/../../../Fixtures/files/sample.jpg', 'sample.jpg', 'image/jpeg', null, true);

        $this->client->request('POST', '/api/conversations/'.$conversationId.'/attachments', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient,
        ], files: ['file' => $file], content: null);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT file_name, mime_type FROM messaging.message_attachments WHERE id = :id',
            ['id' => $data['attachmentId']]
        );
        self::assertNotFalse($row);
        self::assertSame('sample.jpg', $row['file_name']);
        self::assertSame('image/jpeg', $row['mime_type']);

        // * fileUrl en base est une clé objet privée -- ce qui compte ici c'est qu'un GET renvoie bien une
        // * URL exploitable (signée par FakeTemporaryUrlGenerator en test), pas une clé brute inutilisable.
        $this->client->request('GET', '/api/conversations/'.$conversationId, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient]);
        self::assertResponseIsSuccessful();
        $conversation = json_decode($this->client->getResponse()->getContent(), true);
        $messageWithAttachment = end($conversation['messages']);
        self::assertCount(1, $messageWithAttachment['attachments']);
        self::assertSame('sample.jpg', $messageWithAttachment['attachments'][0]['fileName']);
        self::assertStringStartsWith('https://attachments.test/', $messageWithAttachment['attachments'][0]['url']);
    }

    public function testUploadAttachmentRejectsUnsupportedMimeType(): void
    {
        [$conversationId, $tokenClient] = $this->setUpOpenConversation();

        $file = new UploadedFile(__DIR__.'/../../../Fixtures/files/sample.txt', 'sample.txt', 'text/plain', null, true);

        $this->client->request('POST', '/api/conversations/'.$conversationId.'/attachments', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient,
        ], files: ['file' => $file], content: null);

        self::assertResponseStatusCodeSame(422);
    }

    public function testUploadAttachmentRejectsNonParticipant(): void
    {
        [$conversationId] = $this->setUpOpenConversation();
        $tokenOther = $this->registerClientAndLogin('other');
        $file = new UploadedFile(__DIR__.'/../../../Fixtures/files/sample.jpg', 'sample.jpg', 'image/jpeg', null, true);

        $this->client->request('POST', '/api/conversations/'.$conversationId.'/attachments', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenOther,
        ], files: ['file' => $file], content: null);

        self::assertResponseStatusCodeSame(403);
    }

    public function testGetConversationMarksOtherPartysMessagesAsReadAndReportsReadStatus(): void
    {
        [$conversationId, $tokenClient, $tokenProducer] = $this->setUpOpenConversation();

        // * Le producteur envoie un message : il n'est pas encore lu par le client tant que ce dernier
        // * n'a pas ouvert la conversation (readAt doit rester null de son point de vue).
        $this->client->request('POST', '/api/conversations/'.$conversationId.'/messages', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenProducer,
        ], content: json_encode(['content' => 'Oui, toujours disponible.']));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/conversations/'.$conversationId, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokenProducer]);
        $asProducer = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNull($asProducer['messages'][0]['readAt'], 'Le client ne l\'a pas encore lu.');

        // * Le client ouvre la conversation : ça doit marquer le message du producteur comme lu par lui.
        $this->client->request('GET', '/api/conversations/'.$conversationId, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient]);
        self::assertResponseIsSuccessful();

        // * Le producteur reconsulte : readAt doit maintenant être renseigné, puisque le client (le
        // * destinataire de CE message) l'a lu entre-temps.
        $this->client->request('GET', '/api/conversations/'.$conversationId, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokenProducer]);
        $asProducer = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNotNull($asProducer['messages'][0]['readAt']);
    }

    public function testListConversationsReportsUnreadCount(): void
    {
        [$conversationId, $tokenClient, $tokenProducer] = $this->setUpOpenConversation();

        $this->client->request('POST', '/api/conversations/'.$conversationId.'/messages', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenProducer,
        ], content: json_encode(['content' => 'Oui, toujours disponible.']));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/conversations', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient]);
        $asClient = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(1, $asClient[0]['unreadCount']);

        // * Ouvrir la conversation marque le message comme lu : le compteur doit retomber à 0.
        $this->client->request('GET', '/api/conversations/'.$conversationId, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/conversations', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient]);
        $asClient = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(0, $asClient[0]['unreadCount']);

        // * Le producteur n'a jamais lu son propre message envoyé -- il n'est pas non plus compté comme
        // * "non lu" pour lui (on ne compte que les messages reçus, pas les siens).
        $this->client->request('GET', '/api/conversations', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokenProducer]);
        $asProducer = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(0, $asProducer[0]['unreadCount']);
    }

    public function testUploadAttachmentRejectsWhenRecipientHasBlockedSender(): void
    {
        [$conversationId, $tokenClient, , $client] = $this->setUpOpenConversation();

        $conversationRow = $this->em->getConnection()->fetchAssociative(
            'SELECT producer_id FROM messaging.conversations WHERE id = :id',
            ['id' => $conversationId]
        );
        $producer = $this->em->getRepository(ProducerProfile::class)->find($conversationRow['producer_id']);

        $blockedUser = new BlockedUser();
        $blockedUser->setBlocker($producer->getOwner());
        $blockedUser->setBlocked($client);
        $blockedUser->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($blockedUser);
        $this->em->flush();

        $file = new UploadedFile(__DIR__.'/../../../Fixtures/files/sample.jpg', 'sample.jpg', 'image/jpeg', null, true);

        $this->client->request('POST', '/api/conversations/'.$conversationId.'/attachments', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient,
        ], files: ['file' => $file], content: null);

        self::assertResponseStatusCodeSame(403);
    }
}
