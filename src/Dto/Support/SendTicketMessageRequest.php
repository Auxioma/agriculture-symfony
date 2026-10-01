<?php

namespace App\Dto\Support;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par POST /api/support/tickets/{id}/messages -- voir TicketController::sendMessage().
 */

final readonly class SendTicketMessageRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 5000)]
        public string $content,
    ) {
    }
}
