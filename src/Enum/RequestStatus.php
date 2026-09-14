<?php

/**
 * Cycle de vie de ClientRequest::$status (cahier fonctionnel, tunnel de demande). Sent posé à la création
 * (ClientRequestController::createRequest()) ; ConversationOpen posé dès le premier vrai message échangé
 * (ConversationController::sendMessage()/uploadAttachment(), uniquement depuis un statut encore actif,
 * jamais depuis un statut terminal) ; Cancelled/Archived posés par le client via les actions dédiées.
 * Reported n'est PAS posé par le signalement d'une conversation -- ça, c'est ConversationStatus::Reported,
 * sur une entité différente (Conversation). WaitingReplies, RepliesReceived, DealFound, Expired et Reported
 * existent dans le schéma mais ne sont posés par aucune route actuelle.
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
