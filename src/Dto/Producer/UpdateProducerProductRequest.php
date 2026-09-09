<?php

namespace App\Dto\Producer;

final readonly class UpdateProducerProductRequest
{
    public function __construct(
        public ?string $variety = null,
        public ?string $description = null,
        public ?string $estimatedVolume = null,
        public ?string $defaultPrice = null,
        public ?string $currencyCode = null,
        public bool $isActive = true,
    ) {
    }
}