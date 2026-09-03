<?php

namespace App\Enum;

enum VerificationStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
}