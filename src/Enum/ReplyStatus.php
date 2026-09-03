<?php

/**
 * Copyright(c)2026 TrouveMoi (https://trouvemoi.com)
 *
 * Ce fichier fait partie d’un projet développé par Auxioma Web Agency pour l’entreprise.
 * Tous droits réservés.
 *
 * Ce code source est la propriété exclusive de Auxioma Web Agency et.
 * Toute reproduction, modification, distribution ou utilisation sans autorisation préalable est interdite.
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
