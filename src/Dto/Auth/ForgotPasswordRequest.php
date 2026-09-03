<?php

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par POST /api/auth/forgot-password
 * Le contrôleur répond toujours 200, que l'email existe ou non -- évite qu'on puisse énumérer les
 * comptes existants via ce endpoint.
 */

final readonly class ForgotPasswordRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email,
    ) {
    }
}
