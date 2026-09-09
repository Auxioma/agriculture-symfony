<?php

/**
 * Nature de la demande d'un client (ClientRequest::$needType, champ requis dès la création via
 * POST /api/requests) : prix indicatif, devis formel, disponibilité, achat ponctuel, besoin récurrent ou
 * demande d'un client professionnel.
 */

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
