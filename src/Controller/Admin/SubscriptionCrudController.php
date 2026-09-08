<?php

namespace App\Controller\Admin;

use App\Entity\Billing\Subscription;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;

/**
 * Module "Abonnements" du back-office §13 -- volet "abonnements actifs". Consultation seule : le statut
 * change via les webhooks Stripe déjà branchés (StripeWebhookController), jamais à la main ici.
 */
class SubscriptionCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Subscription::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Abonnement')
            ->setEntityLabelInPlural('Abonnements')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('producer')->formatValue(fn ($v, $e) => $e?->getProducer()?->getFarmName())->hideOnForm();
        yield AssociationField::new('planPrice')
            ->formatValue(fn ($v, $e) => $e?->getPlanPrice()?->getPlan()->getName())
            ->hideOnForm();
        yield ChoiceField::new('status')->hideOnForm();
        yield DateTimeField::new('currentPeriodStart')->hideOnForm();
        yield DateTimeField::new('currentPeriodEnd')->hideOnForm();
        yield BooleanField::new('cancelAtPeriodEnd')->hideOnForm();
        yield AssociationField::new('invoices')->onlyOnDetail();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }
}