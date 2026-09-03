<?php

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