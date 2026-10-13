<?php

namespace App\Service\Payment;

use Stripe\StripeClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class StripeGateway implements PaymentGatewayInterface
{
    private ?StripeClient $stripe = null;

    // * Le client n'est créé qu'au premier appel : sans STRIPE_SECRET_KEY (dev), Stripe lève une exception dès le
    // * constructeur, et elle doit arriver dans le try/catch des contrôleurs, pas pendant l'injection.
    public function __construct(#[Autowire(env: 'STRIPE_SECRET_KEY')] private readonly string $secretKey)
    {
    }

    private function stripe(): StripeClient
    {
        return $this->stripe ??= new StripeClient($this->secretKey);
    }

    public function createCheckoutSession(string $priceId, string $customerEmail, array $metadata, string $successUrl, string $cancelUrl, ?string $couponId = null): string
    {
        $params = [
            'mode' => 'subscription',
            'customer_email' => $customerEmail,
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            // ! subscription_data.metadata (pas metadata au niveau racine) : c'est ce qui permet de
            // ! retrouver producer_id directement sur l'objet Subscription Stripe créé juste après --
            // ! sans ça, seul l'événement checkout.session.completed le porterait, et il ne contient pas
            // ! l'abonnement complet (prix, période...). coupon_id (quand fourni) permet de la même façon à
            // ! StripeWebhookController::handleSubscriptionCreated() de retrouver le Coupon local à créditer.
            'subscription_data' => ['metadata' => $metadata],
        ];

        if ($couponId !== null) {
            $params['discounts'] = [['coupon' => $couponId]];
        }

        return $this->stripe()->checkout->sessions->create($params)->url;
    }

    public function updateSubscriptionPrice(string $providerSubscriptionId, string $newPriceId, bool $atPeriodEnd = false): void
    {
        $subscription = $this->stripe()->subscriptions->retrieve($providerSubscriptionId);
        $item = $subscription->items->data[0];

        if (!$atPeriodEnd) {
            $this->stripe()->subscriptions->update($providerSubscriptionId, [
                'items' => [['id' => $item->id, 'price' => $newPriceId]],
                'proration_behavior' => 'create_prorations',
                // * Passer de mensuel à annuel (ou l'inverse) facture tout de suite. Si ce paiement échoue, Stripe refuse alors le
                // * changement (402) au lieu de l'appliquer et de passer l'abonnement en retard de paiement avec la nouvelle offre.
                'payment_behavior' => 'error_if_incomplete',
            ]);

            return;
        }

        // * Une "schedule" garde l'ancien prix jusqu'à la fin de la période, puis passe au nouveau ; le webhook
        // * customer.subscription.updated met alors le plan local à jour, à la bonne date.
        $schedule = $this->stripe()->subscriptionSchedules->create(['from_subscription' => $providerSubscriptionId]);
        $current = $schedule->phases[0];
        $this->stripe()->subscriptionSchedules->update($schedule->id, [
            'end_behavior' => 'release',
            'phases' => [
                ['items' => [['price' => $item->price->id, 'quantity' => 1]], 'start_date' => $current->start_date, 'end_date' => $current->end_date],
                ['items' => [['price' => $newPriceId, 'quantity' => 1]], 'iterations' => 1],
            ],
        ]);
    }

    public function cancelSubscriptionAtPeriodEnd(string $providerSubscriptionId): void
    {
        $this->stripe()->subscriptions->update($providerSubscriptionId, ['cancel_at_period_end' => true]);
    }

    public function retrieveSubscription(string $providerSubscriptionId): array
    {
        $subscription = $this->stripe()->subscriptions->retrieve($providerSubscriptionId);
        $item = $subscription->items->data[0] ?? null;

        return [
            'status' => $subscription->status,
            'cancelAtPeriodEnd' => (bool) $subscription->cancel_at_period_end,
            'priceId' => $item?->price->id,
            'periodStart' => $item?->current_period_start,
            'periodEnd' => $item?->current_period_end,
        ];
    }

    public function cancelSubscriptionNow(string $providerSubscriptionId): void
    {
        $this->stripe()->subscriptions->cancel($providerSubscriptionId);
    }

    public function listPaymentMethods(string $providerSubscriptionId): array
    {
        $subscription = $this->stripe()->subscriptions->retrieve($providerSubscriptionId, ['expand' => ['customer']]);
        $customer = $subscription->customer;
        $defaultId = $subscription->default_payment_method ?? $customer->invoice_settings->default_payment_method;

        $methods = [];
        foreach ($this->stripe()->paymentMethods->all(['customer' => $customer->id, 'type' => 'card'])->data as $method) {
            $methods[] = [
                'id' => $method->id,
                'brand' => $method->card->brand,
                'last4' => $method->card->last4,
                'expMonth' => $method->card->exp_month,
                'expYear' => $method->card->exp_year,
                'isDefault' => $method->id === $defaultId,
            ];
        }

        return $methods;
    }

    public function setDefaultPaymentMethod(string $providerSubscriptionId, string $paymentMethodId): void
    {
        $subscription = $this->stripe()->subscriptions->retrieve($providerSubscriptionId);
        $this->stripe()->subscriptions->update($providerSubscriptionId, ['default_payment_method' => $paymentMethodId]);
        $this->stripe()->customers->update($subscription->customer, ['invoice_settings' => ['default_payment_method' => $paymentMethodId]]);
    }

    public function detachPaymentMethod(string $paymentMethodId): void
    {
        $this->stripe()->paymentMethods->detach($paymentMethodId);
    }

    public function createCardSetupSession(string $providerSubscriptionId, string $successUrl, string $cancelUrl): string
    {
        $subscription = $this->stripe()->subscriptions->retrieve($providerSubscriptionId);

        return $this->stripe()->checkout->sessions->create([
            'mode' => 'setup',
            'customer' => $subscription->customer,
            'payment_method_types' => ['card'],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ])->url;
    }
}
