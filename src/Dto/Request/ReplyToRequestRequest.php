<?php

namespace App\Dto\Request;

final readonly class ReplyToRequestRequest
{
    public function __construct(
        public ?string $replyText = null,
        public ?string $priceAmount = null,
        public ?string $priceUnitId = null,
        public ?string $currencyCode = null,
        public ?\DateTimeImmutable $availabilityDate = null,
        public ?\DateTimeImmutable $validUntil = null,
        public ?string $conditions = null,
    ) {
    }
}