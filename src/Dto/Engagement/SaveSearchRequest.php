<?php

namespace App\Dto\Engagement;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par POST /api/saved-searches et PUT /api/saved-searches/{id}, désérialisé et
 * validé automatiquement par #[MapRequestPayload] dans SavedSearchController. $criteria reprend
 * librement les mêmes paramètres de filtre que GET /api/producers (productId, categoryId, label,
 * pickupAvailable, deliveryAvailable, latitude/longitude/radiusKm, verifiedOnly) -- non validés ici
 * (stockage libre, comme documenté sur SavedSearch::$criteria).
 */

final readonly class SaveSearchRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        public string $name,

        /** @var array<string, mixed>|null */
        public ?array $criteria = null,

        public bool $notificationsEnabled = false,
    ) {
    }
}
