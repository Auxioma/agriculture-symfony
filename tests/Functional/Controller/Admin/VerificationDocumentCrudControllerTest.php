<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\VerificationDocumentCrudController;
use App\Entity\Identity\User;
use App\Entity\Producer\ProducerLabel;
use App\Entity\Trust\VerificationDocument;
use App\Enum\VerificationDocumentStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste le module "Documents justificatifs" du back-office : Approuver/Rejeter
 * (VerificationDocumentCrudController), et la vérification automatique du ProducerLabel lié lors
 * de l'approbation.
 */
final class VerificationDocumentCrudControllerTest extends ApiTestCase
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

    private function makePendingDocumentWithClaimedLabel(): VerificationDocument
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $country);
        $label = $this->makeLabel();

        $document = new VerificationDocument();
        $document->setProducer($producer);
        $document->setType('kbis');
        $this->em->persist($document);

        $producerLabel = new ProducerLabel();
        $producerLabel->setProducer($producer);
        $producerLabel->setLabel($label);
        $producerLabel->setDocument($document);
        $this->em->persist($producerLabel);

        $this->em->flush();

        return $document;
    }

    public function testApprovingDocumentVerifiesLinkedLabel(): void
    {
        $this->loginAsAdmin();
        $document = $this->makePendingDocumentWithClaimedLabel();

        $this->postRowAction(VerificationDocumentCrudController::class, $document->getId()->toRfc4122(), 'approve');
        self::assertResponseIsSuccessful();

        $docRow = $this->em->getConnection()->fetchAssociative(
            'SELECT status, reviewed_at FROM trust.verification_documents WHERE id = :id',
            ['id' => $document->getId()->toRfc4122()]
        );
        self::assertSame('approved', $docRow['status']);
        self::assertNotNull($docRow['reviewed_at']);

        $labelRow = $this->em->getConnection()->fetchAssociative(
            'SELECT verified_at, expires_at FROM producer.producer_labels WHERE document_id = :id',
            ['id' => $document->getId()->toRfc4122()]
        );
        self::assertNotNull($labelRow['verified_at']);
        self::assertNotNull($labelRow['expires_at']);

        $audit = $this->em->getConnection()->fetchAssociative(
            "SELECT record_id FROM audit.audit_logs WHERE action = 'verification_document_approved' AND table_name = 'verification_documents'"
        );
        self::assertNotFalse($audit);
    }

    public function testRejectingDocumentSetsStatus(): void
    {
        $this->loginAsAdmin();
        $document = $this->makePendingDocumentWithClaimedLabel();

        $this->postRowAction(VerificationDocumentCrudController::class, $document->getId()->toRfc4122(), 'reject');
        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status FROM trust.verification_documents WHERE id = :id',
            ['id' => $document->getId()->toRfc4122()]
        );
        self::assertSame('rejected', $row['status']);

        // * Un document rejeté ne doit pas vérifier le label qui le référence.
        $labelRow = $this->em->getConnection()->fetchAssociative(
            'SELECT verified_at FROM producer.producer_labels WHERE document_id = :id',
            ['id' => $document->getId()->toRfc4122()]
        );
        self::assertNull($labelRow['verified_at']);
    }

    public function testApprovingAlreadyReviewedDocumentIsForbidden(): void
    {
        $this->loginAsAdmin();
        $document = $this->makePendingDocumentWithClaimedLabel();
        // * Adresse lue tant que le bouton est affiché, document traité ensuite, envoi en dernier. Le changement se fait en
        // * SQL : après une requête, $this->em ne suit plus l'entité (le kernel remet Doctrine à zéro), un flush() ne ferait rien.
        $url = $this->rowActionUrl(VerificationDocumentCrudController::class, $document->getId()->toRfc4122(), 'approve');
        $this->em->getConnection()->executeStatement('UPDATE trust.verification_documents SET status = :status WHERE id = :id', [
            'status' => VerificationDocumentStatus::Approved->value,
            'id' => $document->getId()->toRfc4122(),
        ]);

        $this->client->request('POST', $url);
        self::assertResponseStatusCodeSame(403);
    }

    // * Avant : un simple lien GET suffisait, sans jeton.
    public function testDocumentActionsRefuseGetAndABadToken(): void
    {
        $this->loginAsAdmin();
        $document = $this->makePendingDocumentWithClaimedLabel();
        $id = $document->getId()->toRfc4122();

        foreach (['approve', 'reject'] as $pathEnd) {
            $url = $this->rowActionUrl(VerificationDocumentCrudController::class, $id, $pathEnd);

            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(405, $pathEnd.' en GET');

            $this->client->request('POST', $this->withForgedToken($url));
            self::assertResponseStatusCodeSame(403, $pathEnd.' avec un faux jeton');

            $this->client->request('POST', $this->withoutToken($url));
            self::assertResponseStatusCodeSame(403, $pathEnd.' sans jeton');
        }

        self::assertSame('pending', $this->em->getConnection()->fetchOne('SELECT status FROM trust.verification_documents WHERE id = :id', ['id' => $id]));
    }
}
