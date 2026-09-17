<?php

namespace App\Dto\Subscription;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par POST /api/subscription/checkout (premier abonnement -- FakePaymentGateway en test,
 * voir config/services.yaml, bloc when@test). Structurellement proche de ChangePlanRequest (même champ
 * planPriceId) mais gardé séparé : les deux routes couvrent des cas métier différents (souscription
 * initiale vs changement d'un abonnement existant) qui pourraient diverger plus tard -- $couponCode
 * (cahier fonctionnel, "coupons abonnement") n'existe d'ailleurs que sur ce DTO-ci, pas sur
 * ChangePlanRequest (voir Coupon pour le raisonnement).
 */
final readonly class CheckoutRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $planPriceId,

        public ?string $couponCode = null,
    ) {
    }
}