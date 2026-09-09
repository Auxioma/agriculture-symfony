<?php

namespace App\Dto\Messaging;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SendMessageRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 5000)]
        public string $content,
    ) {
    }
}
