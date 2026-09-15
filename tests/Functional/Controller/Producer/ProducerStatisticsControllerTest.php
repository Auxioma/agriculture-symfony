<?php

namespace App\Tests\Functional\Controller\Producer;

use App\Entity\Identity\User;
use App\Entity\Matching\ProducerReply;
use App\Entity\Matching\RequestMatch;
use App\Entity\Messaging\Conversation;
use App\Enum\ReplyStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste GET /api/producer/statistics (cahier fonctionnel, dashboard producteur : "Vues profil,
 * demandes reçues, réponses, conversations, taux de réponse, ROI estimé").
 */

final class ProducerStatisticsControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    /**
     * @return array{0: string, 1: \App\Entity\Producer\ProducerProfile}
     */
    private function loginAsProducer(): array
    {
        $country = $this->makeCountry();
        $owner = $this->makeUserWithPassword('producer', 'motdepasse123');
        $owner->setRoles([User::ROLE_PRODUCER]);
        $producer = $this->makeProducerProfile($owner, $country);
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $owner->getEmail(),
            'password' => 'motdepasse123',
        ]));
        $token = json_decode($this->client->getResponse()->getContent(), true)['token'];

        return [$token, $producer];
    }

    public function testGetStatisticsComputesCountsResponseRateAndRoi(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);

        $subscription = $this->makeActiveSubscription($producer);
        $subscription->getPlanPrice()->setAmount('30.00');

        // * 3 demandes mises en relation avec ce producteur, dont 2 avec une réponse envoyée --
        // * taux de réponse attendu : round(100 * 2 / 3, 1) = 66.7.
        for ($i = 0; $i < 3; ++$i) {
            $request = $this->makeClientRequest($this->makeUser('client'.$i), $product);
            $match = new RequestMatch();
            $match->setRequest($request);
            $match->setProducer($producer);
            $this->em->persist($match);

            if ($i < 2) {
                $reply = new ProducerReply();
                $reply->setRequest($request);
                $reply->setProducer($producer);
                $reply->setStatus(ReplyStatus::Sent);
                $this->em->persist($reply);
            }

            if ($i === 0) {
                $conversation = new Conversation();
                $conversation->setRequest($request);
                $conversation->setClient($request->getClient());
                $conversation->setProducer($producer);
                $this->em->persist($conversation);
            }
        }
        $this->em->flush();

        $this->client->request('GET', '/api/producer/statistics', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame(3, $data['requestsReceived']);
        self::assertSame(2, $data['replies']);
        self::assertSame(1, $data['conversations']);
        self::assertEqualsWithDelta(66.7, $data['responseRate'], 0.01);
        self::assertEqualsWithDelta(10.0, $data['estimatedRoi']['costPerRequestReceived'], 0.01);
        self::assertEqualsWithDelta(30.0, $data['estimatedRoi']['costPerConversationOpened'], 0.01);
    }

    public function testGetStatisticsWithoutActiveSubscriptionReturnsNullRoi(): void
    {
        [$token] = $this->loginAsProducer();

        $this->client->request('GET', '/api/producer/statistics', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame(0, $data['requestsReceived']);
        self::assertNull($data['responseRate']);
        self::assertNull($data['estimatedRoi']['subscriptionCost']);
        self::assertNull($data['estimatedRoi']['costPerRequestReceived']);
    }

    public function testGetStatisticsCountsProfileViewsFromPublicRoute(): void
    {
        [$token, $producer] = $this->loginAsProducer();

        $this->client->request('GET', '/api/producers/'.$producer->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/producers/'.$producer->getId()->toRfc4122());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/producer/statistics', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(2, $data['profileViews']);
    }

    public function testGetStatisticsRejectsNonProducer(): void
    {
        $token = $this->registerClientAndLogin('client');

        $this->client->request('GET', '/api/producer/statistics', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(404);
    }
}
