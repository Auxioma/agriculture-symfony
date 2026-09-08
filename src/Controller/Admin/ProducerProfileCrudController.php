<?php

namespace App\Controller\Admin;

use App\Entity\Producer\ProducerProfile;
use App\Enum\VerificationStatus;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

class ProducerProfileCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ProducerProfile::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Producteur')
            ->setEntityLabelInPlural('Producteurs')
            ->setDefaultSort(['farmName' => 'ASC']);
    }

    // * verificationStatus n'est PAS éditable ici (hideOnForm) les transitions passent uniquement par les
    // * actions Valider/Refuser/Demander un complément, pour garantir qu'un changement de statut déclenche
    // * toujours la notification associée, un simple dropdown éditable permettrait de le contourner.
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('farmName');
        yield AssociationField::new('owner')
            ->formatValue(fn ($value, $entity) => $entity->getOwner()->getEmail())
            ->hideOnForm();
        yield AssociationField::new('country')->hideOnForm();
        yield TextField::new('city')->hideOnForm();
        yield ChoiceField::new('verificationStatus')->hideOnForm();
        yield BooleanField::new('isActive')->hideOnForm();
        yield AssociationField::new('labels')->onlyOnDetail();
        yield AssociationField::new('verificationDocuments')->onlyOnDetail();
    }

    public function configureActions(Actions $actions): Actions
    {
        $validate = Action::new('validate', 'Valider')
            ->linkToCrudAction('validateProducer')
            ->displayIf(fn (ProducerProfile $p) => $p->getVerificationStatus() !== VerificationStatus::Verified);

        $reject = Action::new('reject', 'Refuser')
            ->linkToCrudAction('rejectProducer')
            ->displayIf(fn (ProducerProfile $p) => $p->getVerificationStatus() !== VerificationStatus::Rejected);

        $requestMoreInfo = Action::new('requestMoreInfo', 'Demander un complément')
            ->linkToCrudAction('requestMoreInfo')
            ->displayIf(fn (ProducerProfile $p) => $p->getVerificationStatus() === VerificationStatus::Pending);

        return $actions
            ->disable(Action::NEW, Action::DELETE)
            ->add(Crud::PAGE_INDEX, $validate)->add(Crud::PAGE_DETAIL, $validate)
            ->add(Crud::PAGE_INDEX, $reject)->add(Crud::PAGE_DETAIL, $reject)
            ->add(Crud::PAGE_DETAIL, $requestMoreInfo);
    }

    #[AdminRoute(path: '/{entityId}/validate', name: 'validate')]
    public function validateProducer(AdminContext $context, EntityManagerInterface $em, NotificationService $notificationService): Response
    {
        $producer = $context->getEntity()->getInstance();
        $producer->setVerificationStatus(VerificationStatus::Verified);
        $notificationService->notify($producer->getOwner(), 'profile_validated', 'Profil validé', 'Votre profil producteur a été validé par notre équipe.');
        $em->flush();

        $this->addFlash('success', 'Profil validé.');

        return $this->redirectToIndex();
    }

    #[AdminRoute(path: '/{entityId}/reject', name: 'reject')]
    public function rejectProducer(AdminContext $context, EntityManagerInterface $em, NotificationService $notificationService): Response
    {
        $producer = $context->getEntity()->getInstance();
        $producer->setVerificationStatus(VerificationStatus::Rejected);
        $notificationService->notify($producer->getOwner(), 'profile_rejected', 'Profil non validé', "Votre profil producteur n'a pas été validé. Contactez le support pour plus d'informations.");
        $em->flush();

        $this->addFlash('success', 'Profil refusé.');

        return $this->redirectToIndex();
    }

    #[AdminRoute(path: '/{entityId}/request-more-info', name: 'request_more_info')]
    public function requestMoreInfo(AdminContext $context, EntityManagerInterface $em, NotificationService $notificationService): Response
    {
        $producer = $context->getEntity()->getInstance();
        $notificationService->notify($producer->getOwner(), 'profile_more_info_needed', 'Complément requis', 'Merci de compléter votre profil producteur (documents ou informations manquantes) pour finaliser la vérification.');
        $em->flush();

        $this->addFlash('success', 'Demande de complément envoyée.');

        return $this->redirectToIndex();
    }

    private function redirectToIndex(): Response
    {
        // ! setAction(INDEX) + unset('entityId') obligatoires : sans ça, AdminUrlGenerator réutilise les
        // ! paramètres de la requête en cours (action=validate, entityId=<cible>) et l'URL générée repointe
        // ! vers cette même action -- boucle de redirection infinie, déjà vécue sur UserCrudController::anonymizeUser().
        return $this->redirect(
            $this->container->get(AdminUrlGenerator::class)
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->unset('entityId')
                ->generateUrl()
        );
    }
}