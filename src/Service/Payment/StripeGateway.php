<?php

namespace App\Service\Payment;

use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidArgumentException;
use Stripe\StripeClient;
use Stripe\Subscription;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class StripeGateway implements PaymentGatewayInterface
{
    private ?StripeClient $stripe = null;

    // * Le client n'est créé qu'au premier appel : sans STRIPE_SECRET_KEY (dev), Stripe lève une exception dès le
    // * constructeur, et elle doit arriver dans le try/catch des contrôleurs, pas pendant l'injection.
    public function __construct(
        #[Autowire(env: 'STRIPE_SECRET_KEY')] private readonly string $secretKey,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
    }

    private function stripe(): StripeClient
    {
        // ! Une clé live en dev ou en test ferait de vrais prélèvements. Refusée ici : cette exception est rattrapée comme les
        // ! autres erreurs Stripe par les contrôleurs (502 + une ligne dans les logs qui dit pourquoi).
        if ('prod' !== $this->environment && 1 === preg_match('/^[sr]k_live_/', $this->secretKey)) {
            throw new InvalidArgumentException(\sprintf('Clé Stripe live refusée hors production (environnement : %s).', $this->environment));
        }

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
            // * Une session ouverte reste payable 24 h par défaut : deux sessions payées chacune = deux abonnements. 35 minutes
            // * (Stripe exige au moins 30, la marge couvre le temps de l'appel) réduisent cette fenêtre.
            'expires_at' => time() + 2100,
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
        // ! Un changement prévu (planification) interdit toute autre modification directe de l'abonnement (changer d'offre,
        // ! annuler en fin de période) : on le libère d'abord, l'abonnement garde son état courant. Monter d'offre annule donc
        // ! une rétrogradation prévue, et en programmer une nouvelle remplace la précédente.
        $this->releaseSchedule($subscription);
        $item = $subscription->items->data[0];

        if (!$atPeriodEnd) {
            $this->stripe()->subscriptions->update($providerSubscriptionId, [
                'items' => [['id' => $item->id, 'price' => $newPriceId]],
                // ! Facture tout de suite la différence, au prorata de la période en cours (crédit du temps non utilisé de l'ancienne
                // ! offre, débit du temps restant de la nouvelle). Avec "create_prorations" et la facturation "flexible" de ce compte,
                // ! le supplément n'arrive qu'au prochain renouvellement : jusqu'à un an plus tard pour un abonnement annuel, et un
                // ! passage mensuel -> annuel donnait l'année sans la payer (facture de 931 EUR dans un an, vérifié chez Stripe).
                'proration_behavior' => 'always_invoice',
                // * si ce paiement échoue (carte refusée...), Stripe répond 402 et n'applique pas le changement
                'payment_behavior' => 'error_if_incomplete',
            ]);

            return;
        }

        // * Une planification garde l'ancien prix jusqu'à la fin de la période, puis passe au nouveau pour une période, après quoi
        // * elle se libère toute seule (end_behavior). Le webhook customer.subscription.updated met alors le plan local à jour,
        // * à la bonne date. La durée de la 2e phase suit l'intervalle du nouveau prix : "iterations" n'existe plus dans cette
        // * version de l'API (Stripe répond "unknown parameter").
        $schedule = $this->stripe()->subscriptionSchedules->create(['from_subscription' => $providerSubscriptionId]);
        try {
            $current = $schedule->phases[0];
            $recurring = $this->stripe()->prices->retrieve($newPriceId)->recurring;
            $this->stripe()->subscriptionSchedules->update($schedule->id, [
                'end_behavior' => 'release',
                'phases' => [
                    ['items' => [['price' => $item->price->id, 'quantity' => 1]], 'start_date' => $current->start_date, 'end_date' => $current->end_date],
                    ['items' => [['price' => $newPriceId, 'quantity' => 1]], 'duration' => ['interval' => $recurring->interval, 'interval_count' => $recurring->interval_count]],
                ],
            ]);
        } catch (ApiErrorException $e) {
            // ! ne jamais laisser une planification à moitié faite : elle bloquerait ensuite l'annulation et les changements d'offre
            $this->stripe()->subscriptionSchedules->release($schedule->id);

            throw $e;
        }
    }

    public function cancelSubscriptionAtPeriodEnd(string $providerSubscriptionId): void
    {
        // * un changement d'offre prévu empêche l'annulation en fin de période : il n'a plus de raison d'être, on le libère
        $this->releaseSchedule($this->stripe()->subscriptions->retrieve($providerSubscriptionId));
        $this->stripe()->subscriptions->update($providerSubscriptionId, ['cancel_at_period_end' => true]);
    }

    public function resumeSubscription(string $providerSubscriptionId): void
    {
        $this->stripe()->subscriptions->update($providerSubscriptionId, ['cancel_at_period_end' => false]);
    }

    public function scheduledChange(string $providerSubscriptionId): ?array
    {
        $schedule = $this->stripe()->subscriptions->retrieve($providerSubscriptionId)->schedule;
        if (null === $schedule) {
            return null;
        }

        // * la phase qui n'a pas encore commencé est le changement à venir (la première est l'offre actuelle)
        $schedule = $this->stripe()->subscriptionSchedules->retrieve(\is_string($schedule) ? $schedule : $schedule->id);
        foreach ($schedule->phases as $phase) {
            if ($phase->start_date > time()) {
                return ['priceId' => $phase->items[0]->price, 'startsAt' => $phase->start_date];
            }
        }

        return null;
    }

    private function releaseSchedule(Subscription $subscription): void
    {
        $schedule = $subscription->schedule;
        if (null !== $schedule) {
            $this->stripe()->subscriptionSchedules->release(\is_string($schedule) ? $schedule : $schedule->id);
        }
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
