<?php

namespace App\Tests\Functional\Controller\Billing;

use App\Entity\Billing\Coupon;
use App\Entity\Billing\PlanPrice;
use App\Entity\Billing\SubscriptionPlan;
use App\Entity\Catalog\Currency;
use App\Enum\BillingCycle;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use Stripe\WebhookSignature;

/**
 * Teste POST /api/webhooks/stripe (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf, round 2).
 * Le payload est signé avec WebhookSignature::generateSignatureHeader(), prévu par le SDK Stripe lui-même
 * "for unit tests" -- STRIPE_WEBHOOK_SECRET doit avoir une valeur fixe et connue en environnement de test
 * (voir .env.test : whsec_test_fixed_secret) pour que la signature générée ici et celle vérifiée par le
 * contrôleur (Webhook::constructEvent) utilisent le même secret.
 */
final class StripeWebhookControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private const WEBHOOK_SECRET = 'whsec_test_fixed_secret';

    public function testWebhookCreatesSubscriptionOnSubscriptionCreated(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser(), $country);

        // * providerPriceId='price_test_123' est ce qui permet à handleSubscriptionCreated() de retrouver
        // * quel PlanPrice local correspond au prix Stripe souscrit -- sans correspondance, l'événement est
        // * ignoré silencieusement (cf. le if ($producer === null || $planPrice === null) return;).
        $plan = new SubscriptionPlan();
        $plan->setCode('plan-'.bin2hex(random_bytes(6)));
        $plan->setName('Basic');
        $plan->setIsActive(true);
        $this->em->persist($plan);

        $planPrice = new PlanPrice();
        $planPrice->setPlan($plan);
        $planPrice->setBillingCycle(BillingCycle::Monthly);
        $planPrice->setProviderPriceId('price_test_123');
        $planPrice->setIsActive(true);
        $this->em->persist($planPrice);

        $this->em->flush();

        $now = time();
        // ! Depuis l'API Stripe version 2026-02-25.clover, current_period_start/end vivent sur chaque
        // ! subscription_item (items.data[0]), plus sur l'objet Subscription lui-même -- confirmé en
        // ! inspectant un vrai payload webhook reçu lors d'un test manuel du flux complet. Reproduire
        // ! fidèlement cette forme ici, sinon ce test ne détecte pas la régression qu'il a justement révélée.
        $payload = json_encode([
            'id' => 'evt_test_'.bin2hex(random_bytes(6)),
            'type' => 'customer.subscription.created',
            'data' => [
                'object' => [
                    'id' => 'sub_test_'.bin2hex(random_bytes(6)),
                    'metadata' => ['producer_id' => $producer->getId()->toRfc4122()],
                    'items' => ['data' => [[
                        'price' => ['id' => 'price_test_123'],
                        'current_period_start' => $now,
                        'current_period_end' => $now + 2592000,
                    ]]],
                ],
            ],
        ]);

        $signature = WebhookSignature::generateSignatureHeader($payload, self::WEBHOOK_SECRET);

        $this->client->request('POST', '/api/webhooks/stripe', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature,
        ], content: $payload);

        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status, producer_id, plan_price_id, current_period_start FROM billing.subscriptions WHERE producer_id = :producerId',
            ['producerId' => $producer->getId()->toRfc4122()]
        );
        self::assertNotFalse($row);
        self::assertSame('active', $row['status']);
        self::assertSame($planPrice->getId()->toRfc4122(), $row['plan_price_id']);
        // * Vérifie que la date vient bien de items.data[0].current_period_start (et pas d'un epoch-zéro
        // * silencieux si le code lisait encore le mauvais champ) : à quelques secondes près de $now.
        self::assertEqualsWithDelta($now, (new \DateTimeImmutable($row['current_period_start']))->getTimestamp(), 5);
    }

    // * Cahier fonctionnel "coupons abonnement" -- coupon_id (posé par
    // * SubscriptionController::checkout() dans subscription_data.metadata) doit produire une
    // * CouponRedemption une fois l'abonnement Stripe réellement créé, pas avant.
    public function testWebhookCreatesCouponRedemptionWhenCouponIdInMetadata(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser(), $country);

        $plan = new SubscriptionPlan();
        $plan->setCode('plan-'.bin2hex(random_bytes(6)));
        $plan->setName('Basic');
        $plan->setIsActive(true);
        $this->em->persist($plan);

        $planPrice = new PlanPrice();
        $planPrice->setPlan($plan);
        $planPrice->setBillingCycle(BillingCycle::Monthly);
        $planPrice->setProviderPriceId('price_test_coupon_webhook');
        $planPrice->setIsActive(true);
        $this->em->persist($planPrice);

        $coupon = new Coupon();
        $coupon->setCode('WEBHOOK10');
        $coupon->setProviderCouponId('coupon_test_webhook');
        $this->em->persist($coupon);

        $this->em->flush();

        $now = time();
        $payload = json_encode([
            'id' => 'evt_test_'.bin2hex(random_bytes(6)),
            'type' => 'customer.subscription.created',
            'data' => [
                'object' => [
                    'id' => 'sub_test_'.bin2hex(random_bytes(6)),
                    'metadata' => ['producer_id' => $producer->getId()->toRfc4122(), 'coupon_id' => $coupon->getId()->toRfc4122()],
                    'items' => ['data' => [[
                        'price' => ['id' => 'price_test_coupon_webhook'],
                        'current_period_start' => $now,
                        'current_period_end' => $now + 2592000,
                    ]]],
                ],
            ],
        ]);

        $this->client->request('POST', '/api/webhooks/stripe', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($payload, self::WEBHOOK_SECRET),
        ], content: $payload);

        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT producer_id FROM billing.coupon_redemptions WHERE coupon_id = :couponId',
            ['couponId' => $coupon->getId()->toRfc4122()]
        );
        self::assertNotFalse($row);
        self::assertSame($producer->getId()->toRfc4122(), $row['producer_id']);
    }

    // * Reproduit exactement ce qui s'est produit lors d'un test manuel réel : invoice.paid arrivé une
    // * seconde avant customer.subscription.created (Stripe ne garantit pas l'ordre de livraison). Vérifie
    // * que ce cas renvoie 409 (pas 200) pour que Stripe réessaie, puis que le rejeu réussit une fois la
    // * Subscription créée -- cf. cahier devops "Webhooks paiement -- échecs répétés -- Haute".
    public function testWebhookRetriesInvoicePaidWhenSubscriptionNotYetCreated(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser(), $country);

        $plan = new SubscriptionPlan();
        $plan->setCode('plan-'.bin2hex(random_bytes(6)));
        $plan->setName('Basic');
        $plan->setIsActive(true);
        $this->em->persist($plan);

        $planPrice = new PlanPrice();
        $planPrice->setPlan($plan);
        $planPrice->setBillingCycle(BillingCycle::Monthly);
        $planPrice->setProviderPriceId('price_test_456');
        $planPrice->setIsActive(true);
        $this->em->persist($planPrice);

        $this->em->flush();

        $providerSubscriptionId = 'sub_test_'.bin2hex(random_bytes(6));

        $invoicePayload = json_encode([
            'id' => 'evt_test_invoice_'.bin2hex(random_bytes(6)),
            'type' => 'invoice.paid',
            'data' => [
                'object' => [
                    'id' => 'in_test_'.bin2hex(random_bytes(6)),
                    'amount_paid' => 999,
                    'hosted_invoice_url' => 'https://invoice.stripe.test/fake',
                    'parent' => ['subscription_details' => ['subscription' => $providerSubscriptionId]],
                ],
            ],
        ]);
        $invoiceSignature = WebhookSignature::generateSignatureHeader($invoicePayload, self::WEBHOOK_SECRET);

        // * 1er envoi : la Subscription locale n'existe pas encore -- doit échouer avec 409, pas 200.
        $this->client->request('POST', '/api/webhooks/stripe', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $invoiceSignature,
        ], content: $invoicePayload);
        self::assertResponseStatusCodeSame(409);

        $eventRow = $this->em->getConnection()->fetchAssociative(
            'SELECT status FROM billing.webhook_events WHERE event_type = :type ORDER BY received_at DESC LIMIT 1',
            ['type' => 'invoice.paid']
        );
        self::assertSame('failed', $eventRow['status']);

        // * customer.subscription.created arrive enfin -- la Subscription locale existe désormais.
        $now = time();
        $subscriptionPayload = json_encode([
            'id' => 'evt_test_sub_'.bin2hex(random_bytes(6)),
            'type' => 'customer.subscription.created',
            'data' => [
                'object' => [
                    'id' => $providerSubscriptionId,
                    'metadata' => ['producer_id' => $producer->getId()->toRfc4122()],
                    'items' => ['data' => [[
                        'price' => ['id' => 'price_test_456'],
                        'current_period_start' => $now,
                        'current_period_end' => $now + 2592000,
                    ]]],
                ],
            ],
        ]);
        $this->client->request('POST', '/api/webhooks/stripe', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($subscriptionPayload, self::WEBHOOK_SECRET),
        ], content: $subscriptionPayload);
        self::assertResponseIsSuccessful();

        // * Rejeu du même événement invoice.paid (même id) : Stripe le renverrait tel quel lors d'un retry.
        $this->client->request('POST', '/api/webhooks/stripe', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $invoiceSignature,
        ], content: $invoicePayload);
        self::assertResponseIsSuccessful();

        $invoiceRow = $this->em->getConnection()->fetchAssociative(
            'SELECT i.amount FROM billing.invoices i JOIN billing.subscriptions s ON s.id = i.subscription_id WHERE s.provider_subscription_id = :id',
            ['id' => $providerSubscriptionId]
        );
        self::assertNotFalse($invoiceRow);
        self::assertSame('9.99', $invoiceRow['amount']);
    }

    // * Cahier fonctionnel "Paiement échoué" -- réutilise la même extraction que invoice.paid (parent.subscription_details.
    // * subscription), l'objet Invoice ayant la même forme quel que soit son statut final dans cette version d'API.
    public function testWebhookNotifiesProducerOnPaymentFailed(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser(), $country);
        $subscription = $this->makeActiveSubscription($producer);
        $subscription->setProviderSubscriptionId('sub_test_payment_failed');
        $this->em->flush();

        $payload = json_encode([
            'id' => 'evt_test_'.bin2hex(random_bytes(6)),
            'type' => 'invoice.payment_failed',
            'data' => [
                'object' => [
                    'id' => 'in_test_'.bin2hex(random_bytes(6)),
                    'parent' => ['subscription_details' => ['subscription' => 'sub_test_payment_failed']],
                ],
            ],
        ]);

        $this->client->request('POST', '/api/webhooks/stripe', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($payload, self::WEBHOOK_SECRET),
        ], content: $payload);

        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT type FROM notification.notifications WHERE user_id = :userId AND type = 'payment_failed'",
            ['userId' => $producer->getOwner()->getId()->toRfc4122()]
        );
        self::assertNotFalse($row);
    }

    /**
     * Envoie un événement Stripe signé, comme Stripe le ferait ; $eventId fixe pour rejouer le même événement.
     *
     * @param array<string, mixed> $object
     */
    private function sendEvent(string $type, array $object, ?string $eventId = null): void
    {
        $payload = json_encode([
            'id' => $eventId ?? 'evt_test_'.bin2hex(random_bytes(6)),
            'type' => $type,
            'data' => ['object' => $object],
        ]);

        $this->client->request('POST', '/api/webhooks/stripe', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($payload, self::WEBHOOK_SECRET),
        ], content: $payload);
        self::assertResponseIsSuccessful();
    }

    // * un abonnement actif relié à Stripe ($providerId) et la devise EUR
    private function linkedSubscription(string $providerId): \App\Entity\Billing\Subscription
    {
        $producer = $this->makeProducerProfile($this->makeUser(), $this->makeCountry());
        $subscription = $this->makeActiveSubscription($producer);
        $subscription->setProviderSubscriptionId($providerId);
        $currency = new Currency();
        $currency->setCode('EUR');
        $currency->setName('Euro');
        $this->em->persist($currency);
        $this->em->flush();

        return $subscription;
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionObject(string $providerId, string $stripeStatus): array
    {
        $now = time();

        return [
            'id' => $providerId,
            'status' => $stripeStatus,
            'cancel_at_period_end' => false,
            'items' => ['data' => [['price' => ['id' => 'price_inconnu'], 'current_period_start' => $now - 86400, 'current_period_end' => $now + 2592000]]],
        ];
    }

    private function localStatus(string $providerId): string
    {
        return $this->em->getConnection()->fetchOne('SELECT status FROM billing.subscriptions WHERE provider_subscription_id = :id', ['id' => $providerId]);
    }

    public function testSubscriptionUpdatedCopiesTheStripeStatus(): void
    {
        $this->linkedSubscription('sub_test_status');

        $expected = [
            'past_due' => 'past_due',
            'unpaid' => 'past_due',
            'active' => 'active',
            'trialing' => 'trialing',
            'canceled' => 'cancelled',
            'incomplete_expired' => 'expired',
        ];
        foreach ($expected as $stripeStatus => $localStatus) {
            $this->sendEvent('customer.subscription.updated', $this->subscriptionObject('sub_test_status', $stripeStatus));
            self::assertSame($localStatus, $this->localStatus('sub_test_status'), "statut Stripe $stripeStatus");
        }

        // * incomplete (premier paiement pas encore fait) et paused ne changent rien
        $this->sendEvent('customer.subscription.updated', $this->subscriptionObject('sub_test_status', 'active'));
        foreach (['incomplete', 'paused'] as $ignored) {
            $this->sendEvent('customer.subscription.updated', $this->subscriptionObject('sub_test_status', $ignored));
            self::assertSame('active', $this->localStatus('sub_test_status'), "statut Stripe $ignored");
        }
    }

    public function testLatePaymentLimitsAccessUntilStripeReactivatesTheSubscription(): void
    {
        $subscription = $this->linkedSubscription('sub_test_limited');
        $producerId = $subscription->getProducer()->getId()->toRfc4122();
        $subscription->getPlanPrice()->getPlan()->setFeatures(['reply_to_requests' => true]);
        $this->em->flush();
        $canReply = fn (): bool => (bool) $this->em->getConnection()->fetchOne("SELECT billing.producer_has_feature(:id, 'reply_to_requests')", ['id' => $producerId]);

        self::assertTrue($canReply());

        $this->sendEvent('customer.subscription.updated', $this->subscriptionObject('sub_test_limited', 'past_due'));
        self::assertFalse($canReply());

        $this->sendEvent('customer.subscription.updated', $this->subscriptionObject('sub_test_limited', 'active'));
        self::assertTrue($canReply());
    }

    public function testCreatedSubscriptionKeepsTheStripeTrialStatus(): void
    {
        $producer = $this->makeProducerProfile($this->makeUser(), $this->makeCountry());
        $plan = new SubscriptionPlan();
        $plan->setCode('plan-'.bin2hex(random_bytes(6)));
        $plan->setName('Basic');
        $plan->setIsActive(true);
        $this->em->persist($plan);
        $planPrice = new PlanPrice();
        $planPrice->setPlan($plan);
        $planPrice->setBillingCycle(BillingCycle::Monthly);
        $planPrice->setProviderPriceId('price_test_trial');
        $planPrice->setIsActive(true);
        $this->em->persist($planPrice);
        $this->em->flush();
        $now = time();

        $this->sendEvent('customer.subscription.created', [
            'id' => 'sub_test_trial',
            'status' => 'trialing',
            'metadata' => ['producer_id' => $producer->getId()->toRfc4122()],
            'items' => ['data' => [['price' => ['id' => 'price_test_trial'], 'current_period_start' => $now, 'current_period_end' => $now + 1209600]]],
        ]);

        self::assertSame('trialing', $this->localStatus('sub_test_trial'));
    }

    public function testFailedPaymentRecordsAFailedInvoiceAndAPaymentForTheAdmin(): void
    {
        $this->linkedSubscription('sub_test_failed_rows');

        $this->sendEvent('invoice.payment_failed', [
            'id' => 'in_test_failed',
            'amount_due' => 1900,
            'currency' => 'eur',
            'attempt_count' => 2,
            'hosted_invoice_url' => 'https://invoice.stripe.test/failed',
            'parent' => ['subscription_details' => ['subscription' => 'sub_test_failed_rows']],
        ]);

        $connection = $this->em->getConnection();
        $invoice = $connection->fetchAssociative("SELECT status, amount, currency, invoice_url, paid_at FROM billing.invoices WHERE provider_invoice_id = 'in_test_failed'");
        self::assertSame(['status' => 'failed', 'amount' => '19.00', 'currency' => 'EUR', 'invoice_url' => 'https://invoice.stripe.test/failed', 'paid_at' => null], $invoice);

        $payment = $connection->fetchAssociative("SELECT p.status, p.amount, p.currency, p.failure_reason FROM billing.payments p JOIN billing.invoices i ON i.id = p.invoice_id WHERE i.provider_invoice_id = 'in_test_failed'");
        self::assertSame(['status' => 'failed', 'amount' => '19.00', 'currency' => 'EUR', 'failure_reason' => 'Paiement refusé (tentative 2).'], $payment);
    }

    public function testPaidInvoiceRecordsASucceededPaymentAndSettlesTheFailedInvoiceInPlace(): void
    {
        $this->linkedSubscription('sub_test_retry');
        $subscription = ['parent' => ['subscription_details' => ['subscription' => 'sub_test_retry']]];

        $this->sendEvent('invoice.payment_failed', ['id' => 'in_test_retry', 'amount_due' => 1900, 'currency' => 'eur', 'attempt_count' => 1] + $subscription);
        // * Stripe réessaie : la même facture est payée, elle ne doit pas être dupliquée
        $this->sendEvent('invoice.paid', ['id' => 'in_test_retry', 'amount_paid' => 1900, 'currency' => 'eur', 'hosted_invoice_url' => 'https://invoice.stripe.test/paid'] + $subscription);

        $connection = $this->em->getConnection();
        $invoices = $connection->fetchAllAssociative("SELECT status, amount, invoice_url FROM billing.invoices WHERE provider_invoice_id = 'in_test_retry'");
        self::assertSame([['status' => 'paid', 'amount' => '19.00', 'invoice_url' => 'https://invoice.stripe.test/paid']], $invoices);
        self::assertNotNull($connection->fetchOne("SELECT paid_at FROM billing.invoices WHERE provider_invoice_id = 'in_test_retry'"));

        $payments = $connection->fetchFirstColumn("SELECT p.status FROM billing.payments p JOIN billing.invoices i ON i.id = p.invoice_id WHERE i.provider_invoice_id = 'in_test_retry' ORDER BY p.created_at, p.status DESC");
        self::assertEqualsCanonicalizing(['failed', 'succeeded'], $payments);
    }

    public function testReplayedPaymentFailedEventDoesNotDuplicateTheRows(): void
    {
        $this->linkedSubscription('sub_test_replay');
        $object = ['id' => 'in_test_replay', 'amount_due' => 1900, 'currency' => 'eur', 'parent' => ['subscription_details' => ['subscription' => 'sub_test_replay']]];

        $this->sendEvent('invoice.payment_failed', $object, 'evt_test_replay_fixed');
        $this->sendEvent('invoice.payment_failed', $object, 'evt_test_replay_fixed');

        $connection = $this->em->getConnection();
        self::assertSame(1, (int) $connection->fetchOne("SELECT count(*) FROM billing.invoices WHERE provider_invoice_id = 'in_test_replay'"));
        self::assertSame(1, (int) $connection->fetchOne("SELECT count(*) FROM billing.payments p JOIN billing.invoices i ON i.id = p.invoice_id WHERE i.provider_invoice_id = 'in_test_replay'"));
    }

    public function testWebhookRejectsInvalidSignature(): void
    {
        $payload = json_encode(['id' => 'evt_test', 'type' => 'customer.subscription.created', 'data' => ['object' => []]]);

        $this->client->request('POST', '/api/webhooks/stripe', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=1234567890,v1=signature_invalide',
        ], content: $payload);

        self::assertResponseStatusCodeSame(400);
    }
}
