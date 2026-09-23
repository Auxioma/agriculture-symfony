<?php

namespace App\Controller\Admin;

use App\Entity\Catalog\Unit;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Module "Catégories et produits" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf :
 * "Catégories, sous-catégories, produits, unités, saisons, traductions, SEO") -- volet "unités".
 */
// * RBAC (cahier DevOps ; cahier fonctionnel 22.2, "Le support accède uniquement aux éléments nécessaires") :
// * pas dans le périmètre nécessaire au support (voir security.yaml pour le détail du mécanisme).
#[IsGranted('ROLE_ADMIN')]
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