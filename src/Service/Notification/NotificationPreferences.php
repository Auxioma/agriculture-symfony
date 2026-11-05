<?php

namespace App\Service\Notification;

use App\Entity\Identity\User;

/**
 * Réglages de notification d'un utilisateur (écran Paramètres, rubrique Notifications). Ils vivent dans
 * identity.user_preferences.notification_settings (MLD), sous la clé "types" : {type de notification: true|false}.
 * Un interrupteur coupé vaut pour les deux canaux du cahier (MVP : e-mail et in-app) : NotificationService::notify() ne crée ni
 * notification dans l'application ni e-mail. Actif tant que l'utilisateur n'a pas dit le contraire.
 */
final class NotificationPreferences
{
    // * les notifications producteur du cahier fonctionnel que le back envoie, dans l'ordre de l'écran Paramètres. Toutes les autres partent
    // * toujours : un refus ou une demande de complément de profil, une invitation d'équipe, une réponse du support, un abonnement en double.
    // * "Réponse client" (cahier) n'y est pas : rien ne l'envoie encore. "Avis publié" y est sous "new_review", envoyée à la création de l'avis.
    public const SWITCHABLE_TYPES = [
        'new_relevant_request',
        'request_expiring_soon',
        'new_message',
        'payment_failed',
        'subscription_renewing_soon',
        'profile_validated',
        'new_review',
    ];

    /**
     * Chaque type proposé avec son état.
     *
     * @param array<string, mixed>|null $notificationSettings contenu de la colonne notification_settings
     *
     * @return array<string, bool>
     */
    public static function settings(?array $notificationSettings): array
    {
        $stored = \is_array($notificationSettings['types'] ?? null) ? $notificationSettings['types'] : [];
        $settings = [];
        foreach (self::SWITCHABLE_TYPES as $type) {
            $settings[$type] = ($stored[$type] ?? true) !== false;
        }

        return $settings;
    }

    public static function enabled(User $user, string $type): bool
    {
        if (!\in_array($type, self::SWITCHABLE_TYPES, true)) {
            return true;
        }

        return self::settings($user->getPreference()?->getNotificationSettings())[$type];
    }
}
