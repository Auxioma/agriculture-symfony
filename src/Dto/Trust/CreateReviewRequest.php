<?php

namespace App\Dto\Trust;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par POST /api/reviews, désérialisé et validé automatiquement par
 * #[MapRequestPayload] dans ReviewController::createReview(). requestId/producerId identifient la
 * ClientRequest et le ProducerProfile visés -- rating reprend la contrainte DB
 * chk_reviews_rating (Review).
 */

final readonly class CreateReviewRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $requestId,

        #[Assert\NotBlank]
        public string $producerId,

        #[Assert\NotNull]
        #[Assert\Range(min: 1, max: 5)]
        public int $rating,

        #[Assert\Length(max: 5000)]
        public ?string $comment = null,
    ) {
    }
}
