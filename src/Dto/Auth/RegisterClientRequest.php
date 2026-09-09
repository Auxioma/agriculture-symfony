<?php

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par POST /api/auth/register-client 
 * Désérialisé et validé automatiquement par #[MapRequestPayload] dans AuthController, avant même d'entrer
 * dans le corps de la méthode -- aucun parsing ni validation manuelle nécessaire côté contrôleur.
 */

final readonly class RegisterClientRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email,

        // * 8 caractères minimum : aucune règle de complexité précise n'est fixée par les cahiers des charges.
        #[Assert\NotBlank]
        #[Assert\Length(min: 8)]
        public string $password,

        #[Assert\NotBlank]
        public string $firstName,

        #[Assert\NotBlank]
        public string $lastName,
    ) {
    }
}
