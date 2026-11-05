<?php

namespace App\Controller\Admin;

use App\Entity\Producer\ProducerLabel;
use App\Entity\Producer\ProducerProfile;
use App\Enum\VerificationStatus;
use App\Service\Notification\NotificationService;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\Audit\AuditLogger;

// * RBAC (cahier DevOps, "Autorisation : RBAC côté Symfony" ; cahier fonctionnel 22.2, "Le support accède
// * uniquement aux éléments nécessaires") : la validation d'un producteur est une décision d'admin, pas un
// * geste de support -- ROLE_ADMIN exclut ROLE_SUPPORT seul (role_hierarchy va dans l'autre sens, voir
// * security.yaml). Un ROLE_SUPPORT qui visite /admin/producer-profile reçoit un 403.
#[IsGranted('ROLE_ADMIN')]
class ProducerProfileCrudController extends AbstractCrudController
{
    use StatusBadgeFieldTrait;
    use EagerAssociationJoinTrait;
    use CsrfPostActionTrait;

    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public static function getEntityFqcn(): string
    {
        return ProducerProfile::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Producteur')
            ->setEntityLabelInPlural('Producteurs')
            ->setPageTitle(Crud::PAGE_INDEX, 'Validation des producteurs')
            ->setDefaultSort(['farmName' => 'ASC']);
    }

    // * owner force sinon une requête par ligne (voir le commentaire équivalent sur
    // * ClientRequestCrudController::createIndexQueryBuilder()) ; entity elle-même (ProducerProfile) a sa
    // * propre association eager forcée (settings, voir EagerAssociationJoinTrait) qui se déclencherait sinon
    // * une fois par ligne même sans jointure explicite -- entity est ici aussi le producteur "racine".
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->leftJoin('entity.owner', 'owner')->addSelect('owner');
        $qb = $this->joinUserEagerly($qb, 'owner');

        return $this->joinProducerEagerly($qb, 'entity');
    }

    // * verificationStatus n'est PAS éditable ici (hideOnForm) les transitions passent uniquement par les
    // * actions Valider/Refuser/Demander un complément, pour garantir qu'un changement de statut déclenche
    // * toujours la notification associée, un simple dropdown éditable permettrait de le contourner.
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->hideOnIndex();
        yield TextField::new('farmName')->setLabel('Exploitation');
        yield AssociationField::new('owner')
            ->setLabel('Contact')
            ->formatValue(fn ($value, $entity) => $entity?->getOwner()?->getEmail())
            ->hideOnForm();
        yield AssociationField::new('country')->setLabel('Pays')->hideOnForm();
        yield TextField::new('city')->setLabel('Localisation')->hideOnForm();
        yield $this->statusBadgeField('verificationStatus', 'Statut', $pageName, [
            VerificationStatus::Draft->value => 'Brouillon',
            VerificationStatus::Pending->value => 'En attente',
            VerificationStatus::Verified->value => 'Validé',
            VerificationStatus::Rejected->value => 'Refusé',
            VerificationStatus::Suspended->value => 'Suspendu',
        ], [
            VerificationStatus::Draft->value => 'secondary',
            VerificationStatus::Pending->value => 'warning',
            VerificationStatus::Verified->value => 'success',
            VerificationStatus::Rejected->value => 'danger',
            VerificationStatus::Suspended->value => 'warning',
        ])->hideOnForm();
        yield BooleanField::new('isActive')->setLabel('Actif')->hideOnForm();
        // * Pas AssociationField('labels') : ProducerLabel a une clé primaire composée (producteur + label) et EasyAdmin refuse
        // * ces entités, la fiche d'un producteur plantait en 500 à chaque ouverture (labels ou pas). Champ virtuel avec les noms.
        yield TextField::new('labelNames')
            ->setLabel('Labels')
            ->setVirtual(true)
            ->onlyOnDetail()
            ->setTemplatePath('admin/field/formatted_text.html.twig')
            ->formatValue(fn ($value, ?ProducerProfile $producer) => $this->labelNames($producer));
        yield AssociationField::new('verificationDocuments')->setLabel('Documents')->onlyOnDetail();
    }

    private function labelNames(?ProducerProfile $producer): string
    {
        $names = null === $producer ? [] : $producer->getLabels()->map(fn (ProducerLabel $pl) => $pl->getLabel()->getName())->toArray();

        return [] === $names ? '—' : implode(', ', $names);
    }

    // * Puces "En attente/Validés/Refusés" (layout.html.twig, tm_filter_chips) au lieu du bouton "+ Filtres".
    // * TextFilter + setFormType(TextType::class) : par défaut EasyAdmin imbrique la valeur soumise sous
    // * "comparison"/"value", ici on la garde plate ("filters[verificationStatus]=pending") pour rester
    // * lisible par tm_filter_chips, comme RoleFilter sur UserCrudController.
    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(TextFilter::new('verificationStatus')->setFormType(TextType::class));
    }

    public function configureActions(Actions $actions): Actions
    {
        // * Des formulaires POST avec jeton (CsrfPostActionTrait), plus des liens GET.
        $validate = $this->postAction('validate', 'Valider', 'validateProducer')
            ->displayIf(fn (ProducerProfile $p) => $p->getVerificationStatus() !== VerificationStatus::Verified);

        $reject = $this->postAction('reject', 'Refuser', 'rejectProducer')
            ->askConfirmation('Refuser ce producteur ? Il sera prévenu par notification.', 'Refuser')
            ->displayIf(fn (ProducerProfile $p) => $p->getVerificationStatus() !== VerificationStatus::Rejected);

        $requestMoreInfo = $this->postAction('requestMoreInfo', 'Demander un complément', 'requestMoreInfo')
            ->displayIf(fn (ProducerProfile $p) => $p->getVerificationStatus() === VerificationStatus::Pending);

        return $actions
            ->disable(Action::NEW, Action::DELETE)
            ->add(Crud::PAGE_INDEX, $validate)->add(Crud::PAGE_DETAIL, $validate)
            ->add(Crud::PAGE_INDEX, $reject)->add(Crud::PAGE_DETAIL, $reject)
            ->add(Crud::PAGE_DETAIL, $requestMoreInfo);
    }

    // * Le bouton est masqué selon le statut (displayIf), mais ça ne protège rien : un POST direct passait quand même
    // * et renvoyait la notification. Le statut est donc revérifié ici pour les trois actions.
    #[AdminRoute(path: '/{entityId}/validate', name: 'validate', options: ['methods' => ['POST']])]
    public function validateProducer(AdminContext $context, EntityManagerInterface $em, NotificationService $notificationService): Response
    {
        $this->assertPostWithToken($context);
        $producer = $context->getEntity()->getInstance();
        if (VerificationStatus::Verified === $producer->getVerificationStatus()) {
            throw new AccessDeniedHttpException('Ce profil est déjà validé.');
        }
        $previousStatus = $producer->getVerificationStatus();
        $producer->setVerificationStatus(VerificationStatus::Verified);
        $notificationService->notify($producer->getOwner(), 'profile_validated', 'Profil validé', 'Votre profil producteur a été validé par notre équipe.');
        $this->auditLogger->log(
            'producer_validated', 'producer', 'producer_profiles', $producer->getId()->toRfc4122(),
            ['verificationStatus' => $previousStatus->value], ['verificationStatus' => VerificationStatus::Verified->value]
        );
        $em->flush();

        $this->addFlash('success', 'Profil validé.');

        return $this->redirectToIndex();
    }

    #[AdminRoute(path: '/{entityId}/reject', name: 'reject', options: ['methods' => ['POST']])]
    public function rejectProducer(AdminContext $context, EntityManagerInterface $em, NotificationService $notificationService): Response
    {
        $this->assertPostWithToken($context);
        $producer = $context->getEntity()->getInstance();
        if (VerificationStatus::Rejected === $producer->getVerificationStatus()) {
            throw new AccessDeniedHttpException('Ce profil est déjà refusé.');
        }
        $previousStatus = $producer->getVerificationStatus();
        $producer->setVerificationStatus(VerificationStatus::Rejected);
        $notificationService->notify($producer->getOwner(), 'profile_rejected', 'Profil non validé', "Votre profil producteur n'a pas été validé. Contactez le support pour plus d'informations.");
        $this->auditLogger->log(
            'producer_rejected', 'producer', 'producer_profiles', $producer->getId()->toRfc4122(),
            ['verificationStatus' => $previousStatus->value], ['verificationStatus' => VerificationStatus::Rejected->value]
        );
        $em->flush();

        $this->addFlash('success', 'Profil refusé.');

        return $this->redirectToIndex();
    }

    #[AdminRoute(path: '/{entityId}/request-more-info', name: 'request_more_info', options: ['methods' => ['POST']])]
    public function requestMoreInfo(AdminContext $context, EntityManagerInterface $em, NotificationService $notificationService): Response
    {
        $this->assertPostWithToken($context);
        $producer = $context->getEntity()->getInstance();
        if (VerificationStatus::Pending !== $producer->getVerificationStatus()) {
            throw new AccessDeniedHttpException('Un complément ne se demande que pour un profil en attente.');
        }
        $notificationService->notify($producer->getOwner(), 'profile_more_info_needed', 'Complément requis', 'Merci de compléter votre profil producteur (documents ou informations manquantes) pour finaliser la vérification.');
        $this->auditLogger->log('producer_more_info_requested', 'producer', 'producer_profiles', $producer->getId()->toRfc4122());
        $em->flush();

        $this->addFlash('success', 'Demande de complément envoyée.');

        return $this->redirectToIndex();
    }
}