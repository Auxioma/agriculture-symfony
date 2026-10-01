<?php

namespace App\Dto\Request;

use App\Enum\RecurrenceFrequency;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par POST/PUT /api/client/requests/{id}/recurring-rule -- voir RecurringRequestRuleController.
 */
final readonly class SaveRecurringRuleRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public RecurrenceFrequency $frequency,

        public ?\DateTimeImmutable $endAt = null,
    ) {
    }
}
