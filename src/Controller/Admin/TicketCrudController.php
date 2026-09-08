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
 * "Modèles de réponse" absent de ce round : aucune entité ne les modélise côté admin -- QuickReply existe
 * déjà mais appartient à un producteur précis pour sa messagerie client (cahier fonctionnel), pas un modèle générique de
 * support. Nécessiterait une nouvelle entité, hors périmètre d'un round back-office seul.
 *
 * Ticket.status/priority sont de simples chaînes (pas d'enum en base) : les choix ci-dessous sont posés au
 * niveau du formulaire seulement, aucune migration nécessaire.
 */

class TicketCrudController extends AbstractCrudController
{
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
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    // * idUser visible seulement à la création (l'admin ouvre un ticket pour un appel/mail reçu hors plateforme,
    // * cf. docblock de classe) : hideWhenUpdating au lieu de hideOnForm, sinon user_id (NOT NULL) ne peut
    // * jamais être renseigné et la création échoue. Une fois le ticket créé, son auteur ne se réassigne pas ;
    // * seule sa prise en charge (assignedTo) reste éditable. choice_label : User n'a pas de __toString().
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('idUser')
            ->setLabel('Utilisateur')
            ->formatValue(fn ($v, $e) => $e?->getIdUser()?->getEmail())
            ->setFormTypeOption('choice_label', 'email')
            ->hideWhenUpdating();
        yield TextField::new('subject')->setLabel('Sujet');
        yield ChoiceField::new('status')->setLabel('Statut')->setChoices(self::STATUSES);
        yield ChoiceField::new('priority')->setLabel('Priorité')->setChoices(self::PRIORITIES);
        yield AssociationField::new('assignedTo')
            ->setLabel('Assigné à')
            ->formatValue(fn ($v, $e) => $e?->getAssignedTo()?->getEmail())
            ->setFormTypeOption('choice_label', 'email');
        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('closedAt')->hideOnForm();
        yield AssociationField::new('messages')->onlyOnDetail();
    }
}