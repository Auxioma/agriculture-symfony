<?php

namespace App\Dto\Subscription;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par POST /api/subscription/checkout (premier abonnement -- FakePaymentGateway en test,
 * voir config/services.yaml, bloc when@test). Structurellement identique à ChangePlanRequest (même champ
 * unique planPriceId) mais gardé séparé : les deux routes couvrent des cas métier différents (souscription
 * initiale vs changement d'un abonnement existant) qui pourraient diverger plus tard.
 */
final readonly class CheckoutRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $planPriceId,
    ) {
    }
}