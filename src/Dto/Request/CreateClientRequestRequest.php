<?php

namespace App\Dto\Request;

use App\Enum\NeedType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par POST /api/requests (création) et PUT /api/client/requests/{id} (mise à jour complète,
 * mêmes champs -- voir ClientRequestController::applyRequestData(), partagée par les deux routes).
 *
 * ! categoryId/productId/customProduct sont chacun individuellement optionnels ici : la règle réelle
 * ! ("product ou customProduct est requis", categoryId seul ne suffit jamais) n'est pas un attribut de
 * ! validation -- elle reflète la contrainte SQL chk_client_requests_product_or_custom et n'est vérifiée
 * ! qu'à la main dans applyRequestData(), pas au niveau du DTO.
 */
final readonly class CreateClientRequestRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public NeedType $needType,

        public ?string $categoryId = null,
        public ?string $productId = null,
        public ?string $customProduct = null,

        public ?string $quantity = null,
        public ?string $unitId = null,

        public ?string $budgetMin = null,
        public ?string $budgetMax = null,
        public ?string $currencyCode = null,

        public ?\DateTimeImmutable $desiredDate = null,
        public int $urgencyLevel = 0,

        public ?string $countryCode = null,
        public ?string $city = null,
        public ?string $postalCode = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $radiusKm = null,

        public bool $pickupWanted = false,
        public bool $deliveryWanted = false,

        #[Assert\Length(max: 2000)]
        public ?string $message = null,
    ) {
    }
}
