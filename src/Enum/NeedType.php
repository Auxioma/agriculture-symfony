<?php

namespace App\Enum;

enum NeedType: string
{
    case OneShot = 'one_shot';
    case PriceRequest = 'price_request';
    case QuoteRequest = 'quote_request';
    case AvailabilityRequest = 'availability_request';
    case Recurring = 'recurring';
    case Professional = 'professional';
}