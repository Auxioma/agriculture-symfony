<?php

namespace App\Tests\Functional\Controller\Producer;

use App\Entity\Identity\User;
use App\Entity\Matching\ProducerReply;
use App\Entity\Matching\RequestAttachment;
use App\Entity\Matching\RequestMatch;
use App\Entity\Messaging\Conversation;
use App\Entity\Messaging\Message;
use App\Entity\Producer\ProducerProfile;
use App\Enum\MatchStatus;
use App\Enum\NeedType;
use App\Enum\ReplyStatus;
use App\Enum\RequestStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste GET /api/producer/dashboard (cahier fonctionnel, dashboard producteur : "Demandes disponibles,
 * demandes urgentes, messages non lus, abonnement, quota, profil complété") et les listes de demandes qui
 * partagent ses règles : disponibles (/available), reçues (/received) et le détail d'une demande.
 */
final class ProducerDashboardControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    /**
     * @return array{0: string, 1: ProducerProfile}
     */
    private function loginAsProducer(): array
    {
        $country = $this->makeCountry();
        $owner = $this->makeUserWithPassword('producer', 'motdepasse123');
        $owner->setRoles([User::ROLE_PRODUCER]);
        $producer = $this->makeProducerProfile($owner, $country, farmName: 'Ferme Dupont');
        $this->em->flush();

        return [$this->login($owner->getEmail()), $producer];
    }

    private function login(string $email): string
    {
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'password' => 'motdepasse123',
        ]));

        return json_decode($this->client->getResponse()->getContent(), true)['token'];
    }

    /**
     * @return array<string, mixed>
     */
    private function getDashboard(string $token): array
    {
        $this->client->request('GET', '/api/producer/dashboard', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function makeMatch(ProducerProfile $producer, int $urgency, RequestStatus $requestStatus = RequestStatus::Sent, MatchStatus $matchStatus = MatchStatus::Proposed, string $client = 'client'): RequestMatch
    {
        $request = $this->makeClientRequest($this->makeUser($client.bin2hex(random_bytes(3))), null, status: $requestStatus);
        $request->setUrgencyLevel($urgency);
        $request->setCity('Lyon');

        $match = new RequestMatch();
        $match->setRequest($request);
        $match->setProducer($producer);
        $match->setStatus($matchStatus);
        $match->setDistanceKm('4.00');
        $this->em->persist($match);

        return $match;
    }

    public function testDashboardCountsOnlyOpenAvailableRequestsAndListsThemUrgentOnesFirst(): void
    {
        [$token, $producer] = $this->loginAsProducer();

        $this->makeMatch($producer, urgency: 1);
        $this->makeMatch($producer, urgency: 2);
        $this->makeMatch($producer, urgency: 0);
        // * Exclues : demande annulée, correspondance ignorée par le producteur.
        $this->makeMatch($producer, urgency: 2, requestStatus: RequestStatus::Cancelled);
        $this->makeMatch($producer, urgency: 2, matchStatus: MatchStatus::Ignored);
        $this->em->flush();

        $data = $this->getDashboard($token);

        self::assertSame('Ferme Dupont', $data['farmName']);
        self::assertSame(3, $data['availableRequests']);
        self::assertSame(2, $data['urgentRequests']);
        // * La liste contient toutes les demandes disponibles (3), les urgentes en premier.
        self::assertCount(3, $data['requests']);
        self::assertSame([true, true, false], array_column($data['requests'], 'urgent'));
        self::assertSame('Lyon', $data['requests'][0]['city']);
        self::assertEquals(4, $data['requests'][0]['distanceKm']);

        // * La liste dédiée applique la même définition de "disponible".
        $this->client->request('GET', '/api/producer/requests/available', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $list = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(3, $list);
        self::assertArrayHasKey('highVolume', $list[0]);
    }

    public function testRequestsExposeClientTypeNewAndHighVolumeFlags(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $big = $this->makeMatch($producer, urgency: 0)->getRequest();
        $big->setNeedType(NeedType::Professional);
        $big->setQuantity('60');
        $plain = $this->makeMatch($producer, urgency: 0)->getRequest();
        $plain->setQuantity('5');
        $this->em->flush();

        $items = array_column($this->getDashboard($token)['requests'], null, 'requestId');

        self::assertSame('professional', $items[$big->getId()->toRfc4122()]['clientType']);
        self::assertTrue($items[$big->getId()->toRfc4122()]['highVolume']);
        self::assertSame('individual', $items[$plain->getId()->toRfc4122()]['clientType']);
        self::assertFalse($items[$plain->getId()->toRfc4122()]['highVolume']);
        // * Correspondance qui vient d'être créée = nouvelle.
        self::assertTrue($items[$plain->getId()->toRfc4122()]['isNew']);
    }

    private function reply(RequestMatch $match, ReplyStatus $status): void
    {
        $reply = new ProducerReply();
        $reply->setRequest($match->getRequest());
        $reply->setProducer($match->getProducer());
        $reply->setStatus($status);
        $this->em->persist($reply);
    }

    public function testReceivedRequestsTellNewTreatedAndClosedApart(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $new = $this->makeMatch($producer, urgency: 0);
        $new->getRequest()->getClient()->setFirstName('Camille')->setLastName('Roux');
        $draft = $this->makeMatch($producer, urgency: 0);
        $this->reply($draft, ReplyStatus::Draft);
        $sent = $this->makeMatch($producer, urgency: 0);
        $this->reply($sent, ReplyStatus::Sent);
        $declined = $this->makeMatch($producer, urgency: 0);
        $this->reply($declined, ReplyStatus::Declined);
        $closed = $this->makeMatch($producer, urgency: 0, requestStatus: RequestStatus::Cancelled);
        $this->em->flush();

        $this->client->request('GET', '/api/producer/requests/received', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        $items = array_column(json_decode($this->client->getResponse()->getContent(), true), null, 'requestId');
        $item = static fn (RequestMatch $m) => $items[$m->getRequest()->getId()->toRfc4122()];

        self::assertSame('new', $item($new)['status']);
        self::assertSame('Camille R.', $item($new)['clientName']);
        self::assertSame('Client', $item($draft)['clientName']);
        // * Un brouillon n'est pas une réponse : la demande reste à traiter.
        self::assertSame('new', $item($draft)['status']);
        self::assertSame('treated', $item($sent)['status']);
        self::assertNotNull($item($sent)['respondedAt']);
        self::assertFalse($item($sent)['declined']);
        self::assertTrue($item($declined)['declined']);
        self::assertSame('closed', $item($closed)['status']);
        // * Les demandes traitées ne sont plus "disponibles" : seules la nouvelle et le brouillon le restent.
        self::assertSame(2, $this->getDashboard($token)['availableRequests']);
    }

    public function testRequestDetailExposesFieldsAttachmentsAndStatus(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $match = $this->makeMatch($producer, urgency: 2);
        $request = $match->getRequest();
        $request->setDesiredDate(new \DateTimeImmutable('2026-08-23'));
        $request->setCountry($producer->getCountry());
        $request->setPostalCode('69003');
        $request->setPickupWanted(true);
        $attachment = new RequestAttachment();
        $attachment->setRequest($request);
        $attachment->setFileName('photo-parcelle.jpg');
        $attachment->setFileUrl('https://files.test/photo-parcelle.jpg');
        $this->em->persist($attachment);
        $request->getAttachments()->add($attachment);
        $this->em->flush();
        $url = '/api/producer/requests/'.$request->getId()->toRfc4122();
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.$token];

        $this->client->request('GET', $url, server: $auth);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame('Client', $data['clientName']);
        self::assertTrue($data['urgent']);
        self::assertSame('69', $data['department']);
        self::assertStringStartsWith('2026-08-23', $data['desiredDate']);
        self::assertTrue($data['pickupWanted']);
        self::assertFalse($data['deliveryWanted']);
        self::assertSame([['fileName' => 'photo-parcelle.jpg', 'fileUrl' => 'https://files.test/photo-parcelle.jpg']], $data['attachments']);
        self::assertSame('new', $data['status']);
        self::assertNull($data['respondedAt']);

        // * Une fois refusée, la demande est traitée (et le refus se voit). Le client HTTP a vidé l'EntityManager :
        // * on recharge la correspondance avant d'y rattacher le refus.
        $this->reply($this->em->find(RequestMatch::class, $match->getId()), ReplyStatus::Declined);
        $this->em->flush();
        $this->client->request('GET', $url, server: $auth);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame('treated', $data['status']);
        self::assertTrue($data['declined']);
        self::assertNotNull($data['respondedAt']);
    }

    public function testRequestDetailReturnsTheCurrentDraftAndKeepsTheRequestNew(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $request = $this->makeMatch($producer, urgency: 0)->getRequest();
        $this->em->flush();
        $url = '/api/producer/requests/'.$request->getId()->toRfc4122();
        $auth = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token];

        $this->client->request('GET', $url, server: $auth);
        self::assertNull(json_decode($this->client->getResponse()->getContent(), true)['draft']);

        $this->client->request('POST', $url.'/reply', server: $auth, content: json_encode([
            'draft' => true,
            'priceAmount' => '12.5',
            'availableQuantity' => '20',
            'pickupConditions' => 'Retrait à la ferme',
        ]));
        $this->client->request('GET', $url, server: $auth);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame(12.5, $data['draft']['priceAmount']);
        self::assertEquals(20, $data['draft']['availableQuantity']);
        self::assertSame('Retrait à la ferme', $data['draft']['pickupConditions']);
        // * un brouillon n'est pas une réponse : la demande reste à traiter
        self::assertSame('new', $data['status']);
    }

    public function testRequestDetailOfACancelledRequestIsClosed(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $request = $this->makeMatch($producer, urgency: 0, requestStatus: RequestStatus::Cancelled)->getRequest();
        $this->em->flush();

        $this->client->request('GET', '/api/producer/requests/'.$request->getId()->toRfc4122(), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertSame('closed', json_decode($this->client->getResponse()->getContent(), true)['status']);
    }

    public function testReceivedRequestsRejectsAccountWithoutProducerProfile(): void
    {
        $client = $this->makeUserWithPassword('client', 'motdepasse123');
        $this->em->flush();
        $token = $this->login($client->getEmail());

        $this->client->request('GET', '/api/producer/requests/received', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDashboardCountsUnreadMessagesSentByClientsOnly(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $match = $this->makeMatch($producer, urgency: 0);
        $client = $match->getRequest()->getClient();

        $conversation = new Conversation();
        $conversation->setRequest($match->getRequest());
        $conversation->setClient($client);
        $conversation->setProducer($producer);
        $this->em->persist($conversation);

        foreach ([$client, $client, $producer->getOwner()] as $sender) {
            $message = new Message();
            $message->setConversation($conversation);
            $message->setSender($sender);
            $message->setContent('Bonjour');
            $this->em->persist($message);
        }
        $this->em->flush();

        self::assertSame(2, $this->getDashboard($token)['unreadMessages']);
    }

    public function testDashboardSummarizesSubscriptionWithMonthlyQuota(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $subscription = $this->makeActiveSubscription($producer);
        $subscription->getPlanPrice()->getPlan()->setName('Standard');
        $subscription->getPlanPrice()->getPlan()->setLimits(['requests_per_month' => 30]);
        $this->makeMatch($producer, urgency: 0);
        $this->makeMatch($producer, urgency: 0);
        $this->em->flush();

        $summary = $this->getDashboard($token)['subscription'];

        self::assertSame('Standard', $summary['planName']);
        self::assertSame('active', $summary['status']);
        self::assertSame(2, $summary['requestsThisMonth']);
        self::assertSame(30, $summary['requestsQuota']);
    }

    public function testDashboardHasNoSubscriptionAndAnEmptyProfileForANewProducer(): void
    {
        [$token] = $this->loginAsProducer();

        $data = $this->getDashboard($token);

        self::assertNull($data['subscription']);
        self::assertSame(0, $data['profile']['completion']);
        self::assertSame(['description', 'photos', 'labels', 'products', 'availability', 'zones'], $data['profile']['missing']);
    }

    public function testDashboardProfileCompletionFollowsFilledCriteria(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $producer->setDescription('Ferme familiale en polyculture.');
        $this->makeProducerProduct($producer, $this->makeProduct($this->makeCategory()));
        $this->em->flush();

        $profile = $this->getDashboard($token)['profile'];

        self::assertSame(33, $profile['completion']);
        self::assertSame(['photos', 'labels', 'availability', 'zones'], $profile['missing']);
    }

    public function testDashboardIsForbiddenForAccountsWithoutProducerProfile(): void
    {
        $client = $this->makeUserWithPassword('client', 'motdepasse123');
        $this->em->flush();
        $token = $this->login($client->getEmail());

        $this->client->request('GET', '/api/producer/dashboard', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(403);
    }
}
