<?php

namespace App\Controller\Admin;

use App\Entity\Identity\User;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

class UserCrudController extends AbstractCrudController
{
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
        yield IdField::new('id')->hideOnForm();
        yield EmailField::new('email');
        yield TextField::new('firstName');
        yield TextField::new('lastName');
        // * ChoiceField (liste fermée) et non ArrayField (texte libre) : un ArrayField aurait permis à
        // * n'importe quel ROLE_ADMIN de se taper ROLE_SUPER_ADMIN sur sa propre fiche (aucune role_hierarchy
        // * n'existe dans security.yaml pour s'en prémunir autrement).
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
            ->renderExpanded();
        // * ChoiceField (pas TextField) : getStatus()/setStatus() sont typés UserStatus (enum), un TextField
        // * soumettrait une chaîne brute et setStatus(string) ferait planter la sauvegarde (TypeError).
        yield ChoiceField::new('status');
        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('lastLoginAt')->hideOnForm();
    }

    public function configureActions(Actions $actions): Actions
    {
        $anonymize = Action::new('anonymize', 'Anonymiser')
            ->linkToCrudAction('anonymizeUser')
            ->displayIf(static fn (User $user) => $user->getStatus() !== UserStatus::Deleted);

        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, $anonymize)
            ->add(Crud::PAGE_DETAIL, $anonymize);
    }

    #[AdminRoute(path: '/{entityId}/anonymize', name: 'anonymize')]
    public function anonymizeUser(AdminContext $context, EntityManagerInterface $em): Response
    {
        $user = $context->getEntity()->getInstance();
        $em->getConnection()->executeStatement('SELECT identity.anonymize_user(:userId)', ['userId' => $user->getId()->toRfc4122()]);

        $this->addFlash('success', 'Utilisateur anonymisé.');

        // ! AdminUrlGenerator part des paramètres de la requête EN COURS (action=anonymizeUser,
        // ! entityId=<cible>) et ne les efface pas juste parce qu'on change le contrôleur -- sans
        // ! setAction(INDEX) explicite, l'URL générée pointe vers cette même action anonymize, ce qui
        // ! redéclenche l'anonymisation puis redirige de nouveau vers elle-même : boucle de redirection
        // ! infinie (repérée par un test qui a fait planter le process PHP en OOM avant de s'en arrêter).
        return $this->redirect(
            $this->container->get(AdminUrlGenerator::class)
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->unset('entityId')
                ->generateUrl()
        );
    }
}