<?php

namespace App\Dto\Messaging;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Envoi d'un message.
 *
 * Transporte et valide les données de la requête HTTP envoyées
 * lors de l'envoi d'un nouveau message dans une conversation.
 *
 * Payload attendu par POST /api/conversations/{id}/messages, désérialisé et validé automatiquement par
 * #[MapRequestPayload] dans ConversationController::sendMessage().
 */

final readonly class SendMessageRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 5000)]
        public string $content,
    ) {
    }
}
