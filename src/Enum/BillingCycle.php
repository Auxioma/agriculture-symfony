<?php

namespace App\Enum;

enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
}