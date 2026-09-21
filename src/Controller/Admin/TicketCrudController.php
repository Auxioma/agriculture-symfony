<?php

namespace App\Controller\Admin;

use App\Entity\Support\Ticket;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Module "Support" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf :
 * "Tickets, priorités, assignation, modèles de réponse, pièces jointes").
 *
 * "Modèles de réponse" : voir SupportReplyTemplateCrudController (module séparé) -- distinct de
 * QuickReply, qui appartient à un producteur précis pour sa messagerie client.
 *
 * Ticket.status/priority sont de simples chaînes (pas d'enum en base) : les choix ci-dessous sont posés au
 * niveau du formulaire seulement, aucune migration nécessaire.
 */

class TicketCrudController extends AbstractCrudController
{
    use StatusBadgeFieldTrait;

    private const STATUSES = ['Ouvert' => 'open', 'En cours' => 'in_progress', 'Résolu' => 'resolved', 'Fermé' => 'closed'];
    // * Niveaux de gravité repris du cahier DevOps ("Support et astreinte").
    private const PRIORITIES = ['Basse' => 'basse', 'Moyenne' => 'moyenne', 'Haute' => 'haute', 'Critique' => 'critique'];

    public static function getEntityFqcn(): string
    {
        return Ticket::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Ticket')
            ->setEntityLabelInPlural('Tickets')
            ->setPageTitle(Crud::PAGE_INDEX, 'Support')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    // * idUser visible seulement à la création (l'admin ouvre un ticket pour un appel/mail reçu hors plateforme,
    // * cf. docblock de classe) : hideWhenUpdating au lieu de hideOnForm, sinon user_id (NOT NULL) ne peut
    // * jamais être renseigné et la création échoue. Une fois le ticket créé, son auteur ne se réassigne pas ;
    // * seule sa prise en charge (assignedTo) reste éditable. choice_label : User n'a pas de __toString().
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->hideOnIndex();
        yield AssociationField::new('idUser')
            ->setLabel('Utilisateur')
            ->formatValue(fn ($v, $e) => $e?->getIdUser()?->getEmail())
            ->setFormTypeOption('choice_label', 'email')
            ->hideWhenUpdating();
        yield TextField::new('subject')->setLabel('Sujet');
        // * Ticket::$status/$priority sont de simples chaînes (pas d'enum) : $enumBacked = false, les choix restent donc
        // * explicites sur les formulaires aussi (voir StatusBadgeFieldTrait).
        yield $this->statusBadgeField('priority', 'Priorité', $pageName, array_flip(self::PRIORITIES), [
            'basse' => 'secondary', 'moyenne' => 'secondary', 'haute' => 'warning', 'critique' => 'danger',
        ], false);
        yield $this->statusBadgeField('status', 'Statut', $pageName, array_flip(self::STATUSES), [
            'open' => 'warning', 'in_progress' => 'warning', 'resolved' => 'success', 'closed' => 'secondary',
        ], false);
        yield AssociationField::new('assignedTo')
            ->setLabel('Assigné à')
            ->formatValue(fn ($v, $e) => $e?->getAssignedTo()?->getEmail())
            ->setFormTypeOption('choice_label', 'email')
            ->hideOnIndex();
        yield DateTimeField::new('createdAt')->setLabel('Ouvert le')->setFormat('d MMM y')->hideOnForm();
        yield DateTimeField::new('closedAt')->setLabel('Fermé le')->hideOnForm()->hideOnIndex();
        yield AssociationField::new('messages')->onlyOnDetail();
    }
}