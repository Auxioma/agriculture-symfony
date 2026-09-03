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

enum RequestStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case WaitingReplies = 'waiting_replies';
    case RepliesReceived = 'replies_received';
    case ConversationOpen = 'conversation_open';
    case DealFound = 'deal_found';
    case Expired = 'expired';
    case Archived = 'archived';
    case Cancelled = 'cancelled';
    case Reported = 'reported';
}
