<?php

/**
 * Statut de Subscription::$status, entièrement piloté par les webhooks Stripe (StripeWebhookController) --
 * jamais modifié à la main depuis le back-office (SubscriptionCrudController est en lecture seule).
 * Active/Cancelled reflètent les événements Stripe correspondants ; Trialing, PastDue et Expired existent
 * dans le schéma mais ne sont posés par aucun webhook actuellement géré.
 */

namespace App\Enum;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
