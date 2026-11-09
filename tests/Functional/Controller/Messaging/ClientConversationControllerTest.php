<?php

namespace App\Tests\Functional\Controller\Messaging;

use App\Entity\Catalog\Country;
use App\Entity\Identity\User;
use App\Entity\Matching\ClientRequest;
use App\Entity\Messaging\Conversation;
use App\Entity\Messaging\Message;
use App\Entity\Producer\ProducerProfile;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste la liste des conversations du client (cahier fonctionnel, dashboard client : "Messages -- inbox par demande, non lus").
 */
final class ClientConversationControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private const PASSWORD = 'motdepasse123';

    private function login(User $user): string
    {
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $user->getEmail(),
            'password' => self::PASSWORD,
        ]));

        return json_decode($this->client->getResponse()->getContent(), true)['token'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(User $user): array
    {
        $this->client->request('GET', '/api/client/conversations', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->login($user)]);

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function makeProducer(string $name): ProducerProfile
    {
        $owner = $this->makeUserWithPassword($name, self::PASSWORD);
        $owner->setRoles([User::ROLE_PRODUCER]);

        return $this->makeProducerProfile($owner, $this->em->find(Country::class, 'FR') ?? $this->makeCountry(), farmName: 'Ferme '.$name);
    }

    private function makeConversation(ClientRequest $request, ProducerProfile $producer, ?\DateTimeImmutable $lastMessageAt = null): Conversation
    {
        $conversation = new Conversation();
        $conversation->setRequest($request);
        $conversation->setProducer($producer);
        $conversation->setClient($request->getClient());
        $conversation->setLastMessageAt($lastMessageAt);
        $this->em->persist($conversation);

        return $conversation;
    }

    private function makeMessage(Conversation $conversation, ?User $sender, string $content): Message
    {
        $message = new Message();
        $message->setConversation($conversation);
        $message->setSender($sender);
        $message->setContent($content);
        $this->em->persist($message);

        return $message;
    }

    public function testTheClientSeesItsConversationsWithTheProducerTheProductAndTheLastMessage(): void
    {
        $client = $this->makeUserWithPassword('client', self::PASSWORD);
        $request = $this->makeClientRequest($client, $this->makeProduct($this->makeCategory()));
        $producer = $this->makeProducer('miel');
        $conversation = $this->makeConversation($request, $producer, new \DateTimeImmutable('-1 hour'));
        $this->makeMessage($conversation, $producer->getOwner(), 'Bonjour, oui disponible.');
        $this->em->flush();

        $rows = $this->list($client);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $rows);
        self::assertSame($conversation->getId()->toRfc4122(), $rows[0]['id']);
        self::assertSame($request->getId()->toRfc4122(), $rows[0]['requestId']);
        self::assertSame('Ferme miel', $rows[0]['producerName']);
        self::assertSame($producer->getSlug(), $rows[0]['producerSlug']);
        self::assertSame('Pommes', $rows[0]['product']);
        self::assertSame('open', $rows[0]['status']);
        // * le producteur a écrit en dernier et le client ne l'a pas lu
        self::assertSame(1, $rows[0]['unreadCount']);
        self::assertSame('Bonjour, oui disponible.', $rows[0]['lastMessage']['preview']);
        self::assertFalse($rows[0]['lastMessage']['fromMe']);
    }

    public function testTheMostRecentConversationComesFirstAndTheOthersClientsAreNotListed(): void
    {
        $client = $this->makeUserWithPassword('client', self::PASSWORD);
        $other = $this->makeUserWithPassword('other', self::PASSWORD);
        $category = $this->makeCategory();
        $oldRequest = $this->makeClientRequest($client, $this->makeProduct($category, 'Poires'));
        $newRequest = $this->makeClientRequest($client, $this->makeProduct($category, 'Cerises'));
        $otherRequest = $this->makeClientRequest($other, $this->makeProduct($category, 'Prunes'));
        $producer = $this->makeProducer('verger');
        $this->makeConversation($oldRequest, $producer, new \DateTimeImmutable('-2 days'));
        $this->makeConversation($newRequest, $producer, new \DateTimeImmutable('-1 hour'));
        $this->makeConversation($otherRequest, $producer, new \DateTimeImmutable('-1 minute'));
        $this->em->flush();

        $products = array_map(static fn (array $row) => $row['product'], $this->list($client));

        self::assertSame(['Cerises', 'Poires'], $products);
    }

    public function testAConversationWithoutAnyMessageHasNoPreview(): void
    {
        $client = $this->makeUserWithPassword('client', self::PASSWORD);
        $this->makeConversation($this->makeClientRequest($client), $this->makeProducer('vide'));
        $this->em->flush();

        $rows = $this->list($client);

        self::assertNull($rows[0]['lastMessage']);
        self::assertSame(0, $rows[0]['unreadCount']);
    }

    public function testTheListNeedsAnAuthenticatedAccount(): void
    {
        $this->client->request('GET', '/api/client/conversations');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Un client avec $count conversations, de la plus récente (index 0) à la plus ancienne.
     *
     * @return array{0: User, 1: list<string>}
     */
    private function makeClientWithConversations(int $count): array
    {
        $client = $this->makeUserWithPassword('client', self::PASSWORD);
        $producer = $this->makeProducer('verger');
        $ids = [];
        for ($i = 0; $i < $count; ++$i) {
            $ids[] = $this->makeConversation($this->makeClientRequest($client), $producer, new \DateTimeImmutable('-'.($i + 1).' hours'))->getId()->toRfc4122();
        }
        $this->em->flush();

        return [$client, $ids];
    }

    public function testTheListIsPaginatedMostRecentFirstWithTheTotalInTheHeader(): void
    {
        [$client, $ids] = $this->makeClientWithConversations(5);

        $this->client->request('GET', '/api/client/conversations?pageSize=2&page=2', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->login($client)]);

        self::assertResponseIsSuccessful();
        self::assertSame([$ids[2], $ids[3]], array_column(json_decode($this->client->getResponse()->getContent(), true), 'id'));
        self::assertSame('5', $this->client->getResponse()->headers->get('X-Total-Count'));
    }

    public function testTheListIsBoundedEvenWithoutParameters(): void
    {
        [$client] = $this->makeClientWithConversations(21);

        $this->client->request('GET', '/api/client/conversations', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->login($client)]);

        self::assertCount(20, json_decode($this->client->getResponse()->getContent(), true));
        self::assertSame('21', $this->client->getResponse()->headers->get('X-Total-Count'));
    }

    public function testTheListRejectsInvalidPagination(): void
    {
        [$client] = $this->makeClientWithConversations(1);
        $token = $this->login($client);

        foreach (['page=0', 'pageSize=0', 'pageSize=101', 'pageSize=abc'] as $query) {
            $this->client->request('GET', '/api/client/conversations?'.$query, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
            self::assertResponseStatusCodeSame(400, $query);
        }
    }
}
