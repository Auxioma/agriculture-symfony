<?php

/**
 * Cadence de RecurringRequestRule::$frequency -- à quel rythme RunRecurringRequestsCommand republie
 * automatiquement la ClientRequest associée (NeedType::Recurring, "besoin récurrent" du cahier fonctionnel).
 */

namespace App\Enum;

enum RecurrenceFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function nextRunAfter(\DateTimeImmutable $from): \DateTimeImmutable
    {
        return match ($this) {
            self::Weekly => $from->modify('+1 week'),
            self::Monthly => $from->modify('+1 month'),
        };
    }
}
