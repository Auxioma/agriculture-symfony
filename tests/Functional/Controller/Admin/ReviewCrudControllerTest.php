<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\ReviewCrudController;
use App\Entity\Identity\User;
use App\Entity\Trust\Review;
use App\Enum\ReviewStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste le module "Avis clients" du back-office : Publier/Rejeter (ReviewCrudController), qui font
 * passer un avis Pending à Published/Rejected.
 */
final class ReviewCrudControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;
    use RowActionTestTrait;

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

        $this->postRowAction(ReviewCrudController::class, $review->getId()->toRfc4122(), 'publish');

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

        $this->postRowAction(ReviewCrudController::class, $review->getId()->toRfc4122(), 'reject');

        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative('SELECT status FROM trust.reviews WHERE id = :id', ['id' => $review->getId()->toRfc4122()]);
        self::assertSame('rejected', $row['status']);
    }

    public function testPublishingAlreadyModeratedReviewIsForbidden(): void
    {
        $this->loginAsAdmin();
        $review = $this->makePendingReview();
        // * L'adresse (et son jeton) se lit tant que le bouton est affiché ; l'avis est modéré ensuite, avant l'envoi.
        // * En SQL : après une requête, $this->em ne suit plus l'entité (le kernel remet Doctrine à zéro), un flush() ne ferait rien.
        $url = $this->rowActionUrl(ReviewCrudController::class, $review->getId()->toRfc4122(), 'publish');
        $this->em->getConnection()->executeStatement('UPDATE trust.reviews SET status = :status WHERE id = :id', [
            'status' => ReviewStatus::Published->value,
            'id' => $review->getId()->toRfc4122(),
        ]);

        $this->client->request('POST', $url);

        self::assertResponseStatusCodeSame(403);
    }

    // * Avant : un simple lien GET suffisait à publier un avis (pas de jeton, pas de POST).
    public function testModerationActionsRefuseGet(): void
    {
        $this->loginAsAdmin();
        $review = $this->makePendingReview();
        $id = $review->getId()->toRfc4122();

        foreach (['publish', 'reject'] as $pathEnd) {
            $this->client->request('GET', $this->rowActionUrl(ReviewCrudController::class, $id, $pathEnd));
            self::assertResponseStatusCodeSame(405, $pathEnd);
        }

        self::assertSame('pending', $this->em->getConnection()->fetchOne('SELECT status FROM trust.reviews WHERE id = :id', ['id' => $id]));
    }

    public function testModerationActionsRefuseAMissingOrForgedToken(): void
    {
        $this->loginAsAdmin();
        $review = $this->makePendingReview();
        $id = $review->getId()->toRfc4122();

        foreach (['publish', 'reject'] as $pathEnd) {
            $url = $this->rowActionUrl(ReviewCrudController::class, $id, $pathEnd);

            $this->client->request('POST', $this->withForgedToken($url));
            self::assertResponseStatusCodeSame(403, $pathEnd.' avec un faux jeton');

            $this->client->request('POST', $this->withoutToken($url));
            self::assertResponseStatusCodeSame(403, $pathEnd.' sans jeton');
        }

        self::assertSame('pending', $this->em->getConnection()->fetchOne('SELECT status FROM trust.reviews WHERE id = :id', ['id' => $id]));
    }

    // * Le jeton de "Publier" ne vaut pas pour "Rejeter" : il est lié à l'action.
    public function testATokenIsOnlyValidForItsOwnAction(): void
    {
        $this->loginAsAdmin();
        $review = $this->makePendingReview();
        $id = $review->getId()->toRfc4122();

        $publishUrl = $this->rowActionUrl(ReviewCrudController::class, $id, 'publish');
        $token = (string) preg_replace('/^.*[?&]_token=([^&]+).*$/', '$1', $publishUrl);
        $rejectUrl = $this->rowActionUrl(ReviewCrudController::class, $id, 'reject');

        $this->client->request('POST', (string) preg_replace('/([?&]_token=)[^&]+/', '${1}'.$token, $rejectUrl));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('pending', $this->em->getConnection()->fetchOne('SELECT status FROM trust.reviews WHERE id = :id', ['id' => $id]));
    }
}
