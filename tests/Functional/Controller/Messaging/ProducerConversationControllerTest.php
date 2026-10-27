<?php

namespace App\Tests\Functional\Controller\Messaging;

use App\Entity\Catalog\Country;
use App\Entity\Catalog\Unit;
use App\Entity\Identity\User;
use App\Entity\Messaging\Conversation;
use App\Entity\Messaging\Message;
use App\Entity\Messaging\MessageAttachment;
use App\Entity\Producer\ProducerProfile;
use App\Enum\ConversationStatus;
use App\Enum\NeedType;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste GET /api/producer/conversations (cahier fonctionnel, messagerie : "Conversation liée à une demande précise",
 * "Indicateurs lu / non lu").
 */
final class ProducerConversationControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private ?Country $country = null;

    private function country(): Country
    {
        return $this->country ??= $this->makeCountry();
    }

    /**
     * @return array{0: User, 1: ProducerProfile}
     */
    private function makeProducer(bool $subscribed = true): array
    {
        $owner = $this->makeUserWithPassword('producer', 'motdepasse123');
        $owner->setRoles([User::ROLE_PRODUCER]);
        $producer = $this->makeProducerProfile($owner, $this->country());
        if ($subscribed) {
            $this->makeActiveSubscription($producer);
        }

        return [$owner, $producer];
    }

    // * created_at n'a pas de fractions de seconde et vaut "maintenant" à la création : on le pose en SQL pour que l'ordre soit sûr
    private function makeConversation(ProducerProfile $producer, ?User $client = null, string $createdAt = '2026-10-01 08:00:00+00', ?string $lastMessageAt = null, ConversationStatus $status = ConversationStatus::Open): Conversation
    {
        $client ??= $this->makeUser('client');
        $conversation = new Conversation();
        $conversation->setRequest($this->makeClientRequest($client));
        $conversation->setClient($client);
        $conversation->setProducer($producer);
        $conversation->setStatus($status);
        if (null !== $lastMessageAt) {
            $conversation->setLastMessageAt(new \DateTimeImmutable($lastMessageAt));
        }
        $this->em->persist($conversation);
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            'UPDATE messaging.conversations SET created_at = :at WHERE id = :id',
            ['at' => $createdAt, 'id' => $conversation->getId()->toRfc4122()]
        );

        return $conversation;
    }

    private function addMessage(Conversation $conversation, ?User $sender, ?string $content, string $at, bool $system = false): Message
    {
        $message = new Message();
        $message->setConversation($conversation);
        $message->setSender($sender);
        $message->setContent($content);
        $message->setIsSystem($system);
        $this->em->persist($message);
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            'UPDATE messaging.messages SET created_at = :at WHERE id = :id',
            ['at' => $at, 'id' => $message->getId()->toRfc4122()]
        );

        return $message;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listAs(User $owner): array
    {
        $this->client->request('GET', '/api/producer/conversations', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->tokenOf($owner)]);
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function tokenOf(User $owner): string
    {
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $owner->getEmail(),
            'password' => 'motdepasse123',
        ]));

        return json_decode($this->client->getResponse()->getContent(), true)['token'];
    }

    public function testRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/producer/conversations');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRefusesAnAccountWithoutProducerProfile(): void
    {
        $token = $this->registerClientAndLogin('client');

        $this->client->request('GET', '/api/producer/conversations', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testListsOnlyTheProducersOwnConversations(): void
    {
        [$owner, $producer] = $this->makeProducer();
        [, $otherProducer] = $this->makeProducer();
        $mine = $this->makeConversation($producer);
        $this->makeConversation($otherProducer);
        $this->em->flush();

        $list = $this->listAs($owner);

        self::assertCount(1, $list);
        self::assertSame($mine->getId()->toRfc4122(), $list[0]['id']);
    }

    public function testReturnsAnEmptyListWhenThereIsNoConversation(): void
    {
        [$owner] = $this->makeProducer();
        $this->em->flush();

        self::assertSame([], $this->listAs($owner));
    }

    public function testSortsByLatestActivityAndPlacesAConversationWithoutMessageAtItsCreation(): void
    {
        [$owner, $producer] = $this->makeProducer();
        $old = $this->makeConversation($producer, createdAt: '2026-10-01 08:00:00+00', lastMessageAt: '2026-10-02 08:00:00+00');
        $recent = $this->makeConversation($producer, createdAt: '2026-10-01 09:00:00+00', lastMessageAt: '2026-10-05 08:00:00+00');
        // * aucun message : sa date d'activité est sa création, entre les deux autres
        $empty = $this->makeConversation($producer, createdAt: '2026-10-03 08:00:00+00');
        $this->em->flush();

        $ids = array_column($this->listAs($owner), 'id');

        self::assertSame([$recent->getId()->toRfc4122(), $empty->getId()->toRfc4122(), $old->getId()->toRfc4122()], $ids);
    }

    public function testSummarisesTheRequestAndTheClient(): void
    {
        [$owner, $producer] = $this->makeProducer();
        $client = $this->makeUser('client');
        $client->setFirstName('Marie');
        $client->setLastName('Durand');
        $conversation = $this->makeConversation($producer, $client, status: ConversationStatus::Reported);
        $request = $conversation->getRequest();
        $request->setQuantity('20');
        $unit = (new Unit())->setCode('kg')->setLabel('Kilogramme');
        $this->em->persist($unit);
        $request->setUnit($unit);
        $this->em->flush();

        $row = $this->listAs($owner)[0];

        self::assertSame($request->getId()->toRfc4122(), $row['requestId']);
        self::assertSame('Marie D.', $row['clientName']);
        self::assertSame('Produit non catalogue', $row['product']);
        self::assertEquals(20, $row['quantity']);
        self::assertSame('kg', $row['unit']);
        self::assertSame('reported', $row['status']);
    }

    public function testTellsAProfessionalClientFromAnIndividualOne(): void
    {
        [$owner, $producer] = $this->makeProducer();
        $this->makeConversation($producer, createdAt: '2026-10-02 08:00:00+00');
        $professional = $this->makeConversation($producer, createdAt: '2026-10-01 08:00:00+00');
        $professional->getRequest()->setNeedType(NeedType::Professional);
        $this->em->flush();

        self::assertSame(['individual', 'professional'], array_column($this->listAs($owner), 'clientType'));
    }

    public function testHidesTheClientNameWithoutAValidSubscription(): void
    {
        [$owner, $producer] = $this->makeProducer(subscribed: false);
        $client = $this->makeUser('client');
        $client->setFirstName('Marie');
        $client->setLastName('Durand');
        $this->makeConversation($producer, $client);
        $this->em->flush();

        self::assertSame('Client', $this->listAs($owner)[0]['clientName']);
    }

    public function testShowsTheLatestMessageAndWhoSentIt(): void
    {
        [$owner, $producer] = $this->makeProducer();
        $client = $this->makeUser('client');
        $conversation = $this->makeConversation($producer, $client);
        $this->addMessage($conversation, $client, 'Bonjour, toujours disponible ?', '2026-10-05 08:00:00+00');
        $this->addMessage($conversation, $owner, 'Oui, jusqu\'à samedi.', '2026-10-05 09:00:00+00');
        $this->addMessage($conversation, $client, 'Parfait, je passe samedi.', '2026-10-05 10:00:00+00');
        $this->em->flush();

        $last = $this->listAs($owner)[0]['lastMessage'];

        self::assertSame('Parfait, je passe samedi.', $last['preview']);
        self::assertFalse($last['fromMe']);
        self::assertFalse($last['isSystem']);
        self::assertFalse($last['hasAttachment']);
        // * le fuseau de la réponse n'est pas celui de la base : on compare l'instant
        self::assertSame((new \DateTimeImmutable('2026-10-05 10:00:00+00'))->getTimestamp(), (new \DateTimeImmutable($last['createdAt']))->getTimestamp());
    }

    public function testFlagsTheProducersOwnLastMessage(): void
    {
        [$owner, $producer] = $this->makeProducer();
        $client = $this->makeUser('client');
        $conversation = $this->makeConversation($producer, $client);
        $this->addMessage($conversation, $client, 'Bonjour', '2026-10-05 08:00:00+00');
        $this->addMessage($conversation, $owner, 'Bonjour, ce produit est disponible.', '2026-10-05 09:00:00+00');
        $this->em->flush();

        self::assertTrue($this->listAs($owner)[0]['lastMessage']['fromMe']);
    }

    public function testEachConversationGetsItsOwnLatestMessage(): void
    {
        [$owner, $producer] = $this->makeProducer();
        $client = $this->makeUser('client');
        $first = $this->makeConversation($producer, $client, lastMessageAt: '2026-10-05 12:00:00+00');
        $second = $this->makeConversation($producer, null, lastMessageAt: '2026-10-04 12:00:00+00');
        $this->addMessage($first, $client, 'Premier fil, ancien', '2026-10-05 08:00:00+00');
        $this->addMessage($first, $client, 'Premier fil, dernier', '2026-10-05 12:00:00+00');
        $this->addMessage($second, $second->getClient(), 'Second fil, dernier', '2026-10-04 12:00:00+00');
        $this->em->flush();

        $list = $this->listAs($owner);

        self::assertSame('Premier fil, dernier', $list[0]['lastMessage']['preview']);
        self::assertSame('Second fil, dernier', $list[1]['lastMessage']['preview']);
    }

    public function testCutsALongMessageInThePreview(): void
    {
        [$owner, $producer] = $this->makeProducer();
        $client = $this->makeUser('client');
        $conversation = $this->makeConversation($producer, $client);
        $this->addMessage($conversation, $client, str_repeat('a', 500), '2026-10-05 08:00:00+00');
        $this->em->flush();

        $preview = $this->listAs($owner)[0]['lastMessage']['preview'];

        self::assertSame(120, mb_strlen($preview));
        self::assertStringEndsWith('…', $preview);
    }

    public function testHidesTheTextOfAModeratedMessage(): void
    {
        [$owner, $producer] = $this->makeProducer();
        $client = $this->makeUser('client');
        $conversation = $this->makeConversation($producer, $client);
        $message = $this->addMessage($conversation, $client, 'Texte retiré par la modération', '2026-10-05 08:00:00+00');
        $message->setModeratedAt(new \DateTimeImmutable());
        $this->em->flush();

        $last = $this->listAs($owner)[0]['lastMessage'];

        self::assertNull($last['preview']);
        self::assertTrue($last['moderated']);
    }

    public function testReportsAnAttachmentWithoutCaption(): void
    {
        [$owner, $producer] = $this->makeProducer();
        $client = $this->makeUser('client');
        $conversation = $this->makeConversation($producer, $client);
        $message = $this->addMessage($conversation, $client, null, '2026-10-05 08:00:00+00');
        $attachment = new MessageAttachment();
        $attachment->setMessage($message);
        $attachment->setFileName('photo.jpg');
        $attachment->setMimeType('image/jpeg');
        $attachment->setFileSize(1000);
        $attachment->setFileUrl('conversation/photo');
        $this->em->persist($attachment);
        $this->em->flush();

        $last = $this->listAs($owner)[0]['lastMessage'];

        self::assertNull($last['preview']);
        self::assertTrue($last['hasAttachment']);
    }

    public function testMarksASystemMessage(): void
    {
        [$owner, $producer] = $this->makeProducer();
        $conversation = $this->makeConversation($producer);
        $this->addMessage($conversation, null, 'Devis envoyé.', '2026-10-05 08:00:00+00', system: true);
        $this->em->flush();

        $last = $this->listAs($owner)[0]['lastMessage'];

        self::assertTrue($last['isSystem']);
        self::assertFalse($last['fromMe']);
    }

    public function testAConversationWithoutMessageHasNoLastMessageAndNothingUnread(): void
    {
        [$owner, $producer] = $this->makeProducer();
        $this->makeConversation($producer);
        $this->em->flush();

        $row = $this->listAs($owner)[0];

        self::assertNull($row['lastMessage']);
        self::assertNull($row['lastMessageAt']);
        self::assertSame(0, $row['unreadCount']);
    }

    public function testCountsOnlyTheMessagesTheClientSentAndNotYetRead(): void
    {
        [$owner, $producer] = $this->makeProducer();
        $client = $this->makeUser('client');
        $conversation = $this->makeConversation($producer, $client);
        $this->addMessage($conversation, $client, 'Un', '2026-10-05 08:00:00+00');
        $this->addMessage($conversation, $client, 'Deux', '2026-10-05 08:30:00+00');
        $this->addMessage($conversation, $owner, 'Ma réponse', '2026-10-05 09:00:00+00');
        $this->em->flush();

        self::assertSame(2, $this->listAs($owner)[0]['unreadCount']);
    }

    // * lire le fil passe par la route partagée de ConversationController : la liste doit en tenir compte
    public function testOpeningTheThreadMakesTheCountFallToZero(): void
    {
        [$owner, $producer] = $this->makeProducer();
        $client = $this->makeUser('client');
        $conversation = $this->makeConversation($producer, $client);
        $this->addMessage($conversation, $client, 'Bonjour', '2026-10-05 08:00:00+00');
        $this->em->flush();
        self::assertSame(1, $this->listAs($owner)[0]['unreadCount']);
        $token = $this->tokenOf($owner);

        $this->client->request('GET', '/api/conversations/'.$conversation->getId()->toRfc4122(), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();

        self::assertSame(0, $this->listAs($owner)[0]['unreadCount']);
    }
}
