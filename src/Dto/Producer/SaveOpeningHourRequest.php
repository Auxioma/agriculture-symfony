<?php

namespace App\Dto\Producer;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par POST/PUT /api/producer/opening-hours(/{id}), désérialisé et validé
 * automatiquement par #[MapRequestPayload] dans ProducerOpeningHourController. $weekday suit la
 * convention ISO-8601 déjà documentée sur OpeningHour (0=lundi). $opensAt/$closesAt sont des
 * heures au format "HH:MM", ignorées si $isClosed est vrai.
 */

final readonly class SaveOpeningHourRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Range(min: 0, max: 6)]
        public int $weekday,

        #[Assert\Regex(pattern: '/^\d{2}:\d{2}$/', message: 'Format attendu : HH:MM.')]
        public ?string $opensAt = null,

        #[Assert\Regex(pattern: '/^\d{2}:\d{2}$/', message: 'Format attendu : HH:MM.')]
        public ?string $closesAt = null,

        public bool $isClosed = false,
    ) {
    }
}
