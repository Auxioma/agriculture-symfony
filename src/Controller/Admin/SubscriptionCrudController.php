<?php

namespace App\Controller\Admin;

use App\Entity\Billing\Subscription;
use App\Enum\BillingCycle;
use App\Enum\SubscriptionStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Module "Abonnements" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf) -- volet "abonnements actifs". Consultation seule : le statut
 * change via les webhooks Stripe déjà branchés (StripeWebhookController), jamais à la main ici.
 */
// * RBAC (cahier DevOps ; cahier fonctionnel 22.2, "Le support accède uniquement aux éléments nécessaires") :
// * pas dans le périmètre nécessaire au support (voir security.yaml pour le détail du mécanisme).
#[IsGranted('ROLE_ADMIN')]
class SubscriptionCrudController extends AbstractCrudController
{
    use StatusBadgeFieldTrait;

    public static function getEntityFqcn(): string
    {
        return Subscription::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Abonnement')
            ->setEntityLabelInPlural('Abonnements')
            ->setPageTitle(Crud::PAGE_INDEX, 'Abonnements producteurs')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        // * Colonnes de la maquette "Admin · Abonnements" : PRODUCTEUR, PLAN, CYCLE, STATUT, PROCHAIN PAIEMENT.
        yield IdField::new('id')->hideOnForm()->hideOnIndex();
        yield AssociationField::new('producer')->setLabel('Producteur')->formatValue(fn ($v, $e) => $e?->getProducer()?->getFarmName())->hideOnForm();
        yield AssociationField::new('planPrice')
            ->setLabel('Plan')
            ->formatValue(fn ($v, $e) => $e?->getPlanPrice()?->getPlan()->getName())
            ->hideOnForm();
        // * ChoiceField et non TextField : le template text.html.twig d'EasyAdmin caste la valeur brute en chaîne
        // * (title="{{ field.value }}"), ce que BillingCycle (un enum) ne supporte pas ; ChoiceField gère les enums.
        yield ChoiceField::new('planPrice.billingCycle')
            ->setLabel('Cycle')
            ->setChoices([
                'Mensuel' => BillingCycle::Monthly->value,
                'Annuel' => BillingCycle::Yearly->value,
            ])
            ->hideOnForm();
        yield $this->statusBadgeField('status', 'Statut', $pageName, [
            SubscriptionStatus::Trialing->value => 'Essai',
            SubscriptionStatus::Active->value => 'Actif',
            SubscriptionStatus::PastDue->value => 'Paiement échoué',
            SubscriptionStatus::Cancelled->value => 'Résilié',
            SubscriptionStatus::Expired->value => 'Expiré',
        ], [
            SubscriptionStatus::Trialing->value => 'info',
            SubscriptionStatus::Active->value => 'success',
            SubscriptionStatus::PastDue->value => 'warning',
            SubscriptionStatus::Cancelled->value => 'secondary',
            SubscriptionStatus::Expired->value => 'secondary',
        ])->hideOnForm();
        yield DateTimeField::new('currentPeriodStart')->setLabel('Début de période')->hideOnForm()->hideOnIndex();
        yield DateTimeField::new('currentPeriodEnd')->setLabel('Prochain paiement')->setFormat('d MMM y')->hideOnForm();
        yield BooleanField::new('cancelAtPeriodEnd')->setLabel('Résiliation programmée')->hideOnForm()->hideOnIndex();
        yield AssociationField::new('invoices')->setLabel('Factures')->onlyOnDetail();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }
}