<?php

namespace App\Tests\Functional\Controller\Matching;

use App\Entity\Catalog\Country;
use App\Entity\Identity\User;
use App\Entity\Matching\ClientRequest;
use App\Entity\Matching\ProducerReply;
use App\Entity\Messaging\Conversation;
use App\Entity\Producer\ProducerProfile;
use App\Enum\ReplyStatus;
use App\Enum\RequestStatus;
use App\Enum\ReviewStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use App\Tests\GeographyTestHelperTrait;

/**
 * Teste ce que le client voit d'une demande (les réponses et devis des producteurs) et sa décision : accepter ou refuser
 * (cahier fonctionnel, dashboard client : "Réponses / devis" ; statuts de réponse : vue, acceptée, refusée).
 */
final class ClientReplyControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;
    use GeographyTestHelperTrait;

    private const PASSWORD = 'motdepasse123';

    private function login(User $user): string
    {
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $user->getEmail(),
            'password' => self::PASSWORD,
        ]));

        return json_decode($this->client->getResponse()->getContent(), true)['token'];
    }

    private function get(string $url, string $token): void
    {
        $this->client->request('GET', $url, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    private function post(string $url, string $token): void
    {
        $this->client->request('POST', $url, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true);
    }

    /**
     * @return array{0: User, 1: ClientRequest}
     */
    private function makeClientWithRequest(RequestStatus $status = RequestStatus::RepliesReceived, string $name = 'client'): array
    {
        $client = $this->makeUserWithPassword($name, self::PASSWORD);
        $request = $this->makeClientRequest($client, $this->makeProduct($this->makeCategory()), status: $status);

        return [$client, $request];
    }

    private function makeProducer(string $name = 'producer'): ProducerProfile
    {
        $owner = $this->makeUserWithPassword($name, self::PASSWORD);
        $owner->setRoles([User::ROLE_PRODUCER]);
        $producer = $this->makeProducerProfile($owner, $this->em->find(Country::class, 'FR') ?? $this->makeCountry(), farmName: 'Ferme '.$name);
        $producer->setCity('Lyon');

        return $producer;
    }

    private function makeReply(ClientRequest $request, ProducerProfile $producer, ReplyStatus $status = ReplyStatus::Sent, ?string $text = 'Oui disponible', ?string $price = '12.50'): ProducerReply
    {
        $reply = new ProducerReply();
        $reply->setRequest($request);
        $reply->setProducer($producer);
        $reply->setReplyText($text);
        $reply->setPriceAmount($price);
        $reply->setStatus($status);
        $this->em->persist($reply);

        return $reply;
    }

    private function makeConversation(ClientRequest $request, ProducerProfile $producer): Conversation
    {
        $conversation = new Conversation();
        $conversation->setRequest($request);
        $conversation->setProducer($producer);
        $conversation->setClient($request->getClient());
        $this->em->persist($conversation);

        return $conversation;
    }

    private function replyStatus(ProducerReply $reply): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT status FROM matching.producer_replies WHERE id = :id', ['id' => $reply->getId()->toRfc4122()]);
    }

    public function testTheClientSeesTheRepliesOfItsRequestWithTheProducerAndTheConversation(): void
    {
        [$client, $request] = $this->makeClientWithRequest();
        $producer = $this->makeProducer();
        $reply = $this->makeReply($request, $producer);
        $conversation = $this->makeConversation($request, $producer);
        $this->em->flush();
        $requestId = $request->getId()->toRfc4122();

        $this->get('/api/client/requests/'.$requestId.'/replies', $this->login($client));

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertSame($requestId, $data['request']['id']);
        self::assertSame('replies_received', $data['request']['status']);
        self::assertSame('Pommes', $data['request']['product']);
        self::assertCount(1, $data['replies']);
        $row = $data['replies'][0];
        self::assertSame($reply->getId()->toRfc4122(), $row['id']);
        self::assertSame('Ferme producer', $row['producer']['name']);
        self::assertSame('Lyon', $row['producer']['city']);
        self::assertTrue($row['producer']['verified']);
        self::assertSame(12.5, $row['priceAmount']);
        self::assertSame('Oui disponible', $row['message']);
        self::assertSame($conversation->getId()->toRfc4122(), $row['conversationId']);
        self::assertNull($row['review']);
        self::assertSame([], $row['attachments']);
    }

    // * cahier fonctionnel, statuts de réponse : "Vue -- le client a consulté la réponse"
    public function testOpeningTheListMarksTheRepliesAsSeenButShowsThemAsNewOnce(): void
    {
        [$client, $request] = $this->makeClientWithRequest();
        $reply = $this->makeReply($request, $this->makeProducer());
        $this->em->flush();
        $token = $this->login($client);
        $url = '/api/client/requests/'.$request->getId()->toRfc4122().'/replies';

        $this->get($url, $token);
        self::assertSame('sent', $this->json()['replies'][0]['status']);
        self::assertSame('seen', $this->replyStatus($reply));

        $this->get($url, $token);
        self::assertSame('seen', $this->json()['replies'][0]['status']);
    }

    public function testADraftAndAProducersPoliteRefusalAreNotProposals(): void
    {
        [$client, $request] = $this->makeClientWithRequest();
        $this->makeReply($request, $this->makeProducer('p1'), ReplyStatus::Draft);
        $this->makeReply($request, $this->makeProducer('p2'), ReplyStatus::Declined, text: null, price: null);
        $this->makeReply($request, $this->makeProducer('p3'));
        $this->em->flush();

        $this->get('/api/client/requests/'.$request->getId()->toRfc4122().'/replies', $this->login($client));

        $names = array_map(static fn (array $row) => $row['producer']['name'], $this->json()['replies']);
        self::assertSame(['Ferme p3'], $names);
    }

    public function testTheDistanceBetweenTheRequestAndTheProducerIsGivenWhenBothHaveAPosition(): void
    {
        [$client, $request] = $this->makeClientWithRequest();
        $near = $this->makeProducer('near');
        $unknown = $this->makeProducer('unknown');
        $this->makeReply($request, $near);
        $this->makeReply($request, $unknown);
        $this->em->flush();
        $this->setGeographyPoint('matching.client_requests', 'location', $request->getId()->toRfc4122(), 2.35, 48.85);
        $this->setGeographyPoint('producer.producer_profiles', 'location', $near->getId()->toRfc4122(), 2.35, 48.95);

        $this->get('/api/client/requests/'.$request->getId()->toRfc4122().'/replies', $this->login($client));

        $distances = [];
        foreach ($this->json()['replies'] as $row) {
            $distances[$row['producer']['name']] = $row['producer']['distanceKm'];
        }
        // * 0,1 degré de latitude = environ 11 km
        self::assertEqualsWithDelta(11.1, $distances['Ferme near'], 0.2);
        self::assertNull($distances['Ferme unknown']);
    }

    public function testTheReviewAlreadyLeftIsShownWithItsModerationStatus(): void
    {
        [$client, $request] = $this->makeClientWithRequest();
        $producer = $this->makeProducer();
        $this->makeReply($request, $producer);
        $this->makeReview($client, $producer, $request, 4, ReviewStatus::Pending);
        $this->em->flush();

        $this->get('/api/client/requests/'.$request->getId()->toRfc4122().'/replies', $this->login($client));

        self::assertSame(['rating' => 4, 'status' => 'pending'], $this->json()['replies'][0]['review']);
    }

    public function testTheRepliesOfSomeoneElsesRequestAreNotShown(): void
    {
        [, $request] = $this->makeClientWithRequest();
        $this->makeReply($request, $this->makeProducer());
        [$other] = $this->makeClientWithRequest(name: 'other');
        $this->em->flush();

        $this->get('/api/client/requests/'.$request->getId()->toRfc4122().'/replies', $this->login($other));

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheRepliesNeedAnAuthenticatedAccountAndAKnownRequest(): void
    {
        [$client] = $this->makeClientWithRequest();
        $this->em->flush();

        $this->client->request('GET', '/api/client/requests/00000000-0000-4000-8000-000000000000/replies');
        self::assertResponseStatusCodeSame(401);

        $this->get('/api/client/requests/00000000-0000-4000-8000-000000000000/replies', $this->login($client));
        self::assertResponseStatusCodeSame(404);
    }

    public function testAcceptingAQuoteTellsTheProducer(): void
    {
        [$client, $request] = $this->makeClientWithRequest();
        $producer = $this->makeProducer();
        $reply = $this->makeReply($request, $producer, ReplyStatus::Seen);
        $this->em->flush();

        $this->post('/api/client/replies/'.$reply->getId()->toRfc4122().'/accept', $this->login($client));

        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $reply->getId()->toRfc4122(), 'status' => 'accepted'], $this->json());
        self::assertSame('accepted', $this->replyStatus($reply));

        $notification = $this->em->getConnection()->fetchAssociative(
            "SELECT title, body, data FROM notification.notifications WHERE user_id = :id AND type = 'client_replied'",
            ['id' => $producer->getOwner()->getId()->toRfc4122()]
        );
        self::assertNotFalse($notification);
        self::assertSame('Réponse client', $notification['title']);
        self::assertSame('Un client souhaite poursuivre avec votre devis.', $notification['body']);
        $data = json_decode($notification['data'], true);
        self::assertSame($request->getId()->toRfc4122(), $data['requestId']);
        self::assertSame('accepted', $data['decision']);
    }

    public function testDecliningAQuoteTellsTheProducerToo(): void
    {
        [$client, $request] = $this->makeClientWithRequest();
        $producer = $this->makeProducer();
        $reply = $this->makeReply($request, $producer);
        $this->em->flush();

        $this->post('/api/client/replies/'.$reply->getId()->toRfc4122().'/decline', $this->login($client));

        self::assertResponseIsSuccessful();
        self::assertSame('declined', $this->replyStatus($reply));
        $body = $this->em->getConnection()->fetchOne(
            "SELECT body FROM notification.notifications WHERE user_id = :id AND type = 'client_replied'",
            ['id' => $producer->getOwner()->getId()->toRfc4122()]
        );
        self::assertSame("Un client n'a pas retenu votre devis.", $body);
    }

    public function testADecisionIsFinal(): void
    {
        [$client, $request] = $this->makeClientWithRequest();
        $accepted = $this->makeReply($request, $this->makeProducer('p1'), ReplyStatus::Accepted);
        $declined = $this->makeReply($request, $this->makeProducer('p2'), ReplyStatus::Declined);
        $this->em->flush();
        $token = $this->login($client);

        $this->post('/api/client/replies/'.$accepted->getId()->toRfc4122().'/decline', $token);
        self::assertResponseStatusCodeSame(409);
        $this->post('/api/client/replies/'.$declined->getId()->toRfc4122().'/accept', $token);
        self::assertResponseStatusCodeSame(409);

        self::assertSame('accepted', $this->replyStatus($accepted));
        self::assertSame('declined', $this->replyStatus($declined));
    }

    public function testAClosedRequestCannotReceiveADecision(): void
    {
        [$client, $request] = $this->makeClientWithRequest(RequestStatus::Cancelled);
        $reply = $this->makeReply($request, $this->makeProducer());
        $this->em->flush();

        $this->post('/api/client/replies/'.$reply->getId()->toRfc4122().'/accept', $this->login($client));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('sent', $this->replyStatus($reply));
    }

    public function testOnlyTheClientOfTheRequestCanDecideAndNotOnADraftOrAPoliteRefusal(): void
    {
        [$client, $request] = $this->makeClientWithRequest();
        $reply = $this->makeReply($request, $this->makeProducer('p1'));
        $draft = $this->makeReply($request, $this->makeProducer('p2'), ReplyStatus::Draft);
        $refusal = $this->makeReply($request, $this->makeProducer('p3'), ReplyStatus::Declined, text: null, price: null);
        [$other] = $this->makeClientWithRequest(name: 'other');
        $this->em->flush();

        $this->post('/api/client/replies/'.$reply->getId()->toRfc4122().'/accept', $this->login($other));
        self::assertResponseStatusCodeSame(404);

        $token = $this->login($client);
        $this->post('/api/client/replies/'.$draft->getId()->toRfc4122().'/accept', $token);
        self::assertResponseStatusCodeSame(404);
        $this->post('/api/client/replies/'.$refusal->getId()->toRfc4122().'/accept', $token);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('sent', $this->replyStatus($reply));
        self::assertSame('draft', $this->replyStatus($draft));
    }

    public function testDecidingNeedsAnAuthenticatedAccount(): void
    {
        $this->client->request('POST', '/api/client/replies/00000000-0000-4000-8000-000000000000/accept');

        self::assertResponseStatusCodeSame(401);
    }
}
