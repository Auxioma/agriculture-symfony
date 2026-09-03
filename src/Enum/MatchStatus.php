<?php

namespace App\Enum;

enum MatchStatus: string
{
    case Proposed = 'proposed';
    case Unlocked = 'unlocked';
    case Ignored = 'ignored';
    case Expired = 'expired';
}