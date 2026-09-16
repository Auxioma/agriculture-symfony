<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\VerificationDocumentCrudController;
use App\Entity\Identity\User;
use App\Entity\Producer\ProducerLabel;
use App\Entity\Trust\VerificationDocument;
use App\Enum\VerificationDocumentStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Teste le module "Documents justificatifs" du back-office : Approuver/Rejeter
 * (VerificationDocumentCrudController), et la vérification automatique du ProducerLabel lié lors
 * de l'approbation.
 */
final class VerificationDocumentCrudControllerTest extends ApiTestCase
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

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(VerificationDocumentCrudController::class)
            ->setAction('approveDocument')
            ->setEntityId($document->getId())
            ->generateUrl());
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

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(VerificationDocumentCrudController::class)
            ->setAction('rejectDocument')
            ->setEntityId($document->getId())
            ->generateUrl());
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
        $document->setStatus(VerificationDocumentStatus::Approved);
        $this->em->flush();

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(VerificationDocumentCrudController::class)
            ->setAction('approveDocument')
            ->setEntityId($document->getId())
            ->generateUrl());
        self::assertResponseStatusCodeSame(403);
    }
}
