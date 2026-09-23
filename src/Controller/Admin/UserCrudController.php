<?php

namespace App\Controller\Admin;

use App\Entity\Identity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use App\Service\Audit\AuditLogger;

class UserCrudController extends AbstractCrudController
{
    use StatusBadgeFieldTrait;

    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Utilisateur')
            ->setEntityLabelInPlural('Utilisateurs')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    // * passwordHash n'est volontairement pas listé ici : jamais affiché ni modifiable depuis le back-office.
    public function configureFields(string $pageName): iterable
    {
        // * Libellés, colonnes et badges de statut : maquette Figma "Admin · Utilisateurs" (NOM, EMAIL, RÔLE, INSCRIPTION, STATUT).
        yield IdField::new('id')->hideOnForm()->hideOnIndex();
        yield TextField::new('firstName')->setLabel('Prénom');
        yield TextField::new('lastName')->setLabel('Nom');
        yield EmailField::new('email')->setLabel('Email');
        // * ChoiceField (liste fermée) et non ArrayField (texte libre) : un ArrayField aurait permis à
        // * n'importe quel ROLE_ADMIN de se taper ROLE_SUPER_ADMIN sur sa propre fiche. setPermission() referme
        // * ce risque une fois pour toutes : le champ n'est même pas rendu (donc pas soumissible) pour qui n'a
        // * pas déjà ROLE_SUPER_ADMIN -- un simple ROLE_ADMIN ne peut plus toucher aux rôles de personne, ni les
        // * siens ni ceux d'un tiers (RBAC, cahier DevOps).
        yield ChoiceField::new('roles')
            ->setChoices([
                'Client' => User::ROLE_CLIENT,
                'Producteur' => User::ROLE_PRODUCER,
                'Équipe producteur' => User::ROLE_PRODUCER_TEAM,
                'Support' => User::ROLE_SUPPORT,
                'Administrateur' => User::ROLE_ADMIN,
                'Super administrateur' => User::ROLE_SUPER_ADMIN,
            ])
            ->allowMultipleChoices()
            ->renderExpanded()
            ->setLabel('Rôle')
            ->setPermission('ROLE_SUPER_ADMIN');
        // * ChoiceField (pas TextField) : getStatus()/setStatus() sont typés UserStatus (enum), un TextField
        // * soumettrait une chaîne brute et setStatus(string) ferait planter la sauvegarde (TypeError).
        yield $this->statusBadgeField('status', 'Statut', $pageName, [
            UserStatus::Active->value => 'Actif',
            UserStatus::Pending->value => 'En attente',
            UserStatus::Suspended->value => 'Suspendu',
            UserStatus::Deleted->value => 'Supprimé',
        ], [
            UserStatus::Active->value => 'success',
            UserStatus::Pending->value => 'warning',
            UserStatus::Suspended->value => 'warning',
            UserStatus::Deleted->value => 'secondary',
        ]);
        yield DateTimeField::new('createdAt')->hideOnForm()->setLabel('Inscription')->setFormat('d MMMM y');
        yield DateTimeField::new('lastLoginAt')->hideOnForm()->hideOnIndex()->setLabel('Dernière connexion');
    }

    // * Puces "Tous/Clients/Producteurs/Support/Admins" (layout.html.twig, tm_filter_chips) au lieu du bouton
    // * "+ Filtres" -- voir RoleFilter pour pourquoi $roles (simple_array) a besoin d'un filtre dédié.
    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(RoleFilter::new('roles'));
    }

    // * RBAC (cahier DevOps ; cahier fonctionnel 22.2, "Le support accède uniquement aux éléments nécessaires") :
    // * Support garde INDEX/DETAIL (chercher un utilisateur en traitant un ticket), mais ni "Anonymiser" (RGPD,
    // * irréversible) ni EDIT (statut, blocage de compte) -- des décisions d'admin, pas de simples consultations.
    public function configureActions(Actions $actions): Actions
    {
        $anonymize = Action::new('anonymize', 'Anonymiser')
            ->linkToCrudAction('anonymizeUser')
            ->displayIf(static fn (User $user) => $user->getStatus() !== UserStatus::Deleted);

        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, $anonymize)
            ->add(Crud::PAGE_DETAIL, $anonymize)
            ->setPermission(Action::EDIT, 'ROLE_ADMIN')
            ->setPermission('anonymize', 'ROLE_ADMIN');
    }

    #[AdminRoute(path: '/{entityId}/anonymize', name: 'anonymize')]
    public function anonymizeUser(AdminContext $context, EntityManagerInterface $em): Response
    {
        $user = $context->getEntity()->getInstance();
        $userId = $user->getId()->toRfc4122();
        $em->getConnection()->executeStatement('SELECT identity.anonymize_user(:userId)', ['userId' => $userId]);
        $this->auditLogger->log('user_anonymized', 'identity', 'users', $userId);
        $em->flush();

        $this->addFlash('success', 'Utilisateur anonymisé.');

        return $this->redirect(
            $this->container->get(AdminUrlGenerator::class)
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->unset('entityId')
                ->generateUrl()
        );
    }

    // * Couvre le "blocage compte" (cahier DevOps) même quand il passe par le formulaire générique
    // * (changement de "status" ici) plutôt que par MessageCrudController::blockSender(). getOriginalEntityData()
    // * donne la valeur AVANT le changement en cours -- $entityInstance porte déjà les valeurs du formulaire.
    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if ($entityInstance instanceof User) {
            $previousStatus = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance)['status'] ?? null;
            if ($previousStatus instanceof UserStatus && $previousStatus !== $entityInstance->getStatus()) {
                $this->auditLogger->log(
                    'user_status_changed', 'identity', 'users', $entityInstance->getId()->toRfc4122(),
                    ['status' => $previousStatus->value], ['status' => $entityInstance->getStatus()->value]
                );
            }
        }

        parent::updateEntity($entityManager, $entityInstance);
    }
}