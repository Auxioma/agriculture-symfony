<?php

namespace App\Dto\Trust;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par POST /api/reviews/{id}/response, désérialisé et validé automatiquement par
 * #[MapRequestPayload] dans ReviewController::respondToReview().
 */

final readonly class RespondToReviewRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 5000)]
        public string $response,
    ) {
    }
}
