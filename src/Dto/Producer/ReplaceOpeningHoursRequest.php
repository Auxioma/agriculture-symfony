<?php

namespace App\Dto\Producer;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par PUT /api/producer/opening-hours : tous les créneaux de la semaine, qui remplacent les anciens. Un jour
 * sans créneau est un jour fermé. $hours n'a volontairement pas de valeur par défaut : un champ oublié ne doit pas vider
 * les horaires (une liste vide, elle, est un choix).
 */
final readonly class ReplaceOpeningHoursRequest
{
    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public function __construct(
        /** @var list<array{weekday: int, opensAt: string, closesAt: string}> */
        #[Assert\Count(max: 28)]
        #[Assert\All([
            new Assert\Collection(fields: [
                'weekday' => [new Assert\Type('int'), new Assert\Range(min: 1, max: 7)],
                'opensAt' => [new Assert\NotBlank(), new Assert\Regex(pattern: self::TIME, message: 'Format attendu : HH:MM.')],
                'closesAt' => [new Assert\NotBlank(), new Assert\Regex(pattern: self::TIME, message: 'Format attendu : HH:MM.')],
            ]),
        ])]
        public array $hours,
    ) {
    }
}
