<?php

namespace App\Dto\Producer;

use Symfony\Component\Validator\Constraints as Assert;

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