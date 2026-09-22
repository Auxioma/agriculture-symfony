<?php

namespace App\Controller\Admin;

use App\Entity\Matching\ClientRequest;
use App\Enum\RequestStatus;
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
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class ClientRequestCrudController extends AbstractCrudController
{
    use StatusBadgeFieldTrait;

    public static function getEntityFqcn(): string
    {
        return ClientRequest::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Demande')
            ->setEntityLabelInPlural('Demandes')
            ->setPageTitle(Crud::PAGE_INDEX, 'Demandes clients')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    // * client/category/product en hideOnForm : une demande appartient à son auteur, seul le statut est
    // * piloté par l'admin. Pas d'action dédiée comme pour la validation producteur : archivage, spam/doublons
    // * (annulation) et signalement sont juste des valeurs de l'enum RequestStatus, un ChoiceField suffit.
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->hideOnIndex();
        yield AssociationField::new('client')
            ->setLabel('Client')
            ->formatValue(fn ($value, $entity) => $entity?->getClient()?->getEmail())
            ->hideOnForm();
        yield AssociationField::new('category')->setLabel('Catégorie')->hideOnForm();
        yield AssociationField::new('product')->setLabel('Produit')->hideOnForm();
        yield TextField::new('customProduct')->setLabel('Produit libre')->hideOnForm()->hideOnIndex();
        yield ChoiceField::new('needType')->setLabel('Type de besoin')->hideOnForm()->hideOnIndex();
        yield $this->statusBadgeField('status', 'Statut', $pageName, [
            RequestStatus::Draft->value => 'Brouillon',
            RequestStatus::Sent->value => 'Envoyée',
            RequestStatus::WaitingReplies->value => 'En attente de réponses',
            RequestStatus::RepliesReceived->value => 'Réponses reçues',
            RequestStatus::ConversationOpen->value => 'Conversation ouverte',
            RequestStatus::DealFound->value => 'Accord trouvé',
            RequestStatus::Expired->value => 'Expirée',
            RequestStatus::Archived->value => 'Archivée',
            RequestStatus::Cancelled->value => 'Annulée',
            RequestStatus::Reported->value => 'Signalée',
        ], [
            RequestStatus::Draft->value => 'secondary',
            RequestStatus::Sent->value => 'info',
            RequestStatus::WaitingReplies->value => 'warning',
            RequestStatus::RepliesReceived->value => 'success',
            RequestStatus::ConversationOpen->value => 'success',
            RequestStatus::DealFound->value => 'success',
            RequestStatus::Expired->value => 'secondary',
            RequestStatus::Archived->value => 'secondary',
            RequestStatus::Cancelled->value => 'secondary',
            RequestStatus::Reported->value => 'danger',
        ]);
        yield AssociationField::new('country')->setLabel('Pays')->hideOnForm()->hideOnIndex();
        yield TextField::new('city')->setLabel('Ville')->hideOnForm();
        yield TextareaField::new('message')->setLabel('Message')->hideOnIndex();
        yield DateTimeField::new('createdAt')->setLabel('Date')->setFormat('d MMM y')->hideOnForm();
        yield DateTimeField::new('expiresAt')->setLabel('Expire le')->hideOnForm()->hideOnIndex();
    }

    public function configureFilters(Filters $filters): Filters
    {
        // * EntityFilter explicite + choice_label obligatoire pour category/country : ni Category ni Country
        // * n'ont de __toString(), et le <select> du filtre (Symfony EntityType, non-autocomplete par défaut)
        // * plante sinon en tentant de caster l'entité en chaîne pour l'option affichée -- même mécanisme
        // * que sur les AssociationField de formulaire (CategoryCrudController::parent, etc.).
        // * "status" reste accessible ici aussi via le bouton "+ Filtres" , le cahier fonctionnel demande plusieurs filtres sur les demandes ("Liste,
        // * filtres, statut, doublons, spam..."), pas seulement le statut. TextFilter + setFormType(TextType::class) :
        // * voir le commentaire équivalent sur ProducerProfileCrudController::configureFilters() -- valeur
        // * soumise gardée plate pour que les puces "Toutes/Envoyées/En attente/..." (tm_filter_chips) et le
        // * "+ Filtres" pilotent le même paramètre de requête.
        return $filters
            ->add(TextFilter::new('status')->setFormType(TextType::class))
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