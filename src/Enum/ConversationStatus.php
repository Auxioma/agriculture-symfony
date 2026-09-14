<?php

/**
 * Statut de Conversation::$status. Open est posé implicitement à la première réponse d'un producteur
 * (voir ConversationController) ; Reported par l'utilisateur qui signale (POST .../report) et fait
 * apparaître la conversation dans le back-office (ConversationCrudController, filtrée sur ce statut) ;
 * Closed/Open à nouveau y sont reposés par l'admin via les actions "Clôturer"/"Rouvrir". Archived existe
 * dans le schéma mais n'est posé par aucune route actuelle.
 */

namespace App\Enum;

enum ConversationStatus: string
{
    case Open = 'open';
    case Archived = 'archived';
    case Reported = 'reported';
    case Closed = 'closed';
}
