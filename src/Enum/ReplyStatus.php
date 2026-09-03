<?php

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