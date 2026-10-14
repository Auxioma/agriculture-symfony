<?php

namespace App\Tests\Functional\Service;

use App\Service\Payment\StripeGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\Exception\InvalidArgumentException;
use Stripe\Exception\InvalidRequestException;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;

/**
 * Ce que StripeGateway envoie réellement à Stripe, sans réseau : le client HTTP du SDK est remplacé par un faux qui
 * enregistre chaque appel et répond avec un abonnement type. (Les contrôleurs, eux, sont testés avec FakePaymentGateway.)
 */

final class StripeGatewayTest extends TestCase
{
    private const CHECKOUT_SESSION = '{"id":"cs_1","object":"checkout.session","url":"https://checkout.stripe.test/cs_1"}';

    private const SCHEDULE = '{"id":"sub_sched_1","object":"subscription_schedule","phases":[{"start_date":1700000000,"end_date":1702592000,"items":[{"price":"price_old"}]}]}';

    private const PRICE = '{"id":"price_new","object":"price","recurring":{"interval":"year","interval_count":1}}';

    private const SUBSCRIPTION = '{"id":"sub_1","object":"subscription","status":"past_due","cancel_at_period_end":true,"schedule":null,"items":{"object":"list","data":[{"id":"si_1","object":"subscription_item","current_period_start":1700000000,"current_period_end":1702592000,"price":{"id":"price_old","object":"price"}}],"has_more":false,"url":"/v1/subscription_items"}}';

    /** @var \ArrayObject<int, array{string, string, array<string, mixed>}> méthode, chemin et paramètres de chaque appel */
    private \ArrayObject $requests;

    protected function setUp(): void
    {
        $this->answerWith();
    }

    /**
     * Installe le faux Stripe. $subscription : l'abonnement renvoyé pour tous les chemins non listés ; $statuses : chemins
     * auxquels Stripe répond par une erreur 400 ("unknown parameter") au lieu de la réponse type.
     *
     * @param array<string, int> $statuses
     */
    private function answerWith(string $subscription = self::SUBSCRIPTION, array $statuses = []): void
    {
        $this->requests = new \ArrayObject();
        $bodies = [
            '/v1/checkout/sessions' => self::CHECKOUT_SESSION,
            '/v1/subscription_schedules' => self::SCHEDULE,
            '/v1/subscription_schedules/sub_sched_1' => self::SCHEDULE,
            '/v1/prices/price_new' => self::PRICE,
        ];

        ApiRequestor::setHttpClient(new class($this->requests, $bodies, $subscription, $statuses) implements ClientInterface {
            /**
             * @param \ArrayObject<int, array{string, string, array<string, mixed>}> $requests
             * @param array<string, string>                                          $bodies   réponse par chemin ; $default pour les autres
             * @param array<string, int>                                             $statuses chemins qui répondent par une erreur
             */
            public function __construct(private readonly \ArrayObject $requests, private readonly array $bodies, private readonly string $default, private readonly array $statuses)
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
                $path = (string) parse_url($absUrl, PHP_URL_PATH);
                $this->requests->append([strtoupper($method), $path, $params]);

                if (isset($this->statuses[$path])) {
                    return ['{"error":{"type":"invalid_request_error","message":"Received unknown parameter: phases[iterations]"}}', $this->statuses[$path], []];
                }

                return [$this->bodies[$path] ?? $this->default, 200, []];
            }
        });
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
    }

    private function gateway(string $key = 'sk_test_dummy', string $environment = 'dev'): StripeGateway
    {
        return new StripeGateway($key, $environment);
    }

    public function testAnImmediateChangeIsRefusedByStripeWhenItsPaymentFails(): void
    {
        $this->gateway()->updateSubscriptionPrice('sub_1', 'price_new');

        $update = $this->requests[1];
        self::assertSame(['POST', '/v1/subscriptions/sub_1'], [$update[0], $update[1]]);
        self::assertSame([['id' => 'si_1', 'price' => 'price_new']], $update[2]['items']);
        self::assertSame('create_prorations', $update[2]['proration_behavior']);
        self::assertSame('error_if_incomplete', $update[2]['payment_behavior']);
    }

    public function testRetrieveSubscriptionReadsTheStateFromStripe(): void
    {
        $state = $this->gateway()->retrieveSubscription('sub_1');

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
        $this->gateway()->cancelSubscriptionNow('sub_1');

        self::assertSame(['DELETE', '/v1/subscriptions/sub_1'], [$this->requests[0][0], $this->requests[0][1]]);
    }

    public function testACheckoutSessionExpiresAfterHalfAnHourToLimitParallelPayments(): void
    {
        $before = time();
        $url = $this->gateway()->createCheckoutSession('price_1', 'producteur@example.test', ['producer_id' => 'p1'], 'https://front.test/ok', 'https://front.test/ko');

        self::assertSame('https://checkout.stripe.test/cs_1', $url);
        $expiresAt = $this->requests[0][2]['expires_at'];
        // * Stripe exige au moins 30 minutes : on garde une marge pour le temps de l'appel, mais loin des 24 h par défaut
        self::assertGreaterThan($before + 1800, $expiresAt);
        self::assertLessThanOrEqual(time() + 3600, $expiresAt);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function liveKeysOutsideProduction(): iterable
    {
        yield 'clé secrète en dev' => ['sk_live_abc', 'dev'];
        yield 'clé restreinte en test' => ['rk_live_abc', 'test'];
    }

    #[DataProvider('liveKeysOutsideProduction')]
    public function testALiveKeyIsRefusedOutsideProduction(string $key, string $environment): void
    {
        try {
            $this->gateway($key, $environment)->retrieveSubscription('sub_1');
            self::fail('Une clé live aurait dû être refusée.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('live refusée hors production', $e->getMessage());
        }

        self::assertCount(0, $this->requests, 'rien ne part vers Stripe');
    }

    public function testLiveKeysAreAllowedInProductionAndTestKeysEverywhere(): void
    {
        foreach ([['sk_live_abc', 'prod'], ['sk_test_abc', 'dev'], ['rk_test_abc', 'test'], ['sk_test_abc', 'prod']] as [$key, $environment]) {
            $this->gateway($key, $environment)->retrieveSubscription('sub_1');
        }

        self::assertCount(4, $this->requests);
    }

    /**
     * @return list<string> "MÉTHODE chemin" de chaque appel envoyé à Stripe, dans l'ordre
     */
    private function calls(): array
    {
        return array_map(static fn (array $r) => $r[0].' '.$r[1], $this->requests->getArrayCopy());
    }

    private const WITH_SCHEDULE = '{"id":"sub_1","object":"subscription","status":"active","cancel_at_period_end":false,"schedule":"sub_sched_old","items":{"object":"list","data":[{"id":"si_1","object":"subscription_item","current_period_start":1700000000,"current_period_end":1702592000,"price":{"id":"price_old","object":"price"}}],"has_more":false,"url":"/v1/subscription_items"}}';

    public function testADowngradeIsScheduledForTheNextRenewalAndLastsOnePeriodOfTheNewPrice(): void
    {
        $this->gateway()->updateSubscriptionPrice('sub_1', 'price_new', true);

        self::assertSame([
            'GET /v1/subscriptions/sub_1',
            'POST /v1/subscription_schedules',
            'GET /v1/prices/price_new',
            'POST /v1/subscription_schedules/sub_sched_1',
        ], $this->calls());
        $update = $this->requests[3][2];
        self::assertSame('release', $update['end_behavior']);
        self::assertSame(['items' => [['price' => 'price_old', 'quantity' => 1]], 'start_date' => 1700000000, 'end_date' => 1702592000], $update['phases'][0]);
        // * la durée de la 2e phase vient de l'intervalle du NOUVEAU prix (ici annuel) ; "iterations" n'existe plus dans cette version de l'API
        self::assertSame(['items' => [['price' => 'price_new', 'quantity' => 1]], 'duration' => ['interval' => 'year', 'interval_count' => 1]], $update['phases'][1]);
    }

    public function testAChangeAlreadyPlannedIsReleasedBeforeAnyOtherChange(): void
    {
        $this->answerWith(self::WITH_SCHEDULE);

        $this->gateway()->updateSubscriptionPrice('sub_1', 'price_new');

        self::assertSame(['GET /v1/subscriptions/sub_1', 'POST /v1/subscription_schedules/sub_sched_old/release', 'POST /v1/subscriptions/sub_1'], $this->calls());
    }

    public function testAChangeAlreadyPlannedIsReleasedBeforeCancelling(): void
    {
        $this->answerWith(self::WITH_SCHEDULE);

        $this->gateway()->cancelSubscriptionAtPeriodEnd('sub_1');

        self::assertSame(['GET /v1/subscriptions/sub_1', 'POST /v1/subscription_schedules/sub_sched_old/release', 'POST /v1/subscriptions/sub_1'], $this->calls());
        // * le SDK envoie les booléens sous forme de texte
        self::assertSame('true', $this->requests[2][2]['cancel_at_period_end']);
    }

    public function testNothingIsReleasedWhenNoChangeIsPlanned(): void
    {
        $this->gateway()->cancelSubscriptionAtPeriodEnd('sub_1');

        self::assertSame(['GET /v1/subscriptions/sub_1', 'POST /v1/subscriptions/sub_1'], $this->calls());
    }

    public function testAHalfMadeScheduleIsReleasedWhenStripeRefusesItsPhases(): void
    {
        $this->answerWith(self::SUBSCRIPTION, ['/v1/subscription_schedules/sub_sched_1' => 400]);

        try {
            $this->gateway()->updateSubscriptionPrice('sub_1', 'price_new', true);
            self::fail('Le refus de Stripe aurait dû remonter.');
        } catch (InvalidRequestException $e) {
            self::assertStringContainsString('unknown parameter', $e->getMessage());
        }

        // * sans ça, la planification vide resterait attachée à l'abonnement et bloquerait ensuite l'annulation
        self::assertSame('POST /v1/subscription_schedules/sub_sched_1/release', $this->calls()[array_key_last($this->calls())]);
    }
}
