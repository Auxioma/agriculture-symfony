<?php

/**
 * Module "Modèles de réponse" du back-office Support (cahier fonctionnel, "Support : Tickets,
 * priorités, assignation, modèles de réponse, pièces jointes"). CRUD simple : contrairement à
 * QuickReply (producteur), ces modèles n'appartiennent à personne en particulier -- toute l'équipe
 * support les gère et s'en sert pour répondre aux tickets (TicketMessage), en copiant le contenu
 * voulu -- pas d'insertion automatique dans le formulaire de réponse pour l'instant (back-office
 * Symfony pur, l'éventuelle UI de ticket côté équipe support vit ailleurs si un jour elle existe).
 */

namespace App\Controller\Admin;

use App\Entity\Support\SupportReplyTemplate;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class SupportReplyTemplateCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SupportReplyTemplate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Modèle de réponse')
            ->setEntityLabelInPlural('Modèles de réponse')
            ->setDefaultSort(['position' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('title')->setLabel('Titre');
        yield TextareaField::new('content')->setLabel('Contenu')->hideOnIndex();
        yield IntegerField::new('position')->setLabel('Ordre d\'affichage');
        yield BooleanField::new('isActive')->setLabel('Actif');
    }
}
