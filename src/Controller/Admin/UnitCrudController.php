<?php

namespace App\Controller\Admin;

use App\Entity\Catalog\Unit;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Module "Catégories et produits" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf §13 :
 * "Catégories, sous-catégories, produits, unités, saisons, traductions, SEO") -- volet "unités".
 */
class UnitCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Unit::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('code');
        yield TextField::new('label');
        yield TextField::new('unitType')->setLabel('Type');
    }
}