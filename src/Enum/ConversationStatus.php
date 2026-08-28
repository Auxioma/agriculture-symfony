<?php

namespace App\Enum;

enum ConversationStatus: string
{
    case Open = 'open';
    case Archived = 'archived';
    case Reported = 'reported';
    case Closed = 'closed';
}