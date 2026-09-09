<?php

namespace App\Dto\Subscription;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par POST /api/subscription/change-plan (upgrade/downgrade d'un abonnement déjà actif).
 * Voir CheckoutRequest pour la souscription initiale -- même champ unique, DTO distinct par cas métier.
 */
final readonly class ChangePlanRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $planPriceId,
    ) {
    }
}