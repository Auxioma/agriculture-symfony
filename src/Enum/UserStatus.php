<?php

/**
 * Statut de compte de User::$status. Active par défaut à l'inscription. Suspended est posé par
 * MessageCrudController::blockSender() (modération d'un message signalé) ou directement via le formulaire
 * d'édition d'UserCrudController. Deleted est posé par la fonction SQL identity.anonymize_user(), utilisée
 * aussi bien par l'auto-suppression RGPD (AuthController) que par UserCrudController::anonymizeUser() côté
 * admin. Pending existe dans le schéma mais n'est posé par aucune route actuelle.
 */

namespace App\Enum;

enum UserStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Deleted = 'deleted';
}
