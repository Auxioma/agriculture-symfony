<?php

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par DELETE /api/me. Le mot de passe confirme que c'est bien la personne (la suppression est irréversible).
 * $acceptSubscriptionCancellation : « J'accepte » de la carte affichée au producteur qui a un abonnement en cours, dont
 * la résiliation immédiate (sans remboursement) fait partie de la suppression.
 */
final readonly class DeleteAccountRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Le mot de passe est obligatoire.')]
        public string $password,

        public bool $acceptSubscriptionCancellation = false,
    ) {
    }
}
