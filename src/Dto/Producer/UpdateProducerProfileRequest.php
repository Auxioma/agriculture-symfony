<?php

namespace App\Dto\Producer;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateProducerProfileRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $farmName,

        public ?string $description = null,

        public ?string $story = null,

        public ?string $city = null,

        #[Assert\Length(max: 20)]
        public ?string $postalCode = null,

        #[Assert\Length(max: 120)]
        public ?string $addressVisibility = null,

        #[Assert\Range(min: -90, max: 90)]
        public ?float $latitude = null,

        #[Assert\Range(min: -180, max: 180)]
        public ?float $longitude = null,
    ) {
    }
}