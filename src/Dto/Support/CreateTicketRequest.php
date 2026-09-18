<?php

namespace App\Dto\Support;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par POST /api/support/tickets -- voir TicketController::createTicket(). $content est le
 * premier TicketMessage du ticket (posé par $idUser lui-même), pas un champ de Ticket.
 */

final readonly class CreateTicketRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        public string $subject,

        #[Assert\NotBlank]
        #[Assert\Length(max: 5000)]
        public string $content,
    ) {
    }
}
