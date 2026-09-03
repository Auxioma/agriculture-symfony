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

// use App\Enum\SubscriptionStatus;
use App\Enum\VerificationStatus;
use App\Tests\DatabaseTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

final class MatchingSqlFunctionsTest extends DatabaseTestCase
{
    use EntityFactoryTrait;

    public function testPopulateRequestMatchesFindsVerifiedSubscribedProducer(): void
    {
        $country = $this->makeCountry();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);

        $producer = $this->makeProducerProfile($this->makeUser('producer'), $country, VerificationStatus::Verified);
        $this->makeProducerProduct($producer, $product, true);
        $this->makeActiveSubscription($producer);

        $request = $this->makeClientRequest($this->makeUser('client'), $product);

        $this->em->flush();

        // Paris, puis un point à ~1km largement dans le rayon par défaut de 50km de la demande.
        $this->setGeographyPoint('producer.producer_profiles', 'location', $producer->getId()->toRfc4122(), 2.3522, 48.8566);
        $this->setGeographyPoint('matching.client_requests', 'location', $request->getId()->toRfc4122(), 2.3600, 48.8600);

        $connection = $this->em->getConnection();

        $inserted = $connection->fetchOne(
            'SELECT matching.populate_request_matches(:id)',
            ['id' => $request->getId()->toRfc4122()]
        );
        self::assertSame(1, (int) $inserted);

        $match = $connection->fetchAssociative(
            'SELECT * FROM matching.request_matches WHERE request_id = :id',
            ['id' => $request->getId()->toRfc4122()]
        );
        self::assertNotFalse($match, 'find_matching_producers should have matched the verified, subscribed, active producer');
        self::assertSame('proposed', $match['status']);
        self::assertGreaterThan(80, (float) $match['score'], 'score should reflect has_product + active_subscription + verified + short distance bonuses');

        $hasFeature = $connection->fetchOne(
            "SELECT billing.producer_has_feature(:pid, 'reply_to_requests')",
            ['pid' => $producer->getId()->toRfc4122()]
        );
        self::assertTrue((bool) $hasFeature, 'producer_has_feature should be true for a feature enabled on an active plan');

        $auditCount = $connection->fetchOne(
            "SELECT count(*) FROM audit.audit_logs WHERE table_name = 'producer_profiles' AND record_id = :id",
            ['id' => $producer->getId()->toRfc4122()]
        );
        self::assertGreaterThanOrEqual(1, (int) $auditCount, 'the audit trigger should have logged the producer_profiles insert');
    }

    public function testPopulateRequestMatchesIsIdempotentOnReRun(): void
    {
        $country = $this->makeCountry();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);

        $producer = $this->makeProducerProfile($this->makeUser('producer'), $country);
        $this->makeProducerProduct($producer, $product, true);
        $request = $this->makeClientRequest($this->makeUser('client'), $product);

        $this->em->flush();

        $this->setGeographyPoint('producer.producer_profiles', 'location', $producer->getId()->toRfc4122(), 2.35, 48.85);
        $this->setGeographyPoint('matching.client_requests', 'location', $request->getId()->toRfc4122(), 2.36, 48.86);

        $connection = $this->em->getConnection();
        $connection->executeStatement('SELECT matching.populate_request_matches(:id)', ['id' => $request->getId()->toRfc4122()]);
        $connection->executeStatement('SELECT matching.populate_request_matches(:id)', ['id' => $request->getId()->toRfc4122()]);

        $count = $connection->fetchOne(
            'SELECT count(*) FROM matching.request_matches WHERE request_id = :id',
            ['id' => $request->getId()->toRfc4122()]
        );
        self::assertSame(1, (int) $count, 'ON CONFLICT should update the existing match instead of inserting a duplicate');
    }

    public function testProducerWithoutTheRequestedProductIsNotMatched(): void
    {
        $country = $this->makeCountry();
        $category = $this->makeCategory();
        $requestedProduct = $this->makeProduct($category, 'Pommes');
        $otherProduct = $this->makeProduct($category, 'Poires');

        $producer = $this->makeProducerProfile($this->makeUser('producer'), $country);
        // Le producteur ne vend que des « Poires », la demande porte sur des « Pommes ».
        $this->makeProducerProduct($producer, $otherProduct, true);
        $request = $this->makeClientRequest($this->makeUser('client'), $requestedProduct);

        $this->em->flush();

        $this->setGeographyPoint('producer.producer_profiles', 'location', $producer->getId()->toRfc4122(), 2.35, 48.85);
        $this->setGeographyPoint('matching.client_requests', 'location', $request->getId()->toRfc4122(), 2.36, 48.86);

        $inserted = $this->em->getConnection()->fetchOne(
            'SELECT matching.populate_request_matches(:id)',
            ['id' => $request->getId()->toRfc4122()]
        );
        self::assertSame(0, (int) $inserted, 'a producer who does not sell the requested product must never be matched');
    }

    public function testProducerOutsideRadiusIsNotMatched(): void
    {
        $country = $this->makeCountry();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);

        $producer = $this->makeProducerProfile($this->makeUser('producer'), $country);
        $this->makeProducerProduct($producer, $product, true);
        $request = $this->makeClientRequest($this->makeUser('client'), $product);

        $this->em->flush();

        // Paris vs. Marseille ~660 km d'écart, largement au-delà du rayon par défaut de 50 km.
        $this->setGeographyPoint('producer.producer_profiles', 'location', $producer->getId()->toRfc4122(), 2.3522, 48.8566);
        $this->setGeographyPoint('matching.client_requests', 'location', $request->getId()->toRfc4122(), 5.3698, 43.2965);

        $inserted = $this->em->getConnection()->fetchOne(
            'SELECT matching.populate_request_matches(:id)',
            ['id' => $request->getId()->toRfc4122()]
        );
        self::assertSame(0, (int) $inserted, 'a producer outside the requested radius must never be matched');
    }

    public function testUnverifiedProducerWithoutSubscriptionScoresLower(): void
    {
        $country = $this->makeCountry();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);

        // Pas d'abonnement, pas vérifié : seul le bonus has_product(50) + distance devrait s'appliquer.
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $country, VerificationStatus::Pending);
        $this->makeProducerProduct($producer, $product, true);
        $request = $this->makeClientRequest($this->makeUser('client'), $product);

        $this->em->flush();

        $this->setGeographyPoint('producer.producer_profiles', 'location', $producer->getId()->toRfc4122(), 2.3522, 48.8566);
        $this->setGeographyPoint('matching.client_requests', 'location', $request->getId()->toRfc4122(), 2.3600, 48.8600);

        $this->em->getConnection()->executeStatement(
            'SELECT matching.populate_request_matches(:id)',
            ['id' => $request->getId()->toRfc4122()]
        );

        $match = $this->em->getConnection()->fetchAssociative(
            'SELECT * FROM matching.request_matches WHERE request_id = :id',
            ['id' => $request->getId()->toRfc4122()]
        );
        self::assertNotFalse($match);
        self::assertLessThan(80, (float) $match['score'], 'without subscription/verification the score should stay well below a fully-qualified producer');

        $reasons = json_decode($match['reasons'], true);
        self::assertFalse($reasons['active_subscription']);
        self::assertFalse($reasons['verified']);
    }

    public function testProducerHasFeatureIsFalseForUnknownFeature(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $country);
        $this->makeActiveSubscription($producer, features: ['reply_to_requests' => true]);
        $this->em->flush();

        $hasFeature = $this->em->getConnection()->fetchOne(
            "SELECT billing.producer_has_feature(:pid, 'unknown_feature')",
            ['pid' => $producer->getId()->toRfc4122()]
        );
        self::assertFalse((bool) $hasFeature, 'producer_has_feature should be false for a feature not present in the plan');
    }

    public function testAnonymizeUserClearsPersonalDataAndClientRequest(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);

        // *Créationn de user et de demande pour vérifier suppression données personnelles
        $user = $this->makeUser('to-delete');
        $user->setFirstName('Jean');
        $user->setLastName('Dupont');

        $request = $this->makeClientRequest($user, $product);
        $request->setMessage('Je veux faire des tests !');

        $this->em->flush();

        $id = $user->getId()->toRfc4122();
        $requestId = $request->getId()->toRfc4122();
        $connection = $this->em->getConnection();

        // Annonimisation données personnelles
        $connection->executeStatement('SELECT identity.anonymize_user(:id)', ['id' => $id]);

        $row = $connection->fetchAssociative('SELECT * FROM identity.users WHERE id = :id', ['id' => $id]);
        $rowRequest = $connection->fetchAssociative('SELECT * FROM matching.client_requests WHERE id = :id', ['id' => $requestId]);

        self::assertStringStartsWith('deleted+', (string) $row['email']);
        self::assertSame('', $row['password_hash']);
        self::assertNull($row['first_name']);
        self::assertNull($row['last_name']);
        self::assertSame('deleted', $row['status']);

        self::assertNull($rowRequest['message']);
    }
}
