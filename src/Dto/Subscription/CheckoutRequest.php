<?php

namespace App\Dto\Subscription;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CheckoutRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $planPriceId,
    ) {
    }
}