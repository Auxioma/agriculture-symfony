<?php

/**
 * Cycle de vie de ProducerReply::$status (réponse d'un producteur à une demande client). Sent est posé par
 * ProducerRequestController::replyToRequest(), Declined par son pendant declineRequest() -- Draft, Seen,
 * Accepted et Archived existent dans le schéma mais ne sont posés par aucune route actuelle.
 */

namespace App\Enum;

enum ReplyStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Seen = 'seen';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Archived = 'archived';
}
