<?php

namespace App\Service\Notification;

use App\Entity\Identity\User;

/**
 * Réglages d'e-mail d'un utilisateur (écran Paramètres, rubrique Notifications). Ils vivent dans
 * identity.user_preferences.notification_settings (MLD), sous la clé "email" : {type de notification: true|false}.
 * Un interrupteur coupe l'e-mail, pas la notification dans l'application (cahier : MVP = e-mail et in-app, et « préférences
 * email » pour le RGPD) : rien n'est perdu pour quelqu'un qui ouvre l'application.
 */
final class NotificationPreferences
{
    // * les trois notifications producteur que l'écran propose de couper ; toutes les autres partent toujours
    public const EMAIL_TYPES = ['new_relevant_request', 'payment_failed', 'subscription_renewing_soon'];

    /**
     * Chaque type proposé avec son état : actif tant que l'utilisateur n'a pas dit le contraire.
     *
     * @param array<string, mixed>|null $notificationSettings contenu de la colonne notification_settings
     *
     * @return array<string, bool>
     */
    public static function emailSettings(?array $notificationSettings): array
    {
        $stored = \is_array($notificationSettings['email'] ?? null) ? $notificationSettings['email'] : [];
        $settings = [];
        foreach (self::EMAIL_TYPES as $type) {
            $settings[$type] = ($stored[$type] ?? true) !== false;
        }

        return $settings;
    }

    public static function emailEnabled(User $user, string $type): bool
    {
        if (!\in_array($type, self::EMAIL_TYPES, true)) {
            return true;
        }

        return self::emailSettings($user->getPreference()?->getNotificationSettings())[$type];
    }
}
