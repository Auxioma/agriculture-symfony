<?php

/**
 * Modération des documents justificatifs producteur (VerificationDocumentStatus). Approuver/Rejeter
 * font passer un document Pending à Approved/Rejected et posent $reviewedBy/$reviewedAt. Approuver
 * un document vérifie aussi automatiquement tout ProducerLabel qui le référence (verifiedAt = maintenant,
 * expiresAt = +1 an -- durée par défaut raisonnable, le cahier ne précise pas de durée exacte de
 * validité des labels). Même schéma que ReviewCrudController/ConversationCrudController : pas
 * d'EDIT libre sur $status, uniquement via ces actions.
 */

namespace App\Controller\Admin;

use App\Entity\Identity\User;
use App\Entity\Producer\ProducerLabel;
use App\Entity\Trust\VerificationDocument;
use App\Enum\VerificationDocumentStatus;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class VerificationDocumentCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return VerificationDocument::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Document justificatif')
            ->setEntityLabelInPlural('Documents justificatifs')
            ->setDefaultSort(['reviewedAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('producer')->formatValue(fn ($v, $e) => $e?->getProducer()?->getFarmName())->hideOnForm();
        yield TextField::new('type')->hideOnForm();
        yield ChoiceField::new('status')->hideOnForm();
        yield AssociationField::new('reviewedBy')->formatValue(fn ($v, $e) => $e?->getReviewedBy()?->getEmail())->hideOnForm();
        yield DateTimeField::new('reviewedAt')->hideOnForm();
        yield DateTimeField::new('expiresAt')->hideOnForm();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('status');
    }

    public function configureActions(Actions $actions): Actions
    {
        $approve = Action::new('approve', 'Approuver')
            ->linkToCrudAction('approveDocument')
            ->displayIf(fn (VerificationDocument $d) => VerificationDocumentStatus::Pending === $d->getStatus());

        $reject = Action::new('reject', 'Rejeter')
            ->linkToCrudAction('rejectDocument')
            ->displayIf(fn (VerificationDocument $d) => VerificationDocumentStatus::Pending === $d->getStatus());

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, $approve)->add(Crud::PAGE_DETAIL, $approve)
            ->add(Crud::PAGE_INDEX, $reject)->add(Crud::PAGE_DETAIL, $reject);
    }

    #[AdminRoute(path: '/{entityId}/approve', name: 'approve')]
    public function approveDocument(AdminContext $context, #[CurrentUser] User $admin): Response
    {
        $document = $context->getEntity()->getInstance();
        $this->assertPending($document);

        $document->setStatus(VerificationDocumentStatus::Approved);
        $document->setReviewedBy($admin);
        $document->setReviewedAt(new \DateTimeImmutable());

        // * Un document peut justifier plusieurs labels revendiqués (ex. un même Kbis attaché à
        // * deux labels) -- on les vérifie tous plutôt que d'exiger un document par label.
        $labels = $this->em->getRepository(ProducerLabel::class)->findBy(['document' => $document]);
        $now = new \DateTimeImmutable();
        foreach ($labels as $producerLabel) {
            $producerLabel->setVerifiedAt($now);
            $producerLabel->setExpiresAt($now->modify('+1 year'));
        }

        $this->auditLogger->log('verification_document_approved', 'trust', 'verification_documents', $document->getId()->toRfc4122());
        $this->em->flush();
        $this->addFlash('success', 'Document approuvé.');

        return $this->redirectToIndex();
    }

    #[AdminRoute(path: '/{entityId}/reject', name: 'reject')]
    public function rejectDocument(AdminContext $context, #[CurrentUser] User $admin): Response
    {
        $document = $context->getEntity()->getInstance();
        $this->assertPending($document);

        $document->setStatus(VerificationDocumentStatus::Rejected);
        $document->setReviewedBy($admin);
        $document->setReviewedAt(new \DateTimeImmutable());

        $this->auditLogger->log('verification_document_rejected', 'trust', 'verification_documents', $document->getId()->toRfc4122());
        $this->em->flush();
        $this->addFlash('success', 'Document rejeté.');

        return $this->redirectToIndex();
    }

    private function assertPending(?VerificationDocument $document): void
    {
        if (null === $document || VerificationDocumentStatus::Pending !== $document->getStatus()) {
            throw new AccessDeniedHttpException("Ce document n'est plus en attente de modération.");
        }
    }

    private function redirectToIndex(): Response
    {
        return $this->redirect(
            $this->container->get(AdminUrlGenerator::class)
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->unset('entityId')
                ->generateUrl()
        );
    }
}
