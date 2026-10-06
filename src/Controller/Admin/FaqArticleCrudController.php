<?php

/**
 * Module "FAQ" du back-office : les articles créés ici sont lus par FaqController (GET /api/faq) pour
 * la section "Questions fréquemment posées" de l'accueil Angular. Seuls les articles actifs sont
 * affichés, triés par position.
 */

namespace App\Controller\Admin;

use App\Entity\Content\FaqArticle;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @extends AbstractCrudController<FaqArticle>
 */
#[IsGranted('ROLE_ADMIN')]
class FaqArticleCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return FaqArticle::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Question FAQ')
            ->setEntityLabelInPlural('FAQ')
            ->setDefaultSort(['position' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->hideOnIndex();
        yield TextField::new('question')->setLabel('Question');
        yield TextareaField::new('answer')->setLabel('Réponse')->hideOnIndex();
        yield ChoiceField::new('category')
            ->setLabel('Catégorie')
            ->setChoices(['Clients' => 'Clients', 'Producteurs' => 'Producteurs', 'Autre' => 'Autre'])
            ->renderExpanded()
            ->setRequired(true)
            ->addCssClass('field-toggle-buttons')
            ->setHelp('Colonne de la page FAQ dans laquelle la question s\'affiche.');
        yield TextField::new('locale')->setLabel('Langue')->setHelp('Code langue ISO, ex. "fr".');
        yield IntegerField::new('position')->setHelp('Ordre d\'affichage, 0 en premier.');
        yield BooleanField::new('isActive')->setLabel('Affichée sur le site');
    }
}
