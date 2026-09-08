<?php

namespace App\Controller\Admin;

use App\Entity\Billing\PlanPrice;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Module "Abonnements" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf) -- volet "Prix".
 */
class PlanPriceCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PlanPrice::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Prix')
            ->setEntityLabelInPlural('Prix');
    }

    // * choice_label obligatoire sur plan/currency : ni SubscriptionPlan ni Currency n'ont de __toString().
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('plan')->setFormTypeOption('choice_label', 'name');
        yield ChoiceField::new('billingCycle');
        yield TextField::new('amount');
        yield AssociationField::new('currency')->setFormTypeOption('choice_label', 'name');
        yield TextField::new('providerPriceId')->setLabel('ID prix Stripe');
        yield BooleanField::new('isActive');
    }
}