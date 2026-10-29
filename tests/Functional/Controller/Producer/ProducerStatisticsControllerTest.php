<?php

namespace App\Tests\Functional\Controller\Producer;

use App\Entity\Identity\User;
use App\Entity\Matching\ProducerReply;
use App\Entity\Matching\RequestMatch;
use App\Entity\Messaging\Conversation;
use App\Enum\ReplyStatus;
use App\Enum\SubscriptionStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use PHPUnit\Framework\Attributes\DataProvider;

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

        $subscription = $this->makeActiveSubscription($producer, features: ['stats' => 'basic']);
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

    // * Pas d'abonnement actif (essai) : pas de prix, donc pas de coût par demande.
    public function testGetStatisticsDuringTrialHasNoRoiYet(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $this->makeActiveSubscription($producer, SubscriptionStatus::Trialing, features: ['stats' => 'basic']);
        $this->em->flush();

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
        $this->makeActiveSubscription($producer, features: ['stats' => 'basic']);
        $this->em->flush();

        $this->client->request('GET', '/api/producers/'.$producer->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/producers/'.$producer->getId()->toRfc4122());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/producer/statistics', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(2, $data['profileViews']);
    }

    // * Cahier fonctionnel, abonnements : "statistiques de base" pour Pro, "statistiques avancées" pour Premium.
    #[DataProvider('statisticsLevels')]
    public function testGetStatisticsIsOpenToBothStatisticsLevels(string $level): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $this->makeActiveSubscription($producer, features: ['stats' => $level]);
        $this->em->flush();

        $this->client->request('GET', '/api/producer/statistics', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function statisticsLevels(): array
    {
        return ['basic' => ['basic'], 'advanced' => ['advanced']];
    }

    public function testGetStatisticsIsClosedWithoutASubscription(): void
    {
        [$token] = $this->loginAsProducer();

        $this->client->request('GET', '/api/producer/statistics', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testGetStatisticsIsClosedWhenThePlanDoesNotIncludeThem(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $this->makeActiveSubscription($producer, features: ['reply_to_requests' => true, 'chat' => true]);
        $this->em->flush();

        $this->client->request('GET', '/api/producer/statistics', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testGetStatisticsIsClosedWhenTheStatisticsFeatureIsOff(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $this->makeActiveSubscription($producer, features: ['stats' => false]);
        $this->em->flush();

        $this->client->request('GET', '/api/producer/statistics', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testGetStatisticsIsClosedOnceTheSubscriptionHasEnded(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $this->makeActiveSubscription($producer, periodStart: new \DateTimeImmutable('-40 days'), periodEnd: new \DateTimeImmutable('-10 days'), features: ['stats' => 'basic']);
        $this->makeActiveSubscription($producer, SubscriptionStatus::Cancelled, features: ['stats' => 'basic']);
        $this->em->flush();

        $this->client->request('GET', '/api/producer/statistics', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(403);
    }

    // * Le graphique : ce mois-ci et les 5 précédents, du plus ancien au plus récent, un mois sans demande compte 0.
    public function testGetStatisticsCountsRequestsReceivedMonthByMonth(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $this->makeActiveSubscription($producer, features: ['stats' => 'basic']);
        $product = $this->makeProduct($this->makeCategory());
        $other = $this->makeProducerProfile($this->makeUser('other'), $this->makeCountry('BE', 'Belgique'));
        $thisMonth = new \DateTimeImmutable('first day of this month 12:00:00', new \DateTimeZone('UTC'));
        $at = static fn (int $monthsAgo): string => $thisMonth->modify("-$monthsAgo months")->format('Y-m-d H:i:sP');

        // * Dans la fenêtre : 2 ce mois-ci, 1 il y a 2 mois, 1 il y a 5 mois (le plus ancien). Dehors : 1 il y a 6 mois,
        // * et 1 demande d'un autre producteur.
        $matches = [[$producer, 0], [$producer, 0], [$producer, 2], [$producer, 5], [$producer, 6], [$other, 0]];
        $ids = [];
        foreach ($matches as $i => [$owner, $monthsAgo]) {
            $match = new RequestMatch();
            $match->setRequest($this->makeClientRequest($this->makeUser('client'.$i), $product));
            $match->setProducer($owner);
            $this->em->persist($match);
            $ids[] = [$match, $monthsAgo];
        }
        $this->em->flush();
        foreach ($ids as [$match, $monthsAgo]) {
            $this->em->getConnection()->executeStatement(
                'UPDATE matching.request_matches SET created_at = :at WHERE id = :id',
                ['at' => $at($monthsAgo), 'id' => $match->getId()->toRfc4122()]
            );
        }

        $this->client->request('GET', '/api/producer/statistics', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame(
            [
                ['month' => $thisMonth->modify('-5 months')->format('Y-m'), 'count' => 1],
                ['month' => $thisMonth->modify('-4 months')->format('Y-m'), 'count' => 0],
                ['month' => $thisMonth->modify('-3 months')->format('Y-m'), 'count' => 0],
                ['month' => $thisMonth->modify('-2 months')->format('Y-m'), 'count' => 1],
                ['month' => $thisMonth->modify('-1 month')->format('Y-m'), 'count' => 0],
                ['month' => $thisMonth->format('Y-m'), 'count' => 2],
            ],
            $data['requestsByMonth']
        );
        // * Le total, lui, n'a pas de fenêtre : il compte aussi la demande d'il y a 6 mois.
        self::assertSame(5, $data['requestsReceived']);
    }

    public function testGetStatisticsRejectsNonProducer(): void
    {
        $token = $this->registerClientAndLogin('client');

        $this->client->request('GET', '/api/producer/statistics', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(404);
    }
}
