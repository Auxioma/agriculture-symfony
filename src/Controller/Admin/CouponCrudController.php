<?php

namespace App\Controller\Admin;

use App\Entity\Billing\Coupon;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Module "Abonnements" du back-office (cahier fonctionnel : "Plans, prix, coupons, abonnements actifs,
 * paiements échoués, factures") -- volet "Coupons". providerCouponId référence un Coupon déjà créé dans le
 * Dashboard Stripe (même principe que PlanPriceCrudController::providerPriceId) : ce CRUD ne crée jamais de
 * Coupon Stripe, il se contente de faire le lien.
 */
// * RBAC (cahier DevOps ; cahier fonctionnel 22.2, "Le support accède uniquement aux éléments nécessaires") :
// * pas dans le périmètre nécessaire au support (voir security.yaml pour le détail du mécanisme).
#[IsGranted('ROLE_ADMIN')]
class CouponCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Coupon::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Coupon')
            ->setEntityLabelInPlural('Coupons')
            ->setPageTitle(Crud::PAGE_INDEX, 'Codes promo');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('code');
        yield TextField::new('discountPercent')->setLabel('Réduction (%, informatif)');
        yield TextField::new('providerCouponId')->setLabel('ID coupon Stripe');
        yield DateField::new('validFrom')->hideOnIndex();
        yield DateField::new('validUntil')->hideOnIndex();
        yield IntegerField::new('maxRedemptions')->setLabel('Utilisations max');
    }
}
