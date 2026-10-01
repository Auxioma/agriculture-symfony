<?php

namespace App\Dto\Producer;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par PUT /api/producer/labels/{labelId}, désérialisé et validé automatiquement
 * par #[MapRequestPayload] dans ProducerVerificationController::attachLabelDocument() -- attache
 * (ou remplace) le document justificatif d'un label déjà revendiqué.
 */

final readonly class AttachLabelDocumentRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $documentId,
    ) {
    }
}
