<?php

namespace App\Dto\Messaging;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ReportConversationRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $reason,

        public ?string $message = null,
    ) {
    }
}
