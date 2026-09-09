<?php

/**
 * Périodicité de facturation d'un PlanPrice (cahier fonctionnel : "l'abonnement mensuel et annuel doivent
 * être disponibles"). Un même SubscriptionPlan peut avoir un PlanPrice Monthly et un PlanPrice Yearly
 * distincts, chacun avec son propre montant.
 */

namespace App\Enum;

enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
}
