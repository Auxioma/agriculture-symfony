<?php

/**
 * Modération des avis clients (ReviewStatus). Publier/Rejeter font passer un avis Pending à
 * Published/Rejected -- seuls les avis Published sont exposés par
 * ProducerController::listProducerReviews() et peuvent recevoir une réponse du producteur
 * (ReviewController::respondToReview()). Pas d'EDIT libre sur $status : même choix que
 * ConversationCrudController, pour ne jamais court-circuiter le contrôle des transitions.
 */

namespace App\Controller\Admin;

use App\Entity\Trust\Review;
use App\Enum\ReviewStatus;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ReviewCrudController extends AbstractCrudController
{
    use StatusBadgeFieldTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Review::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Avis client')
            ->setEntityLabelInPlural('Avis clients')
            ->setPageTitle(Crud::PAGE_INDEX, 'Avis')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        // * Colonnes de la maquette "Admin · Avis" : NOTE, COMMENTAIRE, statut de modération.
        yield IdField::new('id')->hideOnForm()->hideOnIndex();
        yield AssociationField::new('client')->setLabel('Client')->formatValue(fn ($v, $e) => $e?->getClient()?->getEmail())->hideOnForm();
        yield AssociationField::new('producer')->setLabel('Producteur')->formatValue(fn ($v, $e) => $e?->getProducer()?->getFarmName())->hideOnForm();
        yield IntegerField::new('rating')->setLabel('Note')->formatValue(fn ($v) => null === $v ? '—' : $v.' / 5')->hideOnForm();
        yield TextareaField::new('comment')->setLabel('Commentaire')->hideOnIndex()->hideOnForm();
        yield $this->statusBadgeField('status', 'Statut', $pageName, [
            ReviewStatus::Pending->value => 'En attente',
            ReviewStatus::Published->value => 'Publié',
            ReviewStatus::Rejected->value => 'Rejeté',
        ], [
            ReviewStatus::Pending->value => 'warning',
            ReviewStatus::Published->value => 'success',
            ReviewStatus::Rejected->value => 'danger',
        ])->hideOnForm();
        yield TextareaField::new('producerResponse')->setLabel('Réponse du producteur')->hideOnIndex()->hideOnForm();
        yield DateTimeField::new('createdAt')->setLabel('Date')->setFormat('d MMM y')->hideOnForm();
    }

    // * Puces "En attente/Publiés/Rejetés" (layout.html.twig, tm_filter_chips) au lieu du bouton "+ Filtres".
    // * TextFilter + setFormType(TextType::class) : voir le commentaire équivalent sur
    // * ProducerProfileCrudController::configureFilters() -- valeur soumise gardée plate pour tm_filter_chips.
    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(TextFilter::new('status')->setFormType(TextType::class));
    }

    // * DELETE reste actif : nettoyage d'un avis manifestement abusif, comme pour les demandes clients
    // * (ClientRequestCrudController). NEW/EDIT désactivés : un avis naît du client, sa modération passe
    // * uniquement par Publier/Rejeter ci-dessous.
    public function configureActions(Actions $actions): Actions
    {
        $publish = Action::new('publish', 'Publier')
            ->linkToCrudAction('publishReview')
            ->displayIf(fn (Review $r) => ReviewStatus::Pending === $r->getStatus());

        $reject = Action::new('reject', 'Rejeter')
            ->linkToCrudAction('rejectReview')
            ->displayIf(fn (Review $r) => ReviewStatus::Pending === $r->getStatus());

        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, $publish)->add(Crud::PAGE_DETAIL, $publish)
            ->add(Crud::PAGE_INDEX, $reject)->add(Crud::PAGE_DETAIL, $reject);
    }

    #[AdminRoute(path: '/{entityId}/publish', name: 'publish')]
    public function publishReview(AdminContext $context): Response
    {
        $review = $context->getEntity()->getInstance();
        $this->assertReviewIsPending($review);
        $review->setStatus(ReviewStatus::Published);
        $this->auditLogger->log('review_published', 'trust', 'reviews', $review->getId()->toRfc4122());
        $this->em->flush();
        $this->addFlash('success', 'Avis publié.');

        return $this->redirectToIndex();
    }

    #[AdminRoute(path: '/{entityId}/reject', name: 'reject')]
    public function rejectReview(AdminContext $context): Response
    {
        $review = $context->getEntity()->getInstance();
        $this->assertReviewIsPending($review);
        $review->setStatus(ReviewStatus::Rejected);
        $this->auditLogger->log('review_rejected', 'trust', 'reviews', $review->getId()->toRfc4122());
        $this->em->flush();
        $this->addFlash('success', 'Avis rejeté.');

        return $this->redirectToIndex();
    }

    private function assertReviewIsPending(?Review $review): void
    {
        if (null === $review || ReviewStatus::Pending !== $review->getStatus()) {
            throw new AccessDeniedHttpException("Cet avis n'est plus en attente de modération.");
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
