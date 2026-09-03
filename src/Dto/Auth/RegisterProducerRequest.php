<?php

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par POST /api/auth/register-producer
 * En plus des champs communs au client, farmName et countryCode couvrent les colonnes NOT NULL de
 * ProducerProfile (owner, farmName, country) : User et ProducerProfile doivent être créés dans la même
 * opération côté contrôleur.
 */

final readonly class RegisterProducerRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email,

        #[Assert\NotBlank]
        #[Assert\Length(min: 8)]
        public string $password,

        #[Assert\NotBlank]
        public string $firstName,

        #[Assert\NotBlank]
        public string $lastName,

        #[Assert\NotBlank]
        public string $farmName,

        // ! Ne vérifie que le format (2 caractères, comme Country::$code) -- l'existence réelle du pays
        // ! en base se vérifie dans le contrôleur, pas ici (le validator n'a pas accès à la DB)
        #[Assert\NotBlank]
        #[Assert\Length(exactly: 2)]
        public string $countryCode,
    ) {
    }
}
