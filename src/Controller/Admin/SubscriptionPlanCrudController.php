<?php

namespace App\Controller\Admin;

use App\Entity\Billing\SubscriptionPlan;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Module "Abonnements" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf :
 * "Plans, prix, coupons, abonnements actifs, paiements échoués, factures") -- volet "Plans".
 * Voir CouponCrudController pour le volet "Coupons".
 *
 * * limits/features (JSON) volontairement hors formulaire : configuration fine sans écran dédié pour
 * * l'instant, reste modifiable via l'API/seed.
 */
// * RBAC (cahier DevOps ; cahier fonctionnel 22.2, "Le support accède uniquement aux éléments nécessaires") :
// * pas dans le périmètre nécessaire au support (voir security.yaml pour le détail du mécanisme).
#[IsGranted('ROLE_ADMIN')]
class SubscriptionPlanCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SubscriptionPlan::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Plan')
            ->setEntityLabelInPlural('Plans')
            ->setDefaultSort(['position' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('code');
        yield TextField::new('name');
        yield TextareaField::new('description')->hideOnIndex();
        yield IntegerField::new('position');
        yield BooleanField::new('isActive');
    }
}