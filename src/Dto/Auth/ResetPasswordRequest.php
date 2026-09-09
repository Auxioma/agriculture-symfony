<?php

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par POST /api/auth/reset-password 
 */

final readonly class ResetPasswordRequest
{
    public function __construct(
        // * Chaîne aléatoire opaque générée côté serveur (forgotPassword) pas de contrainte de format ici,
        // * sa validité réelle (hash correspondant, non expiré, non déjà utilisé) se vérifie dans le contrôleur
        #[Assert\NotBlank]
        public string $token,

        #[Assert\NotBlank]
        #[Assert\Length(min: 8)]
        public string $newPassword,
    ) {
    }
}
