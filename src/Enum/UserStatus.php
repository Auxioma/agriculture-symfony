<?php

namespace App\Enum;

enum UserStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Deleted = 'deleted';
}