<?php

/**
 * Statut de vérification d'un ProducerProfile. Pending à l'inscription (registerProducer), puis Verified
 * ou Rejected posés par les actions "Valider"/"Refuser" de ProducerProfileCrudController -- ce contrôleur
 * n'expose volontairement pas ce champ en édition libre pour garantir qu'un changement de statut déclenche
 * toujours la notification associée. Draft et Suspended existent dans le schéma mais ne sont posés par
 * aucune route actuelle.
 */

namespace App\Enum;

enum VerificationStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
}
