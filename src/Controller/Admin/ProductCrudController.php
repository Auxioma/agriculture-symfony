<?php

namespace App\Controller\Admin;

use App\Entity\Catalog\Product;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Module "Catégories et produits" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf §13 :
 * "Catégories, sous-catégories, produits, unités, saisons, traductions, SEO").
 *
 * Traductions (productTranslations) volontairement absentes du formulaire : ProductTranslation a une clé
 * primaire composite (product+locale) -- même limitation qu'expliquée sur CategoryCrudController.
 */
class ProductCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Product::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Produit')
            ->setEntityLabelInPlural('Produits')
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        // * choice_label obligatoire sur les deux : ni Category ni Unit n'ont de __toString(), le <select>
        // * du formulaire plante sinon en tentant de caster l'entité en chaîne pour l'option affichée.
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('category')->setFormTypeOption('choice_label', 'name');
        yield TextField::new('name');
        yield TextField::new('slug');
        yield AssociationField::new('defaultUnit')
            ->setLabel('Unité par défaut')
            ->setFormTypeOption('choice_label', static fn ($unit) => $unit->getLabel() ?? $unit->getCode());
        yield IntegerField::new('seasonStartMonth')->setLabel('Début de saison (mois 1-12)')->hideOnIndex();
        yield IntegerField::new('seasonEndMonth')->setLabel('Fin de saison (mois 1-12)')->hideOnIndex();
        yield BooleanField::new('isActive');
        // * productTranslations : voir le docblock de la classe ci-dessus.
    }
}