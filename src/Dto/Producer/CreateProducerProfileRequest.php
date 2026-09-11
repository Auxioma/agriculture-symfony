<?php

namespace App\Dto\Producer;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par POST /api/producer/profile, désérialisé et validé automatiquement par
 * #[MapRequestPayload] dans ProducerProfileController::createMyProfile(). farmName et countryCode
 * couvrent les colonnes NOT NULL de ProducerProfile (owner, farmName, country), même contrainte que
 * RegisterProducerRequest ; les autres champs reprennent ceux d'UpdateProducerProfileRequest pour
 * permettre de compléter le profil en un seul appel.
 */

final readonly class CreateProducerProfileRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $farmName,

        // ! Ne vérifie que le format (2 caractères, comme Country::$code) -- l'existence réelle du pays
        // ! en base se vérifie dans le contrôleur, pas ici (le validator n'a pas accès à la DB).
        #[Assert\NotBlank]
        #[Assert\Length(exactly: 2)]
        public string $countryCode,

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
