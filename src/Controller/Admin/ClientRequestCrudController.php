<?php

namespace App\Controller\Admin;

use App\Entity\Matching\ClientRequest;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

class ClientRequestCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ClientRequest::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Demande')
            ->setEntityLabelInPlural('Demandes')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    // * client/category/product en hideOnForm : une demande appartient à son auteur, seul le statut est
    // * piloté par l'admin. Pas d'action dédiée comme pour la validation producteur : archivage, spam/doublons
    // * (annulation) et signalement sont juste des valeurs de l'enum RequestStatus, un ChoiceField suffit.
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('client')
            ->formatValue(fn ($value, $entity) => $entity?->getClient()?->getEmail())
            ->hideOnForm();
        yield AssociationField::new('category')->hideOnForm();
        yield AssociationField::new('product')->hideOnForm();
        yield TextField::new('customProduct')->hideOnForm();
        yield ChoiceField::new('needType')->hideOnForm();
        yield ChoiceField::new('status');
        yield AssociationField::new('country')->hideOnForm();
        yield TextField::new('city')->hideOnForm();
        yield TextareaField::new('message')->hideOnIndex();
        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('expiresAt')->hideOnForm();
    }

    public function configureFilters(Filters $filters): Filters
    {
        // * EntityFilter explicite + choice_label obligatoire pour category/country : ni Category ni Country
        // * n'ont de __toString(), et le <select> du filtre (Symfony EntityType, non-autocomplete par défaut)
        // * plante sinon en tentant de caster l'entité en chaîne pour l'option affichée -- même mécanisme
        // * que sur les AssociationField de formulaire (CategoryCrudController::parent, etc.).
        return $filters
            ->add('status')
            ->add('needType')
            ->add(EntityFilter::new('category')->setFormTypeOption('value_type_options.choice_label', 'name'))
            ->add(EntityFilter::new('country')->setFormTypeOption('value_type_options.choice_label', 'name'));
    }

    public function configureActions(Actions $actions): Actions
    {
        // * DELETE reste actif ici (contrairement à Utilisateurs/Producteurs) : "suppression" est
        // * explicitement listée dans le CDC pour ce module (nettoyage spam/doublons). Seule la création est
        // * bloquée -- une demande naît du tunnel client, jamais du back-office.
        return $actions->disable(Action::NEW);
    }
}