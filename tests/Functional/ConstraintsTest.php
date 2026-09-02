<?php

namespace App\Tests\Functional;

use App\Entity\Trust\Review;
use App\Tests\DatabaseTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Prouve que les contraintes UNIQUE et CHECK ajoutées en plus du guide rejettent bien les données invalides
 * au niveau de la base de données — pas seulement côté PHP.
 */
final class ConstraintsTest extends DatabaseTestCase
{
    use EntityFactoryTrait;

    private function assertFlushRejectedWith(string $expectedNeedle): void
    {
        try {
            $this->em->flush();
            self::fail("Expected the flush to be rejected by a database constraint containing '{$expectedNeedle}'.");
        } catch (\Throwable $e) {
            self::assertStringContainsString($expectedNeedle, $e->getMessage());
        }
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
        $review->setStatus('published');
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
}
