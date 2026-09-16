<?php

namespace App\Dto\Producer;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par POST/PUT /api/producer/labels(/{labelId}), désérialisé et validé
 * automatiquement par #[MapRequestPayload] dans ProducerVerificationController. $documentId est
 * facultatif : ProducerLabel::$document peut être fourni plus tard via PUT (voir le docblock de
 * l'entité, "facultatif tant que non fourni").
 */

final readonly class ClaimLabelRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $labelId,

        #[Assert\Uuid]
        public ?string $documentId = null,
    ) {
    }
}
