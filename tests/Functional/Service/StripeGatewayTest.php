<?php

namespace App\Tests\Functional\Service;

use App\Service\Payment\StripeGateway;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;

/**
 * Ce que StripeGateway envoie réellement à Stripe, sans réseau : le client HTTP du SDK est remplacé par un faux qui
 * enregistre chaque appel et répond avec un abonnement type. (Les contrôleurs, eux, sont testés avec FakePaymentGateway.)
 */

final class StripeGatewayTest extends TestCase
{
    private const SUBSCRIPTION = '{"id":"sub_1","object":"subscription","status":"past_due","cancel_at_period_end":true,"items":{"object":"list","data":[{"id":"si_1","object":"subscription_item","current_period_start":1700000000,"current_period_end":1702592000,"price":{"id":"price_old","object":"price"}}],"has_more":false,"url":"/v1/subscription_items"}}';

    /** @var \ArrayObject<int, array{string, string, array<string, mixed>}> méthode, chemin et paramètres de chaque appel */
    private \ArrayObject $requests;

    protected function setUp(): void
    {
        $this->requests = new \ArrayObject();

        ApiRequestor::setHttpClient(new class($this->requests, self::SUBSCRIPTION) implements ClientInterface {
            /** @param \ArrayObject<int, array{string, string, array<string, mixed>}> $requests */
            public function __construct(private readonly \ArrayObject $requests, private readonly string $body)
            {
            }

            /**
             * @param mixed $method
             * @param mixed $absUrl
             * @param mixed $headers
             * @param mixed $params
             * @param mixed $hasFile
             * @param mixed $apiMode
             * @param mixed $maxNetworkRetries
             *
             * @return array{string, int, array<string, string>}
             */
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->requests->append([strtoupper($method), (string) parse_url($absUrl, PHP_URL_PATH), $params]);

                return [$this->body, 200, []];
            }
        });
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
    }

    public function testAnImmediateChangeIsRefusedByStripeWhenItsPaymentFails(): void
    {
        (new StripeGateway('sk_test_dummy'))->updateSubscriptionPrice('sub_1', 'price_new');

        $update = $this->requests[1];
        self::assertSame(['POST', '/v1/subscriptions/sub_1'], [$update[0], $update[1]]);
        self::assertSame([['id' => 'si_1', 'price' => 'price_new']], $update[2]['items']);
        self::assertSame('create_prorations', $update[2]['proration_behavior']);
        self::assertSame('error_if_incomplete', $update[2]['payment_behavior']);
    }

    public function testRetrieveSubscriptionReadsTheStateFromStripe(): void
    {
        $state = (new StripeGateway('sk_test_dummy'))->retrieveSubscription('sub_1');

        self::assertSame(['GET', '/v1/subscriptions/sub_1'], [$this->requests[0][0], $this->requests[0][1]]);
        self::assertSame([
            'status' => 'past_due',
            'cancelAtPeriodEnd' => true,
            'priceId' => 'price_old',
            'periodStart' => 1700000000,
            'periodEnd' => 1702592000,
        ], $state);
    }

    public function testCancelSubscriptionNowEndsTheSubscriptionAtOnce(): void
    {
        (new StripeGateway('sk_test_dummy'))->cancelSubscriptionNow('sub_1');

        self::assertSame(['DELETE', '/v1/subscriptions/sub_1'], [$this->requests[0][0], $this->requests[0][1]]);
    }
}
