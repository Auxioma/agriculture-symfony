<?php

namespace App\Dto\Auth;

use App\Service\Notification\NotificationPreferences;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Payload attendu par PUT /api/me/preferences. Chaque champ est facultatif : un champ absent (null) n'est pas touché, ce qui
 * laisse chaque rubrique de l'écran Paramètres s'enregistrer seule. Langues et devises : celles du cahier fonctionnel
 * (internationalisation) ; $emailNotifications : {type de notification: true|false} parmi NotificationPreferences::EMAIL_TYPES.
 */
final readonly class UpdatePreferencesRequest
{
    public function __construct(
        #[Assert\Choice(choices: ['fr', 'en', 'es', 'it', 'de'], message: 'Langue inconnue.')]
        public ?string $locale = null,

        #[Assert\Choice(choices: ['EUR', 'USD', 'GBP', 'CHF'], message: 'Devise inconnue.')]
        public ?string $currency = null,

        /** @var array<string, bool>|null */
        #[Assert\All([new Assert\Type('bool', message: 'Une notification est activée (true) ou non (false).')])]
        public ?array $emailNotifications = null,
    ) {
    }

    #[Assert\Callback]
    public function validateNotificationTypes(ExecutionContextInterface $context): void
    {
        foreach (array_keys($this->emailNotifications ?? []) as $type) {
            if (!\in_array($type, NotificationPreferences::EMAIL_TYPES, true)) {
                $context->buildViolation('Notification inconnue : {{ type }}.')->setParameter('{{ type }}', (string) $type)->atPath('emailNotifications')->addViolation();
            }
        }
    }
}
