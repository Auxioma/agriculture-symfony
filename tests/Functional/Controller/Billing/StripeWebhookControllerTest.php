<?php

namespace App\Tests\Functional\Controller\Billing;

use App\Entity\Billing\Coupon;
use App\Entity\Billing\PlanPrice;
use App\Entity\Billing\SubscriptionPlan;
use App\Entity\Billing\Subscription;
use App\Entity\Catalog\Currency;
use App\Entity\Producer\ProducerProfile;
use App\Enum\BillingCycle;
use App\Service\Payment\FakePaymentGateway;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
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
    private function sendEvent(string $type, array $object, ?string $eventId = null, bool $expectSuccess = true): void
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
        if ($expectSuccess) {
            self::assertResponseIsSuccessful();
        }
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

    // * l'état de l'abonnement "chez Stripe", celui que le webhook relit à chaque événement (voir FakePaymentGateway)
    private function stripeNow(string $providerId, string $status, string $priceId = 'price_inconnu', bool $cancelAtPeriodEnd = false): void
    {
        $now = time();
        $this->gateway()->subscriptions[$providerId] = [
            'status' => $status,
            'cancelAtPeriodEnd' => $cancelAtPeriodEnd,
            'priceId' => $priceId,
            'periodStart' => $now - 86400,
            'periodEnd' => $now + 2592000,
        ];
    }

    // * cas courant : l'événement et l'état actuel chez Stripe disent la même chose
    private function sendSubscriptionUpdated(string $providerId, string $stripeStatus): void
    {
        $this->stripeNow($providerId, $stripeStatus);
        $this->sendEvent('customer.subscription.updated', $this->subscriptionObject($providerId, $stripeStatus));
    }

    private function localStatus(string $providerId): string
    {
        return $this->em->getConnection()->fetchOne('SELECT status FROM billing.subscriptions WHERE provider_subscription_id = :id', ['id' => $providerId]);
    }

    public function testSubscriptionUpdatedCopiesTheStripeStatus(): void
    {
        $first = $this->linkedSubscription('sub_test_status');
        // * annulé et expiré sont définitifs chez Stripe : un abonnement par statut, créés avant le premier appel HTTP (qui vide l'EntityManager)
        $final = ['canceled' => 'cancelled', 'incomplete_expired' => 'expired'];
        foreach (array_keys($final) as $stripeStatus) {
            $this->makeActiveSubscription($this->makeProducerProfile($this->makeUser(), $first->getProducer()->getCountry()))->setProviderSubscriptionId('sub_test_'.$stripeStatus);
        }
        $this->em->flush();

        $expected = [
            'past_due' => 'past_due',
            'unpaid' => 'past_due',
            'active' => 'active',
            'trialing' => 'trialing',
        ];
        foreach ($expected as $stripeStatus => $localStatus) {
            $this->sendSubscriptionUpdated('sub_test_status', $stripeStatus);
            self::assertSame($localStatus, $this->localStatus('sub_test_status'), "statut Stripe $stripeStatus");
        }

        // * incomplete (premier paiement pas encore fait) et paused ne changent rien
        $this->sendSubscriptionUpdated('sub_test_status', 'active');
        foreach (['incomplete', 'paused'] as $ignored) {
            $this->sendSubscriptionUpdated('sub_test_status', $ignored);
            self::assertSame('active', $this->localStatus('sub_test_status'), "statut Stripe $ignored");
        }

        // * et un événement en retard ne les remet pas en vigueur
        foreach ($final as $stripeStatus => $localStatus) {
            $providerId = 'sub_test_'.$stripeStatus;
            $this->sendSubscriptionUpdated($providerId, $stripeStatus);
            self::assertSame($localStatus, $this->localStatus($providerId), "statut Stripe $stripeStatus");

            $this->sendSubscriptionUpdated($providerId, 'active');
            self::assertSame($localStatus, $this->localStatus($providerId), "$stripeStatus ne revient pas en vigueur");
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

        $this->sendSubscriptionUpdated('sub_test_limited', 'past_due');
        self::assertFalse($canReply());

        $this->sendSubscriptionUpdated('sub_test_limited', 'active');
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

    // --- deux paiements pour le même producteur (deux sessions de paiement ouvertes, payées toutes les deux) -------------------

    // * un producteur déjà abonné (abonnement relié à Stripe) ; 'price_dup' est le prix Stripe du second paiement
    private function alreadySubscribed(): Subscription
    {
        $kept = $this->linkedSubscription('sub_kept_'.bin2hex(random_bytes(4)));
        $kept->getPlanPrice()->setProviderPriceId('price_dup');
        $this->em->flush();

        return $kept;
    }

    /**
     * @param array<string, string> $metadata
     *
     * @return array<string, mixed>
     */
    private function createdObject(string $providerId, ProducerProfile $producer, array $metadata = []): array
    {
        $now = time();

        return [
            'id' => $providerId,
            'status' => 'active',
            'metadata' => ['producer_id' => $producer->getId()->toRfc4122()] + $metadata,
            'items' => ['data' => [['price' => ['id' => 'price_dup'], 'current_period_start' => $now, 'current_period_end' => $now + 2592000]]],
        ];
    }

    private function gateway(): FakePaymentGateway
    {
        return static::getContainer()->get(FakePaymentGateway::class);
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function cancelNowCalls(): array
    {
        return array_values(array_filter($this->gateway()->calls, static fn (array $call) => 'cancel_now' === $call[0]));
    }

    private function countSubscriptions(string $providerId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM billing.subscriptions WHERE provider_subscription_id = :id', ['id' => $providerId]);
    }

    public function testSecondSubscriptionOfAnAlreadySubscribedProducerIsCancelledAtStripeAndKeptAsCancelled(): void
    {
        $kept = $this->alreadySubscribed();
        $coupon = $this->makeCoupon('DOUBLE10');
        $this->em->flush();

        $this->sendEvent('customer.subscription.created', $this->createdObject('sub_dup_a', $kept->getProducer(), ['coupon_id' => $coupon->getId()->toRfc4122()]));

        $connection = $this->em->getConnection();
        self::assertSame('cancelled', $this->localStatus('sub_dup_a'));
        self::assertSame('active', $this->localStatus($kept->getProviderSubscriptionId()));
        self::assertSame(1, (int) $connection->fetchOne(
            "SELECT count(*) FROM billing.subscriptions WHERE producer_id = :p AND status IN ('active', 'trialing', 'past_due')",
            ['p' => $kept->getProducer()->getId()->toRfc4122()]
        ));
        self::assertSame([['cancel_now', 'sub_dup_a']], $this->cancelNowCalls());
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM billing.coupon_redemptions WHERE coupon_id = :c', ['c' => $coupon->getId()->toRfc4122()]));
        self::assertSame(1, (int) $connection->fetchOne("SELECT count(*) FROM audit.audit_logs WHERE action = 'subscription_duplicate_cancelled'"));
        self::assertNotFalse($connection->fetchOne(
            "SELECT 1 FROM notification.notifications WHERE type = 'subscription_duplicate_cancelled' AND user_id = :u",
            ['u' => $kept->getProducer()->getOwner()->getId()->toRfc4122()]
        ));
    }

    public function testReplayedDuplicateIsCancelledOnlyOnce(): void
    {
        $kept = $this->alreadySubscribed();
        $object = $this->createdObject('sub_dup_b', $kept->getProducer());

        $this->sendEvent('customer.subscription.created', $object, 'evt_dup_b');
        $this->sendEvent('customer.subscription.created', $object, 'evt_dup_b');
        // * même abonnement sous un autre identifiant d'événement
        $this->sendEvent('customer.subscription.created', $object, 'evt_dup_b_bis');

        self::assertCount(1, $this->cancelNowCalls());
        self::assertSame(1, $this->countSubscriptions('sub_dup_b'));
    }

    public function testSubscriptionCreatedTwiceUnderTwoEventIdsStoresASingleRow(): void
    {
        $producer = $this->makeProducerProfile($this->makeUser(), $this->makeCountry());
        $this->makeKnownPrice('price_dup');
        $this->em->flush();
        $object = $this->createdObject('sub_once', $producer);

        $this->sendEvent('customer.subscription.created', $object, 'evt_once_a');
        $this->sendEvent('customer.subscription.created', $object, 'evt_once_b');

        self::assertSame(1, $this->countSubscriptions('sub_once'));
        self::assertSame('active', $this->localStatus('sub_once'));
        self::assertSame([], $this->cancelNowCalls());
    }

    public function testPaidInvoiceOfTheCancelledDuplicateLandsOnItsOwnRow(): void
    {
        $kept = $this->alreadySubscribed();
        $this->sendEvent('customer.subscription.created', $this->createdObject('sub_dup_d', $kept->getProducer()));

        $this->sendEvent('invoice.paid', [
            'id' => 'in_dup_d',
            'amount_paid' => 4900,
            'currency' => 'eur',
            'parent' => ['subscription_details' => ['subscription' => 'sub_dup_d']],
        ]);

        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT s.provider_subscription_id, s.status, i.status AS invoice_status FROM billing.invoices i JOIN billing.subscriptions s ON s.id = i.subscription_id WHERE i.provider_invoice_id = 'in_dup_d'"
        );
        self::assertSame(['provider_subscription_id' => 'sub_dup_d', 'status' => 'cancelled', 'invoice_status' => 'paid'], $row);
    }

    public function testLateEventCannotPutTheCancelledDuplicateBackInForce(): void
    {
        $kept = $this->alreadySubscribed();
        $this->sendEvent('customer.subscription.created', $this->createdObject('sub_dup_e', $kept->getProducer()));

        // * sans la garde, ce passage à "active" violerait l'index unique : l'événement échouerait en boucle
        $this->sendSubscriptionUpdated('sub_dup_e', 'active');

        self::assertSame('cancelled', $this->localStatus('sub_dup_e'));
        self::assertSame('active', $this->localStatus($kept->getProviderSubscriptionId()));
    }

    public function testStripeOutageWhileCancellingTheDuplicateAsksStripeToRetry(): void
    {
        $kept = $this->alreadySubscribed();
        $object = $this->createdObject('sub_dup_f', $kept->getProducer());

        $this->gateway()->failure = new ApiConnectionException('panne simulée');
        $this->sendEvent('customer.subscription.created', $object, 'evt_dup_f', expectSuccess: false);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, $this->countSubscriptions('sub_dup_f'), 'rien n\'est enregistré tant que Stripe n\'a pas résilié');
        self::assertSame('failed', $this->em->getConnection()->fetchOne("SELECT status FROM billing.webhook_events WHERE provider_event_id = 'evt_dup_f'"));

        // * Stripe réessaie le même événement, Stripe répond cette fois
        $this->gateway()->failure = null;
        $this->sendEvent('customer.subscription.created', $object, 'evt_dup_f');

        self::assertSame('cancelled', $this->localStatus('sub_dup_f'));
    }

    public function testDuplicateAlreadyCancelledAtStripeIsStillRecorded(): void
    {
        $kept = $this->alreadySubscribed();

        // * réessai après un échec d'enregistrement : Stripe répond que l'abonnement n'est plus à résilier
        $this->gateway()->failure = new InvalidRequestException('No such subscription');
        $this->sendEvent('customer.subscription.created', $this->createdObject('sub_dup_g', $kept->getProducer()));

        self::assertSame('cancelled', $this->localStatus('sub_dup_g'));
    }

    public function testPaymentsProcessedAtTheSameTimeLeaveOneSubscriptionAndAskStripeToRetry(): void
    {
        $producer = $this->makeProducerProfile($this->makeUser(), $this->makeCountry());
        $planPrice = $this->makeKnownPrice('price_dup');
        $this->em->flush();

        // * simule l'autre webhook : un abonnement en vigueur apparaît entre le contrôle "déjà abonné" et l'enregistrement
        $this->em->getEventManager()->addEventListener([Events::prePersist], new class($this->em->getConnection(), $producer->getId()->toRfc4122(), $planPrice->getId()->toRfc4122()) {
            private bool $done = false;

            public function __construct(private readonly Connection $connection, private readonly string $producerId, private readonly string $planPriceId)
            {
            }

            public function prePersist(PrePersistEventArgs $args): void
            {
                if ($this->done || !$args->getObject() instanceof Subscription) {
                    return;
                }
                $this->done = true;
                $this->connection->executeStatement(
                    "INSERT INTO billing.subscriptions (id, status, current_period_start, current_period_end, cancel_at_period_end, created_at, updated_at, producer_id, plan_price_id) VALUES (gen_random_uuid(), 'active', now(), now() + interval '1 month', false, now(), now(), :producer, :planPrice)",
                    ['producer' => $this->producerId, 'planPrice' => $this->planPriceId]
                );
            }
        });

        $this->sendEvent('customer.subscription.created', $this->createdObject('sub_race', $producer), expectSuccess: false);

        self::assertResponseStatusCodeSame(409);
    }

    // * une offre dont le prix Stripe est connu ('price_dup'), sans abonnement
    private function makeKnownPrice(string $providerPriceId): PlanPrice
    {
        $plan = new SubscriptionPlan();
        $plan->setCode('plan-'.bin2hex(random_bytes(6)));
        $plan->setName('Basic');
        $plan->setIsActive(true);
        $this->em->persist($plan);

        $planPrice = new PlanPrice();
        $planPrice->setPlan($plan);
        $planPrice->setBillingCycle(BillingCycle::Monthly);
        $planPrice->setProviderPriceId($providerPriceId);
        $planPrice->setIsActive(true);
        $this->em->persist($planPrice);

        return $planPrice;
    }

    // --- événements périmés : Stripe ne garantit pas l'ordre et rejoue ceux qui ont échoué ----------------------------------------

    public function testStaleSubscriptionEventNeverOverwritesTheCurrentStripeState(): void
    {
        $this->linkedSubscription('sub_stale');

        // * Stripe est revenu à "active" ; un vieil événement "past_due" arrive après
        $this->stripeNow('sub_stale', 'active');
        $this->sendEvent('customer.subscription.updated', $this->subscriptionObject('sub_stale', 'past_due'));
        self::assertSame('active', $this->localStatus('sub_stale'));

        // * et l'inverse : le paiement a échoué depuis, un vieil événement "active" ne rend pas l'accès
        $this->stripeNow('sub_stale', 'past_due');
        $this->sendEvent('customer.subscription.updated', $this->subscriptionObject('sub_stale', 'active'));
        self::assertSame('past_due', $this->localStatus('sub_stale'));
    }

    public function testSubscriptionEventAppliesThePlanPeriodAndCancellationReadAtStripe(): void
    {
        $this->linkedSubscription('sub_plan');
        $newPrice = $this->makeKnownPrice('price_new');
        $this->em->flush();

        // * l'événement parle de l'ancien prix, sans annulation : c'est l'état chez Stripe qui compte
        $this->stripeNow('sub_plan', 'active', 'price_new', true);
        $this->sendEvent('customer.subscription.updated', $this->subscriptionObject('sub_plan', 'active'));

        $row = $this->em->getConnection()->fetchAssociative("SELECT plan_price_id, cancel_at_period_end, current_period_end FROM billing.subscriptions WHERE provider_subscription_id = 'sub_plan'");
        self::assertSame($newPrice->getId()->toRfc4122(), $row['plan_price_id']);
        self::assertTrue((bool) $row['cancel_at_period_end']);
        self::assertEqualsWithDelta(time() + 2592000, (new \DateTimeImmutable($row['current_period_end']))->getTimestamp(), 5);
    }

    public function testStripeOutageWhenReadingTheSubscriptionAsksStripeToRetry(): void
    {
        $this->linkedSubscription('sub_read');
        $this->stripeNow('sub_read', 'past_due');
        $object = $this->subscriptionObject('sub_read', 'past_due');

        $this->gateway()->failure = new ApiConnectionException('panne simulée');
        $this->sendEvent('customer.subscription.updated', $object, 'evt_read', expectSuccess: false);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('active', $this->localStatus('sub_read'), 'rien n\'est appliqué tant que Stripe n\'a pas répondu');

        $this->gateway()->failure = null;
        $this->sendEvent('customer.subscription.updated', $object, 'evt_read');
        self::assertSame('past_due', $this->localStatus('sub_read'));
    }

    public function testEventForASubscriptionUnknownToStripeIsIgnored(): void
    {
        $this->linkedSubscription('sub_gone');

        $this->gateway()->failure = new InvalidRequestException('No such subscription');
        $this->sendEvent('customer.subscription.updated', $this->subscriptionObject('sub_gone', 'past_due'));

        self::assertSame('active', $this->localStatus('sub_gone'));
    }

    public function testLateFailedPaymentEventDoesNotReopenAPaidInvoice(): void
    {
        $subscription = $this->linkedSubscription('sub_late_invoice');
        $ofSubscription = ['parent' => ['subscription_details' => ['subscription' => 'sub_late_invoice']]];

        $this->sendEvent('invoice.paid', ['id' => 'in_late', 'amount_paid' => 1900, 'currency' => 'eur'] + $ofSubscription);
        // * l'échec de la première tentative arrive après le paiement (il avait été réessayé)
        $this->sendEvent('invoice.payment_failed', ['id' => 'in_late', 'amount_due' => 1900, 'currency' => 'eur', 'attempt_count' => 1] + $ofSubscription);

        $connection = $this->em->getConnection();
        self::assertSame('paid', $connection->fetchOne("SELECT status FROM billing.invoices WHERE provider_invoice_id = 'in_late'"));
        $payments = $connection->fetchFirstColumn("SELECT p.status FROM billing.payments p JOIN billing.invoices i ON i.id = p.invoice_id WHERE i.provider_invoice_id = 'in_late'");
        self::assertEqualsCanonicalizing(['failed', 'succeeded'], $payments);
        self::assertSame(0, (int) $connection->fetchOne(
            "SELECT count(*) FROM notification.notifications WHERE type = 'payment_failed' AND user_id = :u",
            ['u' => $subscription->getProducer()->getOwner()->getId()->toRfc4122()]
        ));
    }
}
