<?php

namespace App\Controller\Admin;

use App\Entity\Billing\Payment;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Module "Abonnements" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf) -- volet "paiements échoués" (et paiements en général).
 * Consultation seule.
 */
class PaymentCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Payment::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Paiement')
            ->setEntityLabelInPlural('Paiements')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('invoice')
            ->formatValue(fn ($v, $e) => $e?->getInvoice()?->getSubscription()->getProducer()->getFarmName())
            ->hideOnForm();
        yield TextField::new('amount')->hideOnForm();
        yield TextField::new('status')->hideOnForm();
        yield TextField::new('failureReason')->setLabel("Motif d'échec")->hideOnIndex()->hideOnForm();
        yield DateTimeField::new('createdAt')->hideOnForm();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }
}
