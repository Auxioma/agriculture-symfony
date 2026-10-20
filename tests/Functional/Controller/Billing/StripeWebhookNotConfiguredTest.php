<?php

namespace App\Tests\Functional\Controller\Billing;

use App\Tests\ApiTestCase;
use Stripe\WebhookSignature;

/**
 * Secret de webhook absent (STRIPE_WEBHOOK_SECRET vide) : la bibliothèque Stripe accepterait une signature calculée avec une
 * clé vide, que n'importe qui peut reproduire. Le contrôleur doit donc tout refuser tant que le secret n'est pas configuré.
 */
final class StripeWebhookNotConfiguredTest extends ApiTestCase
{
    private ?string $previousEnv = null;
    private ?string $previousServer = null;

    protected function setUp(): void
    {
        $this->previousEnv = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? null;
        $this->previousServer = $_SERVER['STRIPE_WEBHOOK_SECRET'] ?? null;
        $_ENV['STRIPE_WEBHOOK_SECRET'] = $_SERVER['STRIPE_WEBHOOK_SECRET'] = '';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ([['_ENV', $this->previousEnv], ['_SERVER', $this->previousServer]] as [$name, $previous]) {
            if (null === $previous) {
                unset($GLOBALS[$name]['STRIPE_WEBHOOK_SECRET']);
            } else {
                $GLOBALS[$name]['STRIPE_WEBHOOK_SECRET'] = $previous;
            }
        }
    }

    public function testEveryEventIsRefusedWhileTheSecretIsNotConfigured(): void
    {
        $payload = json_encode(['id' => 'evt_forged', 'type' => 'customer.subscription.created', 'data' => ['object' => ['id' => 'sub_forged']]]);

        // * une signature "valide" pour un secret vide : exactement ce que fabriquerait un attaquant
        $this->client->request('POST', '/api/webhooks/stripe', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($payload, ''),
        ], content: $payload);

        self::assertResponseStatusCodeSame(503);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT count(*) FROM billing.webhook_events WHERE provider_event_id = 'evt_forged'"));
    }
}
