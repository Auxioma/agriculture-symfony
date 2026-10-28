<?php

namespace App\Dto\Profile;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de PUT /api/me/password. La confirmation du mot de passe reste côté front. */
final class ChangePasswordRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public readonly string $currentPassword,

        // ! À aligner sur les règles de RegisterClientRequest (même longueur minimale)
        #[Assert\NotBlank]
        #[Assert\Length(min: 8, max: 4096)]
        public readonly string $newPassword,
    ) {
    }
}