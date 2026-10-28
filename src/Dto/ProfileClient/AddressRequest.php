<?php

namespace App\Dto\Profile;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /api/me/addresses et PUT /api/me/addresses/{id}. */
final class AddressRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        public readonly string $label,

        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly string $address1,

        #[Assert\Length(max: 255)]
        public readonly ?string $address2,

        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        public readonly string $city,

        #[Assert\NotBlank]
        #[Assert\Length(max: 20)]
        public readonly string $postalCode,

        // Code pays ISO 3166-1 alpha-2 (FR, BE...)
        #[Assert\NotBlank]
        #[Assert\Country]
        public readonly string $countryCode,

        public readonly bool $isDefault = false,
    ) {
    }
}