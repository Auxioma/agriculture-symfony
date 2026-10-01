<?php

namespace App\Dto\Producer;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par POST/PUT /api/producer/delivery-zones(/{id}), désérialisé et validé
 * automatiquement par #[MapRequestPayload] dans ProducerDeliveryZoneController. $polygon est une
 * liste de points [longitude, latitude] (au moins 3, le contrôleur referme l'anneau si besoin) --
 * reprend DeliveryZone::$zone, qui coexiste avec $radiusKm sans obligation de choisir l'un des
 * deux, mais au moins un des deux doit être fourni (vérifié dans le contrôleur, pas ici : le
 * validator ne peut pas exprimer facilement une contrainte "l'un ou l'autre" entre deux propriétés
 * indépendantes).
 */

final readonly class SaveDeliveryZoneRequest
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public ?float $radiusKm = null,

        /** @var array<int, array{0: float, 1: float}>|null */
        #[Assert\Count(min: 3)]
        public ?array $polygon = null,

        /** @var array<string, mixed>|null */
        public ?array $rules = null,
    ) {
    }
}
