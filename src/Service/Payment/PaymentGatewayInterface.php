<?php

namespace App\Service\Payment;

interface PaymentGatewayInterface
{
    public function createCheckoutSession(string $priceId, string $customerEmail, array $metadata, string $successUrl, string $cancelUrl, ?string $couponId = null): string;

    // * $atPeriodEnd : le nouveau prix ne s'applique qu'à la prochaine échéance (downgrade) ; sinon tout de suite, avec prorata
    public function updateSubscriptionPrice(string $providerSubscriptionId, string $newPriceId, bool $atPeriodEnd = false): void;

    // * l'abonnement reste actif jusqu'à la fin de la période déjà payée
    public function cancelSubscriptionAtPeriodEnd(string $providerSubscriptionId): void;

    // * annule l'annulation programmée : l'abonnement continue et se renouvelle normalement
    public function resumeSubscription(string $providerSubscriptionId): void;

    /**
     * Changement d'offre déjà planifié (rétrogradation à la prochaine échéance) : le prix à venir et la date, ou null s'il n'y
     * en a pas. Lu chez Stripe : la base ne garde rien de ce changement, il ne s'applique qu'à l'échéance.
     *
     * @return array{priceId: string, startsAt: int}|null
     */
    public function scheduledChange(string $providerSubscriptionId): ?array;

    /**
     * État actuel de l'abonnement chez Stripe. Source de vérité quand un événement arrive : Stripe ne garantit pas l'ordre
     * de livraison des webhooks et rejoue ceux qui ont échoué, donc le contenu d'un événement peut être périmé.
     *
     * @return array{status: string, cancelAtPeriodEnd: bool, priceId: string|null, periodStart: int|null, periodEnd: int|null}
     */
    public function retrieveSubscription(string $providerSubscriptionId): array;

    // * résiliation immédiate, sans remboursement : plus aucun prélèvement (abonnement en double, voir StripeWebhookController)
    public function cancelSubscriptionNow(string $providerSubscriptionId): void;

    /**
     * Cartes du client Stripe de l'abonnement, lues chez Stripe à chaque appel (rien n'est stocké chez nous).
     *
     * @return list<array{id: string, brand: string|null, last4: string|null, expMonth: int|null, expYear: int|null, isDefault: bool}>
     */
    public function listPaymentMethods(string $providerSubscriptionId): array;

    public function setDefaultPaymentMethod(string $providerSubscriptionId, string $paymentMethodId): void;

    public function detachPaymentMethod(string $paymentMethodId): void;

    // * page Stripe où le client saisit une nouvelle carte ; retourne son URL
    public function createCardSetupSession(string $providerSubscriptionId, string $successUrl, string $cancelUrl): string;
}
