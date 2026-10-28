<?php

namespace App\Dto\Profile;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * PATCH /api/me/preferences : tous les champs sont optionnels (null = "non envoyé, on ne touche pas").
 */
final class UpdatePreferencesRequest
{
    public const LOCALES = ['fr', 'en', 'es', 'it', 'de'];
    public const CURRENCIES = ['EUR', 'USD', 'GBP', 'CHF'];
    public const NOTIFICATION_KEYS = ['producerReply', 'newMessage', 'requestExpiring'];

    public function __construct(
        #[Assert\Choice(choices: self::LOCALES)]
        public readonly ?string $locale = null,

        #[Assert\Choice(choices: self::CURRENCIES)]
        public readonly ?string $currency = null,

        // allowMissingFields : on peut n'envoyer qu'un seul toggle ; extra fields interdits (liste blanche)
        #[Assert\Collection(
            fields: [
                'producerReply' => new Assert\Type('bool'),
                'newMessage' => new Assert\Type('bool'),
                'requestExpiring' => new Assert\Type('bool'),
            ],
            allowMissingFields: true,
        )]
        public readonly ?array $notificationSettings = null,
    ) {
    }
}