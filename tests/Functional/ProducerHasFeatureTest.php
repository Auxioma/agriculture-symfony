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

namespace App\Tests\Functional;

use App\Enum\SubscriptionStatus;
use App\Tests\DatabaseTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

final class ProducerHasFeatureTest extends DatabaseTestCase
{
    use EntityFactoryTrait;

    private function producerHasFeature(string $producerId, string $feature = 'reply_to_requests'): bool
    {
        return (bool) $this->em->getConnection()->fetchOne(
            'SELECT billing.producer_has_feature(:pid, :feature)',
            ['pid' => $producerId, 'feature' => $feature]
        );
    }

    public function testActiveSubscriptionWithinPeriodGrantsAccess(): void
    {
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $this->makeCountry());
        $this->makeActiveSubscription($producer, SubscriptionStatus::Active);
        $this->em->flush();

        self::assertTrue($this->producerHasFeature($producer->getId()->toRfc4122()));
    }

    public function testTrialingSubscriptionGrantsAccess(): void
    {
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $this->makeCountry());
        $this->makeActiveSubscription($producer, SubscriptionStatus::Trialing);
        $this->em->flush();

        self::assertTrue($this->producerHasFeature($producer->getId()->toRfc4122()));
    }

    public function testExpiredPeriodDeniesAccessEvenIfStatusIsActive(): void
    {
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $this->makeCountry());
        $this->makeActiveSubscription(
            $producer,
            SubscriptionStatus::Active,
            new \DateTimeImmutable('-60 days'),
            new \DateTimeImmutable('-30 days'),
        );
        $this->em->flush();

        self::assertFalse($this->producerHasFeature($producer->getId()->toRfc4122()), 'a subscription whose period has ended must not grant access, regardless of its stored status');
    }

    public function testCancelledStatusDeniesAccessEvenWithinThePeriod(): void
    {
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $this->makeCountry());
        $this->makeActiveSubscription($producer, SubscriptionStatus::Cancelled);
        $this->em->flush();

        self::assertFalse($this->producerHasFeature($producer->getId()->toRfc4122()), 'a cancelled subscription must not grant access even if current_period_end is in the future');
    }

    public function testPastDueStatusDeniesAccess(): void
    {
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $this->makeCountry());
        $this->makeActiveSubscription($producer, SubscriptionStatus::PastDue);
        $this->em->flush();

        self::assertFalse($this->producerHasFeature($producer->getId()->toRfc4122()));
    }

    public function testFeatureExplicitlyDisabledOnThePlanDeniesAccess(): void
    {
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $this->makeCountry());
        $this->makeActiveSubscription($producer, features: ['reply_to_requests' => false]);
        $this->em->flush();

        self::assertFalse($this->producerHasFeature($producer->getId()->toRfc4122()));
    }

    public function testProducerWithNoSubscriptionAtAllHasNoFeatures(): void
    {
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $this->makeCountry());
        $this->em->flush();

        self::assertFalse($this->producerHasFeature($producer->getId()->toRfc4122()));
    }
}
