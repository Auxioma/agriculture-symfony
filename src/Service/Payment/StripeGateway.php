<?php

namespace App\Service\Payment;

use Stripe\StripeClient;

final readonly class StripeGateway implements PaymentGatewayInterface
{
    public function __construct(private StripeClient $stripe)
    {
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

        $session = $this->stripe->checkout->sessions->create($params);

        return $session->url;
    }

    public function updateSubscriptionPrice(string $providerSubscriptionId, string $newPriceId): void
    {
        $subscription = $this->stripe->subscriptions->retrieve($providerSubscriptionId);
        $this->stripe->subscriptions->update($providerSubscriptionId, [
            'items' => [[
                'id' => $subscription->items->data[0]->id,
                'price' => $newPriceId,
            ]],
            'proration_behavior' => 'create_prorations',
        ]);
    }
}