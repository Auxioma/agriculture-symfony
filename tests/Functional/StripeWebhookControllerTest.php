<?php

namespace App\Tests\Functional;

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
        $payload = json_encode([
            'id' => 'evt_test_'.bin2hex(random_bytes(6)),
            'type' => 'customer.subscription.created',
            'data' => [
                'object' => [
                    'id' => 'sub_test_'.bin2hex(random_bytes(6)),
                    'metadata' => ['producer_id' => $producer->getId()->toRfc4122()],
                    'items' => ['data' => [['price' => ['id' => 'price_test_123']]]],
                    'current_period_start' => $now,
                    'current_period_end' => $now + 2592000,
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
            'SELECT status, producer_id, plan_price_id FROM billing.subscriptions WHERE producer_id = :producerId',
            ['producerId' => $producer->getId()->toRfc4122()]
        );
        self::assertNotFalse($row);
        self::assertSame('active', $row['status']);
        self::assertSame($planPrice->getId()->toRfc4122(), $row['plan_price_id']);
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
