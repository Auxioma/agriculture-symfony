<?php

namespace App\Tests\Functional\Controller\Producer;

use App\Entity\Identity\User;
use App\Entity\Matching\ProducerReply;
use App\Entity\Matching\ReplyAttachment;
use App\Entity\Matching\RequestAttachment;
use App\Entity\Matching\RequestMatch;
use App\Entity\Messaging\Conversation;
use App\Entity\Messaging\Message;
use App\Entity\Producer\ProducerProfile;
use App\Enum\MatchStatus;
use App\Enum\NeedType;
use App\Enum\ReplyStatus;
use App\Enum\RequestStatus;
use App\Enum\SubscriptionStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use Symfony\Component\Uid\Uuid;

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

    // * $sentAgo : date d'envoi (createdAt n'a pas de setter, on l'antidate par réflexion)
    private function reply(RequestMatch $match, ReplyStatus $status, ?string $price = null, string $sentAgo = 'now'): ProducerReply
    {
        $reply = new ProducerReply();
        $reply->setRequest($match->getRequest());
        $reply->setProducer($match->getProducer());
        $reply->setStatus($status);
        $reply->setPriceAmount($price);
        (new \ReflectionProperty($reply, 'createdAt'))->setValue($reply, new \DateTimeImmutable($sentAgo));
        $this->em->persist($reply);

        return $reply;
    }

    public function testQuotesListsAllRepliesOfTheProducerNewestFirstExceptPoliteRefusals(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $old = $this->makeMatch($producer, urgency: 0);
        $this->reply($old, ReplyStatus::Accepted, '8', '-3 days');
        $recent = $this->makeMatch($producer, urgency: 0);
        $this->reply($recent, ReplyStatus::Sent, '12.5', '-1 day');
        // * un brouillon et une réponse sans prix (message seul) y sont, avec un prix nul
        $draft = $this->makeMatch($producer, urgency: 0);
        $this->reply($draft, ReplyStatus::Draft, null, '-2 days');
        $messageOnly = $this->makeMatch($producer, urgency: 0);
        $this->reply($messageOnly, ReplyStatus::Sent, null, '-4 days')->setReplyText('Je peux vous en fournir.');
        // * le refus poli (refusée, sans prix ni message) n'y est pas ; un refus du client sur un devis chiffré, si
        $this->reply($this->makeMatch($producer, urgency: 0), ReplyStatus::Declined, null, '-5 days');
        $refused = $this->makeMatch($producer, urgency: 0);
        $this->reply($refused, ReplyStatus::Declined, '3', '-6 days');
        // * la réponse d'un autre producteur n'apparaît pas
        $other = $this->makeProducerProfile($this->makeUser('other-producer'), $producer->getCountry());
        $this->reply($this->makeMatch($other, urgency: 0), ReplyStatus::Sent, '5');
        $this->em->flush();

        $this->client->request('GET', '/api/producer/quotes', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame(['sent', 'draft', 'accepted', 'sent', 'declined'], array_column($data, 'status'));
        self::assertSame(
            array_map(static fn (RequestMatch $m) => $m->getRequest()->getId()->toRfc4122(), [$recent, $draft, $old, $messageOnly, $refused]),
            array_column($data, 'requestId')
        );
        self::assertEquals(12.5, $data[0]['priceAmount']);
        self::assertNull($data[1]['priceAmount']);
        self::assertNull($data[3]['priceAmount']);
        self::assertSame('Client', $data[0]['clientName']);
        self::assertArrayHasKey('sentAt', $data[0]);
    }

    public function testQuoteDetailExposesTheWholeReplyButHidesDraftsRefusalsAndOtherProducers(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $match = $this->makeMatch($producer, urgency: 0);
        $match->getRequest()->setQuantity('20');
        $quote = $this->reply($match, ReplyStatus::Seen, '12', '-2 days');
        $quote->setAvailableQuantity('20');
        $quote->setAvailabilityDate(new \DateTimeImmutable('2026-08-23'));
        $quote->setValidUntil(new \DateTimeImmutable('+5 days'));
        $quote->setPickupConditions('À la ferme');
        $quote->setDeliveryConditions('Possible < 15 km');
        $quote->setReplyText('Parfait, je peux vous proposer ce produit.');
        $attachment = new ReplyAttachment();
        $attachment->setFileName('devis-tomates.pdf');
        $quote->addAttachment($attachment);
        $this->em->persist($attachment);
        // * 404 : un brouillon (il se rouvre depuis la page de réponse), un refus poli, le devis d'un autre producteur
        $draft = $this->reply($this->makeMatch($producer, urgency: 0), ReplyStatus::Draft, '9');
        $refusal = $this->reply($this->makeMatch($producer, urgency: 0), ReplyStatus::Declined);
        $noPrice = $this->reply($this->makeMatch($producer, urgency: 0), ReplyStatus::Sent);
        $noPrice->setReplyText('Je peux vous en fournir.');
        $other = $this->makeProducerProfile($this->makeUser('other-producer'), $producer->getCountry());
        $otherQuote = $this->reply($this->makeMatch($other, urgency: 0), ReplyStatus::Sent, '5');
        $this->em->flush();
        $get = function (string $id) use ($token): int {
            $this->client->request('GET', '/api/producer/quotes/'.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

            return $this->client->getResponse()->getStatusCode();
        };

        self::assertSame(200, $get($quote->getId()->toRfc4122()));
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('seen', $data['status']);
        self::assertSame('individual', $data['clientType']);
        self::assertEquals(12, $data['priceAmount']);
        self::assertEquals(20, $data['availableQuantity']);
        self::assertStringStartsWith('2026-08-23', $data['availabilityDate']);
        self::assertSame('À la ferme', $data['pickupConditions']);
        self::assertSame('Possible < 15 km', $data['deliveryConditions']);
        self::assertSame('Parfait, je peux vous proposer ce produit.', $data['replyText']);
        self::assertCount(1, $data['attachments']);
        self::assertSame('devis-tomates.pdf', $data['attachments'][0]['fileName']);
        self::assertNull($data['attachments'][0]['fileUrl']);

        // * une réponse sans prix a sa fiche, avec un prix nul
        self::assertSame(200, $get($noPrice->getId()->toRfc4122()));
        self::assertNull(json_decode($this->client->getResponse()->getContent(), true)['priceAmount']);

        self::assertSame(404, $get($draft->getId()->toRfc4122()));
        self::assertSame(404, $get($refusal->getId()->toRfc4122()));
        self::assertSame(404, $get($otherQuote->getId()->toRfc4122()));
        self::assertSame(404, $get(Uuid::v4()->toRfc4122()));
        self::assertSame(404, $get('pas-un-identifiant'));
    }

    public function testProducerReportsAMatchedRequestOnlyOnce(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $request = $this->makeMatch($producer, urgency: 0)->getRequest();
        $notMatched = $this->makeClientRequest($this->makeUser('stranger'), null);
        $this->em->flush();
        $id = $request->getId()->toRfc4122();
        $post = function (string $requestId, array $body) use ($token): int {
            $this->client->request('POST', '/api/producer/requests/'.$requestId.'/report', server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ], content: json_encode($body));

            return $this->client->getResponse()->getStatusCode();
        };

        self::assertSame(201, $post($id, ['reason' => 'circumvention', 'message' => 'Il veut payer hors plateforme']));
        $row = $this->em->getConnection()->fetchAssociative('SELECT target_type, reason, message, status FROM trust.reports WHERE target_id = :id', ['id' => $id]);
        self::assertSame(['target_type' => 'client_request', 'reason' => 'circumvention', 'message' => 'Il veut payer hors plateforme', 'status' => 'open'], $row);
        self::assertSame('reported', $this->em->getConnection()->fetchOne('SELECT status FROM matching.client_requests WHERE id = :id', ['id' => $id]));

        self::assertSame(409, $post($id, ['reason' => 'fraud']));
        self::assertSame(422, $post($id, ['reason' => 'pas-un-motif']));
        self::assertSame(403, $post($notMatched->getId()->toRfc4122(), ['reason' => 'fraud']));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM trust.reports'));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postJson(string $token, string $url, array $body): int
    {
        $this->client->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode($body));

        return $this->client->getResponse()->getStatusCode();
    }

    public function testReportingALiveRequestMakesItReportedKeepsItsPreviousStatusAndTakesItOutOfTheLists(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $request = $this->makeMatch($producer, urgency: 0, requestStatus: RequestStatus::ConversationOpen)->getRequest();
        $this->em->flush();
        $id = $request->getId()->toRfc4122();
        $producerId = $producer->getOwner()->getId()->toRfc4122();

        self::assertSame(201, $this->postJson($token, '/api/producer/requests/'.$id.'/report', ['reason' => 'fraud']));

        $connection = $this->em->getConnection();
        self::assertSame('reported', $connection->fetchOne('SELECT status FROM matching.client_requests WHERE id = :id', ['id' => $id]));

        $event = $connection->fetchAssociative("SELECT actor_id, payload FROM matching.request_events WHERE request_id = :id AND event_type = 'reported'", ['id' => $id]);
        self::assertNotFalse($event);
        self::assertSame($producerId, $event['actor_id']);
        self::assertSame('conversation_open', json_decode($event['payload'], true)['previousStatus']);

        $audit = $connection->fetchAssociative("SELECT old_data, new_data FROM audit.audit_logs WHERE action = 'client_request_reported' AND record_id = :id", ['id' => $id]);
        self::assertNotFalse($audit);
        self::assertStringContainsString('conversation_open', $audit['old_data']);
        self::assertStringContainsString('reported', $audit['new_data']);

        $this->client->request('GET', '/api/producer/requests/available', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $available = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame([], $available);

        $this->client->request('GET', '/api/producer/requests/'.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $detail = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('closed', $detail['status']);
        self::assertTrue($detail['underReview']);
    }

    public function testReportingAFinishedOrAlreadyReportedRequestKeepsItsStatus(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $expired = $this->makeMatch($producer, urgency: 0, requestStatus: RequestStatus::Expired)->getRequest();
        $reported = $this->makeMatch($producer, urgency: 0, requestStatus: RequestStatus::Reported)->getRequest();
        $this->em->flush();

        foreach ([[$expired, 'expired'], [$reported, 'reported']] as [$request, $status]) {
            $id = $request->getId()->toRfc4122();
            self::assertSame(201, $this->postJson($token, '/api/producer/requests/'.$id.'/report', ['reason' => 'other']));
            self::assertSame($status, $this->em->getConnection()->fetchOne('SELECT status FROM matching.client_requests WHERE id = :id', ['id' => $id]));
        }

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT count(*) FROM matching.request_events WHERE event_type = 'reported'"));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM trust.reports'));
    }

    public function testRepliesAndDeclinesAreRefusedOnceTheRequestIsNoLongerOpen(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $reported = $this->makeMatch($producer, urgency: 0, requestStatus: RequestStatus::Reported)->getRequest();
        $cancelled = $this->makeMatch($producer, urgency: 0, requestStatus: RequestStatus::Cancelled)->getRequest();
        $this->em->flush();

        foreach ([$reported, $cancelled] as $request) {
            $url = '/api/producer/requests/'.$request->getId()->toRfc4122();
            self::assertSame(409, $this->postJson($token, $url.'/reply', ['replyText' => 'Bonjour']));
            self::assertSame(409, $this->postJson($token, $url.'/reply', ['draft' => true, 'replyText' => 'Bonjour']));
            self::assertSame(409, $this->postJson($token, $url.'/decline', []));
        }

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM matching.producer_replies'));
    }

    public function testArchivingARequestPutsItAsideForThisProducerOnly(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $match = $this->makeMatch($producer, urgency: 0);
        $treated = $this->makeMatch($producer, urgency: 0);
        $this->reply($treated, ReplyStatus::Sent, '5');
        $notMatched = $this->makeClientRequest($this->makeUser('stranger'), null);
        $this->em->flush();
        $id = $match->getRequest()->getId()->toRfc4122();
        $url = '/api/producer/requests/'.$id;

        self::assertSame(200, $this->postJson($token, $url.'/archive', []));

        // * seule la correspondance du producteur est mise de côté, la demande du client reste comme elle est
        $connection = $this->em->getConnection();
        self::assertSame('ignored', $connection->fetchOne('SELECT status FROM matching.request_matches WHERE request_id = :id', ['id' => $id]));
        self::assertSame('sent', $connection->fetchOne('SELECT status FROM matching.client_requests WHERE id = :id', ['id' => $id]));

        $get = function (string $path) use ($token): array {
            $this->client->request('GET', '/api/producer/requests/'.$path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

            return json_decode($this->client->getResponse()->getContent(), true);
        };
        self::assertNotContains($id, array_column($get('available'), 'requestId'));
        self::assertSame('archived', $get($id)['status']);
        $received = array_column($get('received'), 'status', 'requestId');
        self::assertSame('archived', $received[$id]);

        // * ni réponse ni refus sur une demande archivée, et on ne l'archive pas deux fois
        self::assertSame(409, $this->postJson($token, $url.'/reply', ['replyText' => 'Bonjour']));
        self::assertSame(409, $this->postJson($token, $url.'/decline', []));
        self::assertSame(409, $this->postJson($token, $url.'/archive', []));
        // * une demande déjà traitée ne s'archive pas ; une demande qui n'est pas la sienne, non plus
        self::assertSame(409, $this->postJson($token, '/api/producer/requests/'.$treated->getRequest()->getId()->toRfc4122().'/archive', []));
        self::assertSame(403, $this->postJson($token, '/api/producer/requests/'.$notMatched->getId()->toRfc4122().'/archive', []));
    }

    public function testArchivingAQuoteKeepsItInTheListAsArchived(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $accepted = $this->reply($this->makeMatch($producer, urgency: 0), ReplyStatus::Accepted, '8');
        $sent = $this->reply($this->makeMatch($producer, urgency: 0), ReplyStatus::Sent);
        $sent->setReplyText('Je peux vous en fournir.');
        $draft = $this->reply($this->makeMatch($producer, urgency: 0), ReplyStatus::Draft);
        $refusal = $this->reply($this->makeMatch($producer, urgency: 0), ReplyStatus::Declined);
        $other = $this->makeProducerProfile($this->makeUser('other-producer'), $producer->getCountry());
        $otherQuote = $this->reply($this->makeMatch($other, urgency: 0), ReplyStatus::Sent, '5');
        $this->em->flush();

        foreach ([$accepted, $sent] as $quote) {
            self::assertSame(200, $this->postJson($token, '/api/producer/quotes/'.$quote->getId()->toRfc4122().'/archive', []));
            self::assertSame('archived', json_decode($this->client->getResponse()->getContent(), true)['status']);
        }
        // * archiver deux fois ne change rien
        self::assertSame(200, $this->postJson($token, '/api/producer/quotes/'.$accepted->getId()->toRfc4122().'/archive', []));

        self::assertSame(2, (int) $this->em->getConnection()->fetchOne("SELECT count(*) FROM matching.producer_replies WHERE status = 'archived'"));
        $this->client->request('GET', '/api/producer/quotes', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $statuses = array_column(json_decode($this->client->getResponse()->getContent(), true), 'status');
        self::assertEqualsCanonicalizing(['archived', 'archived', 'draft'], $statuses);

        // * un brouillon, un refus poli et la réponse d'un autre producteur : introuvables
        foreach ([$draft, $refusal, $otherQuote] as $notMine) {
            self::assertSame(404, $this->postJson($token, '/api/producer/quotes/'.$notMine->getId()->toRfc4122().'/archive', []));
        }
        self::assertSame(404, $this->postJson($token, '/api/producer/quotes/pas-un-identifiant/archive', []));
    }

    public function testReportRejectsAccountWithoutProducerProfile(): void
    {
        $client = $this->makeUserWithPassword('client', 'motdepasse123');
        $this->em->flush();

        $this->client->request('POST', '/api/producer/requests/'.Uuid::v4()->toRfc4122().'/report', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->login($client->getEmail()),
        ], content: json_encode(['reason' => 'fraud']));

        self::assertResponseStatusCodeSame(403);
    }

    public function testQuotesRejectsAccountWithoutProducerProfile(): void
    {
        $client = $this->makeUserWithPassword('client', 'motdepasse123');
        $this->em->flush();

        $this->client->request('GET', '/api/producer/quotes', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->login($client->getEmail())]);

        self::assertResponseStatusCodeSame(403);
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

    // * un paiement en double est gardé "cancelled", enregistré après l'abonnement qu'on garde : le résumé montre le bon
    public function testDashboardSummaryShowsTheSubscriptionInForceNotANewerCancelledDuplicate(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $kept = $this->makeActiveSubscription($producer);
        $kept->getPlanPrice()->getPlan()->setName('Standard');
        $duplicate = $this->makeActiveSubscription($producer, status: SubscriptionStatus::Cancelled);
        $duplicate->getPlanPrice()->getPlan()->setName('Premium');
        $this->em->flush();
        $this->makeSubscriptionNewest($duplicate);

        $summary = $this->getDashboard($token)['subscription'];

        self::assertSame('Standard', $summary['planName']);
        self::assertSame('active', $summary['status']);
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
