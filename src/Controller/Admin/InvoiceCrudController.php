<?php

namespace App\Controller\Admin;

use App\Entity\Billing\Invoice;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Module "Abonnements" du back-office §13 -- volet "factures". Consultation seule (générées par Stripe).
 */
class InvoiceCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Invoice::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Facture')
            ->setEntityLabelInPlural('Factures')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('subscription')
            ->formatValue(fn ($v, $e) => $e?->getSubscription()?->getProducer()->getFarmName())
            ->hideOnForm();
        yield TextField::new('amount')->hideOnForm();
        yield TextField::new('status')->hideOnForm();
        yield TextField::new('invoiceUrl')->setLabel('Lien facture')->hideOnIndex()->hideOnForm();
        yield DateTimeField::new('paidAt')->hideOnForm();
        yield DateTimeField::new('createdAt')->hideOnForm();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }
}