<?php

namespace App\Dto\Messaging;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Signalement d'une conversation.
 *
 * Transporte et valide les données de la requête HTTP envoyées lors
 * de l'action de signalement d'une conversation par un utilisateur.
 *
 * Payload attendu par POST /api/conversations/{id}/report, désérialisé et validé automatiquement par
 * #[MapRequestPayload] dans ConversationController::reportConversation().
 */

final readonly class ReportConversationRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $reason,

        public ?string $message = null,
    ) {
    }
}
