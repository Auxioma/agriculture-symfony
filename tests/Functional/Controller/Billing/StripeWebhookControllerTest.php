<?php

namespace App\Tests\Functional\Controller\Billing;

use App\Entity\Billing\PlanPrice;
use App\Entity\Billing\SubscriptionPlan;
use App\Enum\BillingCycle;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use Stripe\WebhookSignature;

/**
 * Teste POST /api/webhooks/stripe (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf §20.7 round 2).
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
