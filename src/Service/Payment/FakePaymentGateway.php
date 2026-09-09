<?php

namespace App\Service\Payment;

final class FakePaymentGateway implements PaymentGatewayInterface
{
    public function createCheckoutSession(string $priceId, string $customerEmail, array $metadata, string $successUrl, string $cancelUrl): string
    {
        return 'https://checkout.stripe.test/fake-session';
    }

    public function updateSubscriptionPrice(string $providerSubscriptionId, string $newPriceId): void
    {
        // * No-op : dans les tests, le vrai résultat d'un changement de plan viendrait d'un webhook
        // * customer.subscription.updated -- on ne simule pas ici l'appel Stripe lui-même.
    }
}