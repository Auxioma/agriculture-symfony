<?php

namespace App\Enum;

enum ReportStatus: string
{
    case Open = 'open';
    case InReview = 'in_review';
    case Resolved = 'resolved';
    case Rejected = 'rejected';
}