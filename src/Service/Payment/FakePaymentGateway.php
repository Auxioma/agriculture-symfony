<?php

namespace App\Service\Payment;

final class FakePaymentGateway implements PaymentGatewayInterface
{
    /** @var list<array<int, mixed>> appels reçus, dans l'ordre : [nom, arguments...] */
    public array $calls = [];

    /** @var list<array{id: string, brand: string|null, last4: string|null, expMonth: int|null, expYear: int|null, isDefault: bool}> */
    public array $paymentMethods = [
        ['id' => 'pm_fake_visa', 'brand' => 'visa', 'last4' => '4242', 'expMonth' => 8, 'expYear' => 2028, 'isDefault' => true],
        ['id' => 'pm_fake_mastercard', 'brand' => 'mastercard', 'last4' => '4444', 'expMonth' => 1, 'expYear' => 2030, 'isDefault' => false],
    ];

    /** @var array<string, array{status: string, cancelAtPeriodEnd: bool, priceId: string|null, periodStart: int|null, periodEnd: int|null}> état de chaque abonnement "chez Stripe", à poser dans le test avant d'envoyer l'événement */
    public array $subscriptions = [];

    /** @var array<string, array{priceId: string, startsAt: int}> changement d'offre planifié de chaque abonnement, à poser dans le test */
    public array $scheduledChanges = [];

    // * Pour simuler une panne ou une clé Stripe absente : toute méthode lève cette exception.
    public ?\Throwable $failure = null;

    public function createCheckoutSession(string $priceId, string $customerEmail, array $metadata, string $successUrl, string $cancelUrl, ?string $couponId = null): string
    {
        $this->record('checkout', $priceId, $successUrl, $cancelUrl);

        return 'https://checkout.stripe.test/fake-session';
    }

    public function updateSubscriptionPrice(string $providerSubscriptionId, string $newPriceId, bool $atPeriodEnd = false): void
    {
        // * Pas de changement local ici : dans la vraie vie, le résultat vient d'un webhook
        // * customer.subscription.updated, qu'on ne simule pas.
        $this->record('update_price', $providerSubscriptionId, $newPriceId, $atPeriodEnd);
    }

    public function cancelSubscriptionAtPeriodEnd(string $providerSubscriptionId): void
    {
        $this->record('cancel', $providerSubscriptionId);
    }

    public function resumeSubscription(string $providerSubscriptionId): void
    {
        $this->record('resume', $providerSubscriptionId);
    }

    public function scheduledChange(string $providerSubscriptionId): ?array
    {
        $this->record('scheduled_change', $providerSubscriptionId);

        return $this->scheduledChanges[$providerSubscriptionId] ?? null;
    }

    public function retrieveSubscription(string $providerSubscriptionId): array
    {
        $this->record('retrieve_subscription', $providerSubscriptionId);

        return $this->subscriptions[$providerSubscriptionId] ?? throw new \LogicException(\sprintf('État de %s non défini dans FakePaymentGateway::$subscriptions.', $providerSubscriptionId));
    }

    public function cancelSubscriptionNow(string $providerSubscriptionId): void
    {
        $this->record('cancel_now', $providerSubscriptionId);
    }

    public function anonymizeCustomer(string $providerSubscriptionId): void
    {
        $this->record('anonymize_customer', $providerSubscriptionId);
    }

    public function listPaymentMethods(string $providerSubscriptionId): array
    {
        $this->record('list_payment_methods', $providerSubscriptionId);

        return $this->paymentMethods;
    }

    public function setDefaultPaymentMethod(string $providerSubscriptionId, string $paymentMethodId): void
    {
        $this->record('set_default', $providerSubscriptionId, $paymentMethodId);
        $this->paymentMethods = array_map(
            static fn (array $m) => ['isDefault' => $m['id'] === $paymentMethodId] + $m,
            $this->paymentMethods
        );
    }

    public function detachPaymentMethod(string $paymentMethodId): void
    {
        $this->record('detach', $paymentMethodId);
        $this->paymentMethods = array_values(array_filter($this->paymentMethods, static fn (array $m) => $m['id'] !== $paymentMethodId));
    }

    public function createCardSetupSession(string $providerSubscriptionId, string $successUrl, string $cancelUrl): string
    {
        $this->record('setup_session', $providerSubscriptionId, $successUrl, $cancelUrl);

        return 'https://checkout.stripe.test/fake-setup';
    }

    private function record(mixed ...$call): void
    {
        $this->calls[] = $call;
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
