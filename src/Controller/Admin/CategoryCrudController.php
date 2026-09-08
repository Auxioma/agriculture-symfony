<?php

namespace App\Controller\Admin;

use App\Entity\Catalog\Category;
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
 * Traductions (categoryTranslations) volontairement absentes du formulaire : CategoryTranslation a une clé
 * primaire composite (category+locale), qu'EasyAdmin 5.5.1 rejette catégoriquement, y compris utilisée
 * indirectement via un CollectionField. Restent gérables via l'API/seed en attendant une clé de substitution
 * (UUID) sur cette entité, hors périmètre de ce round.
 */
class CategoryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Category::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Catégorie')
            ->setEntityLabelInPlural('Catégories')
            ->setDefaultSort(['position' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        // * choice_label obligatoire : Category n'a pas de __toString(), et le <select> du formulaire
        // * (Symfony EntityType) plante sinon en tentant de caster l'entité en chaîne pour l'option affichée.
        yield AssociationField::new('parent')->setLabel('Catégorie parente')->setFormTypeOption('choice_label', 'name');
        yield TextField::new('name');
        yield TextField::new('slug');
        yield TextField::new('icon')->hideOnIndex();
        yield TextField::new('imageUrl')->hideOnIndex();
        yield IntegerField::new('position');
        yield BooleanField::new('isActive');
        // * categoryTranslations : voir le docblock de la classe ci-dessus.
    }
}