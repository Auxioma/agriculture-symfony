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
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// * RBAC (cahier DevOps ; cahier fonctionnel 22.2, "Le support accède uniquement aux éléments nécessaires") :
// * pas dans le périmètre nécessaire au support (voir security.yaml pour le détail du mécanisme).
#[IsGranted('ROLE_ADMIN')]
class VerificationDocumentCrudController extends AbstractCrudController
{
    use EagerAssociationJoinTrait;
    use CsrfPostActionTrait;

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

    // * producer et reviewedBy forcent sinon une requête par ligne (voir le commentaire équivalent sur
    // * ClientRequestCrudController::createIndexQueryBuilder()).
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->leftJoin('entity.producer', 'producer')->addSelect('producer')
            ->leftJoin('entity.reviewedBy', 'reviewedBy')->addSelect('reviewedBy');
        $qb = $this->joinProducerEagerly($qb, 'producer');

        return $this->joinUserEagerly($qb, 'reviewedBy');
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
        // * Formulaires POST avec jeton (CsrfPostActionTrait), plus des liens GET.
        $approve = $this->postAction('approve', 'Approuver', 'approveDocument')
            ->displayIf(fn (VerificationDocument $d) => VerificationDocumentStatus::Pending === $d->getStatus());

        $reject = $this->postAction('reject', 'Rejeter', 'rejectDocument')
            ->displayIf(fn (VerificationDocument $d) => VerificationDocumentStatus::Pending === $d->getStatus());

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, $approve)->add(Crud::PAGE_DETAIL, $approve)
            ->add(Crud::PAGE_INDEX, $reject)->add(Crud::PAGE_DETAIL, $reject);
    }

    #[AdminRoute(path: '/{entityId}/approve', name: 'approve', options: ['methods' => ['POST']])]
    public function approveDocument(AdminContext $context, #[CurrentUser] User $admin): Response
    {
        $this->assertPostWithToken($context);
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

    #[AdminRoute(path: '/{entityId}/reject', name: 'reject', options: ['methods' => ['POST']])]
    public function rejectDocument(AdminContext $context, #[CurrentUser] User $admin): Response
    {
        $this->assertPostWithToken($context);
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
}
