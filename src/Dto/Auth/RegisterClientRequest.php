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

        // * 12 caractères minimum (obligation CNIL en l'absence d'autres mesures de protection du compte,
        // * ex. throttling) -- aucune règle de complexité précise n'est fixée par les cahiers des charges,
        // * qui ne parlent pas du seuil exact.
        #[Assert\NotBlank]
        #[Assert\Length(min: 12)]
        public string $password,

        #[Assert\NotBlank]
        public string $firstName,

        #[Assert\NotBlank]
        public string $lastName,
    ) {
    }
}
