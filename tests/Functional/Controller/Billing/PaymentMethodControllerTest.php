<?php

namespace App\Tests\Functional\Controller\Billing;

use App\Entity\Billing\Subscription;
use App\Entity\Identity\User;
use App\Entity\Producer\ProducerProfile;
use App\Enum\SubscriptionStatus;
use App\Service\Payment\FakePaymentGateway;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste les cartes bancaires de l'abonnement producteur (cahier fonctionnel, dashboard producteur : "moyen de
 * paiement") : liste, ajout (page Stripe), carte par défaut et suppression. FakePaymentGateway remplace Stripe :
 * il garde deux cartes (Visa par défaut, Mastercard) et note chaque appel.
 */
final class PaymentMethodControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    /**
     * @return array{0: string, 1: ProducerProfile}
     */
    private function loginAsProducer(?string $providerSubscriptionId = 'sub_test_cards', SubscriptionStatus $status = SubscriptionStatus::Active): array
    {
        $owner = $this->makeUserWithPassword('producer', 'motdepasse123');
        $owner->setRoles([User::ROLE_PRODUCER]);
        $producer = $this->makeProducerProfile($owner, $this->makeCountry(), farmName: 'Ferme test');
        $subscription = $this->makeActiveSubscription($producer, $status);
        $subscription->setProviderSubscriptionId($providerSubscriptionId);
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $owner->getEmail(),
            'password' => 'motdepasse123',
        ]));

        return [json_decode($this->client->getResponse()->getContent(), true)['token'], $producer];
    }

    private function gateway(): FakePaymentGateway
    {
        return static::getContainer()->get(FakePaymentGateway::class);
    }

    private function call(string $method, string $url, string $token): int
    {
        $this->client->request($method, $url, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        return $this->client->getResponse()->getStatusCode();
    }

    public function testListReturnsTheCardsOfTheSubscription(): void
    {
        [$token] = $this->loginAsProducer();

        self::assertSame(200, $this->call('GET', '/api/subscription/payment-methods', $token));

        $cards = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(['pm_fake_visa', 'pm_fake_mastercard'], array_column($cards, 'id'));
        self::assertSame(['brand' => 'visa', 'last4' => '4242', 'expMonth' => 8, 'expYear' => 2028, 'isDefault' => true], array_diff_key($cards[0], ['id' => 1]));
        self::assertSame([['list_payment_methods', 'sub_test_cards']], $this->gateway()->calls);
    }

    public function testCardsAreAvailableWhileThePaymentIsLate(): void
    {
        [$token] = $this->loginAsProducer(status: SubscriptionStatus::PastDue);

        self::assertSame(200, $this->call('GET', '/api/subscription/payment-methods', $token));
    }

    public function testNothingToManageWithoutAStripeSubscription(): void
    {
        [$token] = $this->loginAsProducer(providerSubscriptionId: null);

        self::assertSame(404, $this->call('GET', '/api/subscription/payment-methods', $token));
        self::assertSame(404, $this->call('POST', '/api/subscription/payment-methods/setup', $token));
        self::assertSame([], $this->gateway()->calls);
    }

    public function testAccountWithoutProducerProfileIsRefused(): void
    {
        $token = $this->registerClientAndLogin();

        self::assertSame(403, $this->call('GET', '/api/subscription/payment-methods', $token));
        self::assertSame(403, $this->call('DELETE', '/api/subscription/payment-methods/pm_fake_visa', $token));
    }

    public function testSetupReturnsTheStripePageAndComesBackToTheCardsPage(): void
    {
        [$token] = $this->loginAsProducer();

        self::assertSame(201, $this->call('POST', '/api/subscription/payment-methods/setup', $token));

        self::assertSame('https://checkout.stripe.test/fake-setup', json_decode($this->client->getResponse()->getContent(), true)['setupUrl']);
        [, $subscriptionId, $successUrl, $cancelUrl] = $this->gateway()->calls[0];
        self::assertSame('sub_test_cards', $subscriptionId);
        self::assertStringEndsWith('/producer/subscription/payment-methods?card=added', $successUrl);
        self::assertStringEndsWith('/producer/subscription/payment-methods?card=cancelled', $cancelUrl);
    }

    public function testMakeDefaultSwitchesTheDefaultCard(): void
    {
        [$token] = $this->loginAsProducer();

        self::assertSame(204, $this->call('POST', '/api/subscription/payment-methods/pm_fake_mastercard/default', $token));

        self::assertSame(['set_default', 'sub_test_cards', 'pm_fake_mastercard'], $this->gateway()->calls[1]);
        self::assertSame([false, true], array_column($this->gateway()->paymentMethods, 'isDefault'));
    }

    public function testDeleteRemovesACardButNotTheDefaultOne(): void
    {
        [$token] = $this->loginAsProducer();

        self::assertSame(409, $this->call('DELETE', '/api/subscription/payment-methods/pm_fake_visa', $token));
        self::assertCount(2, $this->gateway()->paymentMethods);

        self::assertSame(204, $this->call('DELETE', '/api/subscription/payment-methods/pm_fake_mastercard', $token));
        self::assertSame(['pm_fake_visa'], array_column($this->gateway()->paymentMethods, 'id'));
        self::assertContains(['detach', 'pm_fake_mastercard'], $this->gateway()->calls);
    }

    public function testACardOfAnotherCustomerIsUnknown(): void
    {
        [$token] = $this->loginAsProducer();

        self::assertSame(404, $this->call('DELETE', '/api/subscription/payment-methods/pm_someone_else', $token));
        self::assertSame(404, $this->call('POST', '/api/subscription/payment-methods/pm_someone_else/default', $token));
        self::assertNotContains('detach', array_column($this->gateway()->calls, 0));
        self::assertNotContains('set_default', array_column($this->gateway()->calls, 0));
    }

    public function testStripeFailureAnswers502(): void
    {
        [$token] = $this->loginAsProducer();
        $this->gateway()->failure = new \Stripe\Exception\InvalidArgumentException('api_key cannot be the empty string');

        self::assertSame(502, $this->call('GET', '/api/subscription/payment-methods', $token));
        self::assertSame(502, $this->call('POST', '/api/subscription/payment-methods/setup', $token));
        self::assertSame(502, $this->call('DELETE', '/api/subscription/payment-methods/pm_fake_mastercard', $token));
    }
}
