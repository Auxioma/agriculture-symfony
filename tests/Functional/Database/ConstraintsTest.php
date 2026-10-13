<?php

/**
 * Copyright(c)2026 TrouveMoi (https://trouvemoi.com)
 *
 * Ce fichier fait partie d’un projet développé par Auxioma Web Agency pour l’entreprise.
 * Tous droits réservés.
 *
 * Ce code source est la propriété exclusive de Auxioma Web Agency et.
 * Toute reproduction, modification, distribution ou utilisation sans autorisation préalable est interdite.
 */

namespace App\Tests\Functional\Database;

use App\Entity\Trust\Review;
use App\Enum\ReviewStatus;
use App\Enum\SubscriptionStatus;
use App\Tests\DatabaseTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Prouve que les contraintes UNIQUE et CHECK ajoutées en plus du guide rejettent bien les données invalides
 * au niveau de la base de données — pas seulement côté PHP.
 */
final class ConstraintsTest extends DatabaseTestCase
{
    use EntityFactoryTrait;

    private function assertFlushRejectedWith(string $expectedNeedle): void
    {
        // ! le fail() est hors du try : dedans, le catch l'attrapait et son message (qui contient le texte cherché)
        // ! faisait passer le test même quand la base ne rejetait rien.
        try {
            $this->em->flush();
        } catch (\Throwable $e) {
            self::assertStringContainsString($expectedNeedle, $e->getMessage());

            return;
        }

        self::fail("Expected the flush to be rejected by a database constraint containing '{$expectedNeedle}'.");
    }

    public function testCategorySlugMustBeUnique(): void
    {
        $category1 = $this->makeCategory('Fruits');
        $category1->setSlug('fruits-et-legumes');
        $category2 = $this->makeCategory('Legumes');
        $category2->setSlug('fruits-et-legumes');

        $this->assertFlushRejectedWith('23505');
    }

    public function testUserEmailUniquenessIsCaseInsensitive(): void
    {
        $user1 = $this->makeUser('dup');
        $user1->setEmail('Jean.Dupont@Example.com');
        $this->em->flush();

        $user2 = $this->makeUser('dup2');
        $user2->setEmail('jean.dupont@example.com');

        $this->assertFlushRejectedWith('23505');
    }

    public function testClientRequestUrgencyLevelMustStayWithinZeroAndFive(): void
    {
        $request = $this->makeClientRequest($this->makeUser('client'));
        $request->setUrgencyLevel(9);

        $this->assertFlushRejectedWith('chk_client_requests_urgency_level');
    }

    public function testClientRequestNeedsAProductOrACustomProduct(): void
    {
        $request = new \App\Entity\Matching\ClientRequest();
        $request->setClient($this->makeUser('client'));
        $request->setNeedType(\App\Enum\NeedType::OneShot);
        $request->setStatus(\App\Enum\RequestStatus::Draft);
        // Ni setProduct() ni setCustomProduct() ne sont appelés.
        $this->em->persist($request);

        $this->assertFlushRejectedWith('chk_client_requests_product_or_custom');
    }

    public function testClientRequestBudgetMinCannotExceedBudgetMax(): void
    {
        $request = $this->makeClientRequest($this->makeUser('client'));
        $request->setBudgetMin('100.00');
        $request->setBudgetMax('50.00');

        $this->assertFlushRejectedWith('chk_client_requests_budget_order');
    }

    public function testClientRequestRadiusMustBePositive(): void
    {
        $request = $this->makeClientRequest($this->makeUser('client'));
        $request->setRadiusKm('0');

        $this->assertFlushRejectedWith('chk_client_requests_radius_positive');
    }

    public function testReviewRatingMustStayBetweenOneAndFive(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $country);
        $request = $this->makeClientRequest($this->makeUser('client'));
        $this->em->flush();

        $review = new Review();
        $review->setProducer($producer);
        $review->setRequest($request);
        $review->setRating(6);
        $review->setStatus(ReviewStatus::Published);
        $this->em->persist($review);

        $this->assertFlushRejectedWith('chk_reviews_rating');
    }

    public function testRequestMatchIsUniquePerRequestAndProducer(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $country);
        $request = $this->makeClientRequest($this->makeUser('client'));
        $this->em->flush();

        $match1 = new \App\Entity\Matching\RequestMatch();
        $match1->setRequest($request);
        $match1->setProducer($producer);
        $this->em->persist($match1);
        $this->em->flush();

        $match2 = new \App\Entity\Matching\RequestMatch();
        $match2->setRequest($request);
        $match2->setProducer($producer);
        $this->em->persist($match2);

        $this->assertFlushRejectedWith('23505');
    }

    /**
     * @return iterable<string, array{SubscriptionStatus, SubscriptionStatus}>
     */
    public static function inForceStatusPairs(): iterable
    {
        yield 'deux actifs' => [SubscriptionStatus::Active, SubscriptionStatus::Active];
        yield 'actif puis essai' => [SubscriptionStatus::Active, SubscriptionStatus::Trialing];
        yield 'en retard puis actif' => [SubscriptionStatus::PastDue, SubscriptionStatus::Active];
        yield 'essai puis en retard' => [SubscriptionStatus::Trialing, SubscriptionStatus::PastDue];
    }

    // * actif, en essai ou en retard de paiement : un seul à la fois par producteur, quel que soit le mélange
    #[DataProvider('inForceStatusPairs')]
    public function testProducerCannotHaveTwoSubscriptionsInForce(SubscriptionStatus $first, SubscriptionStatus $second): void
    {
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $this->makeCountry());
        $this->makeActiveSubscription($producer, $first);
        $this->em->flush();

        $this->makeActiveSubscription($producer, $second);

        $this->assertFlushRejectedWith('uniq_subscriptions_one_in_force_per_producer');
    }

    public function testCancelledAndExpiredSubscriptionsPileUpBesideTheCurrentOne(): void
    {
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $this->makeCountry());
        $this->makeActiveSubscription($producer, SubscriptionStatus::Cancelled);
        $this->makeActiveSubscription($producer, SubscriptionStatus::Cancelled);
        $this->makeActiveSubscription($producer, SubscriptionStatus::Expired);
        $this->makeActiveSubscription($producer, SubscriptionStatus::Active);
        $this->em->flush();

        self::assertSame(4, $this->em->getRepository(\App\Entity\Billing\Subscription::class)->count(['producer' => $producer]));
    }

    public function testTwoProducersCanEachHaveASubscriptionInForce(): void
    {
        $country = $this->makeCountry();
        $this->makeActiveSubscription($this->makeProducerProfile($this->makeUser('producer1'), $country));
        $this->makeActiveSubscription($this->makeProducerProfile($this->makeUser('producer2'), $country));

        $this->em->flush();
        $this->addToAssertionCount(1);
    }
}
