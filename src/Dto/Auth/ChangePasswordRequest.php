<?php

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par PUT /api/me/password. Le nouveau mot de passe suit la règle de l'inscription (12 caractères au moins) ;
 * la confirmation « tapez-le deux fois » se vérifie dans le formulaire, elle n'a pas de sens pour l'API.
 */
final readonly class ChangePasswordRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Le mot de passe actuel est obligatoire.')]
        public string $currentPassword,

        #[Assert\NotBlank(message: 'Le nouveau mot de passe est obligatoire.')]
        #[Assert\Length(min: 12, minMessage: 'Le nouveau mot de passe doit faire au moins {{ limit }} caractères.')]
        public string $newPassword,
    ) {
    }
}
