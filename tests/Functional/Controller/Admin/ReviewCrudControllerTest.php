<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\ReviewCrudController;
use App\Entity\Identity\User;
use App\Entity\Trust\Review;
use App\Enum\ReviewStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Teste le module "Avis clients" du back-office : Publier/Rejeter (ReviewCrudController), qui font
 * passer un avis Pending à Published/Rejected.
 */
final class ReviewCrudControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private function loginAsAdmin(): User
    {
        $admin = $this->makeUserWithPassword('admin', 'motdepasse123');
        $admin->setRoles([User::ROLE_ADMIN]);
        $this->em->flush();

        $this->client->followRedirects(true);
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Se connecter', [
            '_username' => $admin->getEmail(),
            '_password' => 'motdepasse123',
        ]);

        return $admin;
    }

    private function makePendingReview(): Review
    {
        $country = $this->makeCountry();
        $client = $this->makeUser('client');
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $country);
        $request = $this->makeClientRequest($client);

        $review = new Review();
        $review->setClient($client);
        $review->setProducer($producer);
        $review->setRequest($request);
        $review->setRating(5);
        $this->em->persist($review);
        $this->em->flush();

        return $review;
    }

    public function testPublishingPendingReviewMakesItPublished(): void
    {
        $this->loginAsAdmin();
        $review = $this->makePendingReview();

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(ReviewCrudController::class)
            ->setAction('publishReview')
            ->setEntityId($review->getId())
            ->generateUrl());

        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative('SELECT status FROM trust.reviews WHERE id = :id', ['id' => $review->getId()->toRfc4122()]);
        self::assertSame('published', $row['status']);

        $audit = $this->em->getConnection()->fetchAssociative(
            "SELECT record_id FROM audit.audit_logs WHERE action = 'review_published' AND table_name = 'reviews'"
        );
        self::assertNotFalse($audit);
        self::assertSame($review->getId()->toRfc4122(), $audit['record_id']);
    }

    public function testRejectingPendingReviewMakesItRejected(): void
    {
        $this->loginAsAdmin();
        $review = $this->makePendingReview();

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(ReviewCrudController::class)
            ->setAction('rejectReview')
            ->setEntityId($review->getId())
            ->generateUrl());

        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative('SELECT status FROM trust.reviews WHERE id = :id', ['id' => $review->getId()->toRfc4122()]);
        self::assertSame('rejected', $row['status']);
    }

    public function testPublishingAlreadyModeratedReviewIsForbidden(): void
    {
        $this->loginAsAdmin();
        $review = $this->makePendingReview();
        $review->setStatus(ReviewStatus::Published);
        $this->em->flush();

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(ReviewCrudController::class)
            ->setAction('publishReview')
            ->setEntityId($review->getId())
            ->generateUrl());

        self::assertResponseStatusCodeSame(403);
    }
}
