<?php

namespace App\Dto\Subscription;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChangePlanRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $planPriceId,
    ) {
    }
}