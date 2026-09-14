<?php

namespace App\Dto\Producer;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par POST /api/producer/products
 *
 * ! productId n'est vérifié que sur le format ici (non vide) -- son existence réelle dans le catalogue
 * ! (Product) se vérifie dans le contrôleur, pas dans ce DTO (le validator n'a pas accès à la DB).
 */
final readonly class CreateProducerProductRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $productId,

        public ?string $variety = null,
        public ?string $description = null,
        public ?string $estimatedVolume = null,
        public ?string $defaultPrice = null,
        public ?string $currencyCode = null,
        public bool $isActive = true,
    ) {
    }
}