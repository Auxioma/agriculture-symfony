<?php

/**
 * Copyright(c)2026 TrouveMoi (https://trouvemoi.com)
 *
 * Ce fichier fait partie d’un projet développé par Auxioma Web Agency pour l’entreprise.
 * Tous droits réservés.
 *
 * Ce code source est la propriété exclusive de Auxioma Web Agency et.
 * Toute reproduction, modification, distribution ou utilisation sans autorisation préalable est interdite.
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
