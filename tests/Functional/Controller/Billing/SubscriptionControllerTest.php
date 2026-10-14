<?php

namespace App\Tests\Functional\Controller\Billing;

use App\Entity\Billing\CouponRedemption;
use App\Entity\Billing\Invoice;
use App\Entity\Billing\PlanPrice;
use App\Entity\Billing\SubscriptionPlan;
use App\Entity\Catalog\Country;
use App\Entity\Identity\User;
use App\Entity\Producer\ProducerProfile;
use App\Enum\BillingCycle;
use App\Enum\SubscriptionStatus;
use App\Service\Payment\FakePaymentGateway;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste les 6 routes du bloc Abonnements (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf) :
 * GET /api/subscription/plans (public), GET .../current, GET .../invoices, POST .../cancel, .../checkout,
 * .../change-plan. checkout/change-plan utilisent FakePaymentGateway (config/services.yaml, bloc when@test) --
 * aucun appel Stripe réel ici ; le flux réel a été validé manuellement (cf. StripeWebhookControllerTest pour
 * la partie webhook).
 */

final class SubscriptionControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    // * $countryCode (et non un objet Country) pour les tests à deux producteurs : repasser le même objet
    // * PHP d'un appel à l'autre casse dès que la frontière d'une requête HTTP a été traversée entre-temps
    // * (ServicesResetter vide l'identity map après chaque requête -- l'objet devient détaché). Recharger
    // * par code à chaque appel via find() est le seul moyen sûr d'obtenir un Country toujours "managed".
    private function registerProducerAndLogin(string $emailPrefix = 'producer', ?string $countryCode = null): array
    {
        $country = $countryCode !== null
            ? $this->em->getRepository(Country::class)->find($countryCode)
            : $this->makeCountry();
        // * makeUserWithPassword() (pas makeUser()) : ce compte doit pouvoir se logger pour de vrai
        // * via /api/auth/login plus bas.
        $owner = $this->makeUserWithPassword($emailPrefix, 'motdepasse123');
        $owner->setRoles([User::ROLE_PRODUCER]);
        $producer = $this->makeProducerProfile($owner, $country, farmName: 'Ferme '.$emailPrefix);
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $owner->getEmail(),
            'password' => 'motdepasse123',
        ]));
        self::assertResponseIsSuccessful();
        $token = json_decode($this->client->getResponse()->getContent(), true)['token'];

        // ! ServicesResetter vide l'identity map après la requête HTTP ci-dessus : on recharge $producer
        // ! avant tout persist() ultérieur qui le référence (ex. makeActiveSubscription).
        $producer = $this->em->getRepository(ProducerProfile::class)->find($producer->getId());

        return [$token, $producer];
    }

    // * providerPriceId paramétrable : indispensable pour checkout()/changePlan(), qui rejettent tout
    // * PlanPrice sans correspondance Stripe (cf. SubscriptionController::checkout()).
    private function makePlanPriceWithProviderId(string $providerPriceId): PlanPrice
    {
        $plan = new SubscriptionPlan();
        $plan->setCode('plan-'.bin2hex(random_bytes(6)));
        $plan->setName('Plan test');
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

    public function testListPlansReturnsOnlyActiveOnes(): void
    {
        $this->makeCountry();
        $this->em->flush();
        [, $producer] = $this->registerProducerAndLogin('producer', 'FR');
        $active = $this->makeActiveSubscription($producer);
        $this->em->flush();

        // * makeActiveSubscription() active déjà son plan/prix par défaut -- on désactive celui-ci pour
        // * vérifier que la route filtre bien, plutôt que d'en créer un deuxième depuis zéro.
        $inactivePlan = $active->getPlanPrice()->getPlan();
        $inactivePlan->setIsActive(false);
        $this->em->flush();

        [, $producer2] = $this->registerProducerAndLogin('producer2', 'FR');
        $activePlan = $this->makeActiveSubscription($producer2)->getPlanPrice()->getPlan();
        $this->em->flush();

        // * Aucun header Authorization : cette route doit être accessible sans authentification.
        $this->client->request('GET', '/api/subscription/plans');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $codes = array_column($data, 'code');
        self::assertContains($activePlan->getCode(), $codes);
        self::assertNotContains($inactivePlan->getCode(), $codes);
    }

    public function testGetCurrentSubscriptionReturnsData(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $subscription = $this->makeActiveSubscription($producer);
        $this->em->flush();

        $this->client->request('GET', '/api/subscription/current', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($subscription->getPlanPrice()->getPlan()->getCode(), $data['planCode']);
        self::assertSame('active', $data['status']);
        self::assertFalse($data['cancelAtPeriodEnd']);
        // * de quoi afficher "Plan Pro, 24 €/mois, 24/30 demandes utilisées" : prix, offre, quota et demandes du mois
        self::assertSame($subscription->getPlanPrice()->getId()->toRfc4122(), $data['planPriceId']);
        self::assertArrayHasKey('amount', $data);
        self::assertArrayHasKey('currencyCode', $data);
        self::assertSame(0, $data['requestsThisMonth']);
        self::assertNull($data['requestsQuota']);
    }

    private function gateway(): FakePaymentGateway
    {
        return static::getContainer()->get(FakePaymentGateway::class);
    }

    public function testGetCurrentSubscriptionReportsQuotaAndRequestsOfTheMonth(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $subscription = $this->makeActiveSubscription($producer);
        $subscription->getPlanPrice()->getPlan()->setLimits(['requests_per_month' => 30]);
        $request = $this->makeClientRequest($this->makeUser('client'));
        $match = new \App\Entity\Matching\RequestMatch();
        $match->setRequest($request);
        $match->setProducer($producer);
        $this->em->persist($match);
        $this->em->flush();

        $this->client->request('GET', '/api/subscription/current', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(1, $data['requestsThisMonth']);
        self::assertSame(30, $data['requestsQuota']);
    }

    public function testGetCurrentSubscriptionReturns204WhenNone(): void
    {
        [$token] = $this->registerProducerAndLogin();

        $this->client->request('GET', '/api/subscription/current', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(204);
    }

    public function testGetCurrentSubscriptionRejectsAccountWithoutProducerProfile(): void
    {
        $token = $this->registerClientAndLogin();

        $this->client->request('GET', '/api/subscription/current', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testListInvoicesReturnsOnlyMine(): void
    {
        $this->makeCountry();
        $this->em->flush();
        [$token, $producer] = $this->registerProducerAndLogin('producer', 'FR');
        $subscription = $this->makeActiveSubscription($producer);

        $invoice = new Invoice();
        $invoice->setSubscription($subscription);
        $invoice->setAmount('19.99');
        $invoice->setStatus('paid');
        $this->em->persist($invoice);
        $this->em->flush();

        [, $otherProducer] = $this->registerProducerAndLogin('other-producer', 'FR');
        $otherSubscription = $this->makeActiveSubscription($otherProducer);
        $otherInvoice = new Invoice();
        $otherInvoice->setSubscription($otherSubscription);
        $otherInvoice->setAmount('9.99');
        $otherInvoice->setStatus('paid');
        $this->em->persist($otherInvoice);
        $this->em->flush();

        $this->client->request('GET', '/api/subscription/invoices', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame('19.99', $data[0]['amount']);
    }

    public function testCancelSubscriptionSucceeds(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $subscription = $this->makeActiveSubscription($producer);
        $this->em->flush();
        $id = $subscription->getId()->toRfc4122();

        $this->client->request('POST', '/api/subscription/cancel', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT cancel_at_period_end, status FROM billing.subscriptions WHERE id = :id',
            ['id' => $id]
        );
        self::assertTrue((bool) $row['cancel_at_period_end']);
        // * Résiliation "douce" : le statut reste "active" jusqu'à la fin de la période déjà payée.
        self::assertSame('active', $row['status']);
    }

    public function testCancelSubscriptionTellsStripeToStopAtPeriodEnd(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $this->makeActiveSubscription($producer)->setProviderSubscriptionId('sub_test_cancel');
        $this->em->flush();

        $this->client->request('POST', '/api/subscription/cancel', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        self::assertSame([['cancel', 'sub_test_cancel']], $this->gateway()->calls);
    }

    public function testCancelSubscriptionKeepsItActiveWhenStripeIsUnavailable(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $subscription = $this->makeActiveSubscription($producer);
        $subscription->setProviderSubscriptionId('sub_test_cancel_down');
        $this->em->flush();
        $id = $subscription->getId()->toRfc4122();
        $this->gateway()->failure = new \Stripe\Exception\InvalidArgumentException('api_key cannot be the empty string');
        $logSize = $this->logSize();

        $this->client->request('POST', '/api/subscription/cancel', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(502);
        self::assertStringContainsString('Appel Stripe échoué : cancel', $this->logsSince($logSize));
        self::assertFalse((bool) $this->em->getConnection()->fetchOne('SELECT cancel_at_period_end FROM billing.subscriptions WHERE id = :id', ['id' => $id]));
    }

    public function testCancelSubscriptionReturns404WhenNoActiveSubscription(): void
    {
        [$token] = $this->registerProducerAndLogin();

        $this->client->request('POST', '/api/subscription/cancel', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(404);
    }

    // --- reprise d'un abonnement dont l'annulation était programmée ------------------------------------------------------------

    private function post(string $path, string $token): void
    {
        $this->client->request('POST', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    private function cancelAtPeriodEnd(string $subscriptionId): bool
    {
        return (bool) $this->em->getConnection()->fetchOne('SELECT cancel_at_period_end FROM billing.subscriptions WHERE id = :id', ['id' => $subscriptionId]);
    }

    public function testResumeSubscriptionTellsStripeAndClearsTheCancellation(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $subscription = $this->makeActiveSubscription($producer);
        $subscription->setProviderSubscriptionId('sub_test_resume');
        $subscription->setCancelAtPeriodEnd(true);
        $this->em->flush();
        $id = $subscription->getId()->toRfc4122();

        $this->post('/api/subscription/resume', $token);

        self::assertResponseIsSuccessful();
        self::assertSame([['resume', 'sub_test_resume']], $this->gateway()->calls);
        self::assertFalse($this->cancelAtPeriodEnd($id));
    }

    public function testResumeSubscriptionRefusesWhenNothingIsBeingCancelled(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $this->makeActiveSubscription($producer)->setProviderSubscriptionId('sub_test_resume_none');
        $this->em->flush();

        $this->post('/api/subscription/resume', $token);

        self::assertResponseStatusCodeSame(409);
        self::assertSame([], $this->gateway()->calls);
    }

    public function testResumeSubscriptionReturns404WhenNoActiveSubscription(): void
    {
        [$token] = $this->registerProducerAndLogin();

        $this->post('/api/subscription/resume', $token);

        self::assertResponseStatusCodeSame(404);
    }

    public function testResumeSubscriptionKeepsTheCancellationWhenStripeIsUnavailable(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $subscription = $this->makeActiveSubscription($producer);
        $subscription->setProviderSubscriptionId('sub_test_resume_down');
        $subscription->setCancelAtPeriodEnd(true);
        $this->em->flush();
        $id = $subscription->getId()->toRfc4122();
        $this->gateway()->failure = new \Stripe\Exception\InvalidArgumentException('api_key cannot be the empty string');
        $logSize = $this->logSize();

        $this->post('/api/subscription/resume', $token);

        self::assertResponseStatusCodeSame(502);
        self::assertStringContainsString('Appel Stripe échoué : resume', $this->logsSince($logSize));
        self::assertTrue($this->cancelAtPeriodEnd($id));
    }

    public function testResumeSubscriptionAnswers409WhenStripeSaysTheSubscriptionIsOver(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $subscription = $this->makeActiveSubscription($producer);
        $subscription->setProviderSubscriptionId('sub_test_resume_over');
        $subscription->setCancelAtPeriodEnd(true);
        $this->em->flush();
        $id = $subscription->getId()->toRfc4122();
        $this->gateway()->failure = new \Stripe\Exception\InvalidRequestException('A canceled subscription can only update its cancellation_details and metadata.');

        $this->post('/api/subscription/resume', $token);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('souscrivez de nouveau', json_decode($this->client->getResponse()->getContent(), true)['error']);
        self::assertTrue($this->cancelAtPeriodEnd($id));
    }

    public function testResumeSubscriptionRejectsAccountWithoutProducerProfile(): void
    {
        $token = $this->registerClientAndLogin();

        $this->post('/api/subscription/resume', $token);

        self::assertResponseStatusCodeSame(403);
    }

    // --- changement d'offre planifié ---------------------------------------------------------------------------------------------

    private function getScheduledChange(string $token): void
    {
        $this->client->request('GET', '/api/subscription/scheduled-change', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    public function testScheduledChangeReturnsThePlannedPlanAndDate(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $this->makeActiveSubscription($producer)->setProviderSubscriptionId('sub_test_planned');
        $planned = $this->makePlanPriceWithProviderId('price_test_planned');
        $this->em->flush();
        $this->gateway()->scheduledChanges['sub_test_planned'] = ['priceId' => 'price_test_planned', 'startsAt' => 1893456000];

        $this->getScheduledChange($token);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($planned->getId()->toRfc4122(), $data['planPriceId']);
        self::assertSame('Plan test', $data['planName']);
        self::assertSame('monthly', $data['billingCycle']);
        self::assertSame(1893456000, (new \DateTimeImmutable($data['effectiveAt']))->getTimestamp());
    }

    public function testScheduledChangeIs204WhenNothingIsPlanned(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $this->makeActiveSubscription($producer)->setProviderSubscriptionId('sub_test_not_planned');
        $this->em->flush();

        $this->getScheduledChange($token);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([['scheduled_change', 'sub_test_not_planned']], $this->gateway()->calls);
    }

    public function testScheduledChangeIs204AndAsksNothingWithoutAStripeSubscription(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $this->makeActiveSubscription($producer);
        $this->em->flush();

        $this->getScheduledChange($token);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->gateway()->calls);
    }

    public function testScheduledChangeIgnoresAPriceUnknownLocally(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $this->makeActiveSubscription($producer)->setProviderSubscriptionId('sub_test_unknown_price');
        $this->em->flush();
        $this->gateway()->scheduledChanges['sub_test_unknown_price'] = ['priceId' => 'price_nobody_knows', 'startsAt' => 1893456000];

        $this->getScheduledChange($token);

        self::assertResponseStatusCodeSame(204);
    }

    public function testScheduledChangeAnswers502AndLogsWhenStripeFails(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $this->makeActiveSubscription($producer)->setProviderSubscriptionId('sub_test_planned_down');
        $this->em->flush();
        $this->gateway()->failure = new \Stripe\Exception\InvalidArgumentException('api_key cannot be the empty string');
        $logSize = $this->logSize();

        $this->getScheduledChange($token);

        self::assertResponseStatusCodeSame(502);
        self::assertStringContainsString('Appel Stripe échoué : scheduled-change', $this->logsSince($logSize));
    }

    public function testCancelSubscriptionRejectsAccountWithoutProducerProfile(): void
    {
        $token = $this->registerClientAndLogin();

        $this->client->request('POST', '/api/subscription/cancel', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCheckoutCreatesSessionAndReturnsUrl(): void
    {
        [$token] = $this->registerProducerAndLogin();
        $planPrice = $this->makePlanPriceWithProviderId('price_test_checkout');
        $this->em->flush();

        $this->client->request('POST', '/api/subscription/checkout', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['planPriceId' => $planPrice->getId()->toRfc4122()]));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        // * URL fixe renvoyée par FakePaymentGateway (config/services.yaml, bloc when@test) -- confirme que
        // * le contrôleur appelle bien PaymentGatewayInterface plutôt que le vrai StripeClient.
        self::assertSame('https://checkout.stripe.test/fake-session', $data['checkoutUrl']);
        // * retour vers le front (FRONTEND_URL), sur la page "Mon abonnement"
        [, , $successUrl, $cancelUrl] = $this->gateway()->calls[0];
        self::assertStringEndsWith('/producer/subscription?checkout=success', $successUrl);
        self::assertStringEndsWith('/producer/subscription?checkout=cancelled', $cancelUrl);
    }

    public function testALatePaymentSubscriptionStillCountsAsExistingAndCanBeCancelled(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $subscription = $this->makeActiveSubscription($producer, SubscriptionStatus::PastDue);
        $subscription->setProviderSubscriptionId('sub_test_late');
        $planPrice = $this->makePlanPriceWithProviderId('price_test_late');
        $this->em->flush();
        $id = $subscription->getId()->toRfc4122();
        $post = function (string $url, array $body = []) use ($token): int {
            $this->client->request('POST', $url, server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ], content: json_encode($body));

            return $this->client->getResponse()->getStatusCode();
        };
        $body = ['planPriceId' => $planPrice->getId()->toRfc4122()];

        // * pas de second abonnement ni de changement d'offre tant que le paiement est en retard : on met la carte à jour
        self::assertSame(409, $post('/api/subscription/checkout', $body));
        self::assertStringContainsString('moyen de paiement', json_decode($this->client->getResponse()->getContent(), true)['error']);
        self::assertSame(409, $post('/api/subscription/change-plan', $body));
        self::assertSame([], $this->gateway()->calls);

        // * mais on peut toujours annuler
        self::assertSame(200, $post('/api/subscription/cancel'));
        self::assertTrue((bool) $this->em->getConnection()->fetchOne('SELECT cancel_at_period_end FROM billing.subscriptions WHERE id = :id', ['id' => $id]));
        self::assertSame([['cancel', 'sub_test_late']], $this->gateway()->calls);
    }

    public function testATrialSubscriptionCountsAsExistingToo(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $this->makeActiveSubscription($producer, SubscriptionStatus::Trialing);
        $planPrice = $this->makePlanPriceWithProviderId('price_test_trial_checkout');
        $this->em->flush();

        $this->client->request('POST', '/api/subscription/checkout', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['planPriceId' => $planPrice->getId()->toRfc4122()]));

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('utilisez change-plan', json_decode($this->client->getResponse()->getContent(), true)['error']);
    }

    public function testCheckoutAnswers502WhenStripeIsUnavailable(): void
    {
        [$token] = $this->registerProducerAndLogin();
        $planPrice = $this->makePlanPriceWithProviderId('price_test_checkout_down');
        $this->em->flush();
        $this->gateway()->failure = new \Stripe\Exception\InvalidArgumentException('api_key cannot be the empty string');
        $logSize = $this->logSize();

        $this->client->request('POST', '/api/subscription/checkout', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['planPriceId' => $planPrice->getId()->toRfc4122()]));

        self::assertResponseStatusCodeSame(502);
        self::assertStringContainsString('Appel Stripe échoué : checkout', $this->logsSince($logSize));
        self::assertStringContainsString('api_key cannot be the empty string', $this->logsSince($logSize));
        self::assertStringContainsString('indisponible', json_decode($this->client->getResponse()->getContent(), true)['error']);
    }

    public function testCheckoutRejectsWhenAlreadyActive(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $this->makeActiveSubscription($producer);
        $planPrice = $this->makePlanPriceWithProviderId('price_test_checkout_2');
        $this->em->flush();

        $this->client->request('POST', '/api/subscription/checkout', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['planPriceId' => $planPrice->getId()->toRfc4122()]));

        self::assertResponseStatusCodeSame(409);
    }

    public function testCheckoutRejectsUnknownPlanPrice(): void
    {
        [$token] = $this->registerProducerAndLogin();

        $this->client->request('POST', '/api/subscription/checkout', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['planPriceId' => \Symfony\Component\Uid\Uuid::v4()->toRfc4122()]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCheckoutWithValidCouponSucceeds(): void
    {
        [$token] = $this->registerProducerAndLogin();
        $planPrice = $this->makePlanPriceWithProviderId('price_test_coupon_ok');
        $this->makeCoupon('PROMO10');
        $this->em->flush();

        $this->client->request('POST', '/api/subscription/checkout', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['planPriceId' => $planPrice->getId()->toRfc4122(), 'couponCode' => 'PROMO10']));

        self::assertResponseStatusCodeSame(201);
    }

    // * CITEXT (voir la migration de billing.coupons) : la casse ne doit jamais empêcher un client de saisir
    // * un coupon correctement -- vérifie que ce n'est pas une coïncidence de findOneBy() côté ORM.
    public function testCheckoutWithCouponIsCaseInsensitive(): void
    {
        [$token] = $this->registerProducerAndLogin();
        $planPrice = $this->makePlanPriceWithProviderId('price_test_coupon_case');
        $this->makeCoupon('PROMO10');
        $this->em->flush();

        $this->client->request('POST', '/api/subscription/checkout', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['planPriceId' => $planPrice->getId()->toRfc4122(), 'couponCode' => 'promo10']));

        self::assertResponseStatusCodeSame(201);
    }

    public function testCheckoutRejectsUnknownCoupon(): void
    {
        [$token] = $this->registerProducerAndLogin();
        $planPrice = $this->makePlanPriceWithProviderId('price_test_coupon_unknown');
        $this->em->flush();

        $this->client->request('POST', '/api/subscription/checkout', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['planPriceId' => $planPrice->getId()->toRfc4122(), 'couponCode' => 'INCONNU']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCheckoutRejectsExpiredCoupon(): void
    {
        [$token] = $this->registerProducerAndLogin();
        $planPrice = $this->makePlanPriceWithProviderId('price_test_coupon_expired');
        $coupon = $this->makeCoupon('EXPIRE');
        $coupon->setValidUntil(new \DateTimeImmutable('-1 day'));
        $this->em->flush();

        $this->client->request('POST', '/api/subscription/checkout', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['planPriceId' => $planPrice->getId()->toRfc4122(), 'couponCode' => 'EXPIRE']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCheckoutRejectsCouponAtMaxRedemptions(): void
    {
        $this->makeCountry();
        $this->em->flush();
        [, $producer] = $this->registerProducerAndLogin('producer', 'FR');
        $planPrice = $this->makePlanPriceWithProviderId('price_test_coupon_maxed');
        $coupon = $this->makeCoupon('MAXED', maxRedemptions: 1);
        $subscription = $this->makeActiveSubscription($producer);
        $redemption = new CouponRedemption();
        $redemption->setCoupon($coupon);
        $redemption->setProducer($producer);
        $redemption->setSubscription($subscription);
        $this->em->persist($redemption);
        $this->em->flush();

        [$otherToken] = $this->registerProducerAndLogin('other', 'FR');
        $this->client->request('POST', '/api/subscription/checkout', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$otherToken,
        ], content: json_encode(['planPriceId' => $planPrice->getId()->toRfc4122(), 'couponCode' => 'MAXED']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCheckoutRejectsCouponNotSynchronizedWithStripe(): void
    {
        [$token] = $this->registerProducerAndLogin();
        $planPrice = $this->makePlanPriceWithProviderId('price_test_coupon_unsynced');
        $this->makeCoupon('UNSYNCED', providerCouponId: null);
        $this->em->flush();

        $this->client->request('POST', '/api/subscription/checkout', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['planPriceId' => $planPrice->getId()->toRfc4122(), 'couponCode' => 'UNSYNCED']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCheckoutRejectsAccountWithoutProducerProfile(): void
    {
        $token = $this->registerClientAndLogin();
        $planPrice = $this->makePlanPriceWithProviderId('price_test_checkout_3');
        $this->em->flush();

        $this->client->request('POST', '/api/subscription/checkout', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['planPriceId' => $planPrice->getId()->toRfc4122()]));

        self::assertResponseStatusCodeSame(403);
    }

    public function testChangePlanSucceeds(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $subscription = $this->makeActiveSubscription($producer);
        $subscription->setProviderSubscriptionId('sub_test_changeplan');
        $newPlanPrice = $this->makePlanPriceWithProviderId('price_test_changeplan_new');
        $this->em->flush();

        $this->client->request('POST', '/api/subscription/change-plan', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['planPriceId' => $newPlanPrice->getId()->toRfc4122()]));

        self::assertResponseStatusCodeSame(202);
        // * même position que l'offre actuelle (aucune) : ni montée ni descente, donc tout de suite
        self::assertSame('immediate', json_decode($this->client->getResponse()->getContent(), true)['effect']);
        self::assertSame([['update_price', 'sub_test_changeplan', 'price_test_changeplan_new', false]], $this->gateway()->calls);
    }

    public function testChangePlanUpgradeIsImmediateAndDowngradeWaitsForTheNextRenewal(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $subscription = $this->makeActiveSubscription($producer);
        $subscription->setProviderSubscriptionId('sub_test_updown');
        $subscription->getPlanPrice()->getPlan()->setPosition(2);
        $higher = $this->makePlanPriceWithProviderId('price_test_higher');
        $higher->getPlan()->setPosition(3);
        $lower = $this->makePlanPriceWithProviderId('price_test_lower');
        $lower->getPlan()->setPosition(1);
        $this->em->flush();
        $periodEnd = $subscription->getCurrentPeriodEnd()->format(DATE_ATOM);
        $post = function (PlanPrice $price) use ($token): array {
            $this->client->request('POST', '/api/subscription/change-plan', server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ], content: json_encode(['planPriceId' => $price->getId()->toRfc4122()]));
            self::assertResponseStatusCodeSame(202);

            return json_decode($this->client->getResponse()->getContent(), true);
        };

        self::assertSame(['effect' => 'immediate', 'effectiveAt' => null], $post($higher));
        $downgrade = $post($lower);
        self::assertSame('next_renewal', $downgrade['effect']);
        self::assertEquals(new \DateTimeImmutable($periodEnd), new \DateTimeImmutable($downgrade['effectiveAt']));
        self::assertSame([false, true], array_column($this->gateway()->calls, 3));
    }

    public function testChangePlanRejectsTheCurrentOfferAndAnswers502WhenStripeIsUnavailable(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $subscription = $this->makeActiveSubscription($producer);
        $subscription->setProviderSubscriptionId('sub_test_same');
        $subscription->getPlanPrice()->setProviderPriceId('price_test_same');
        $other = $this->makePlanPriceWithProviderId('price_test_other');
        $this->em->flush();
        $post = function (PlanPrice $price) use ($token): void {
            $this->client->request('POST', '/api/subscription/change-plan', server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ], content: json_encode(['planPriceId' => $price->getId()->toRfc4122()]));
        };

        $post($subscription->getPlanPrice());
        self::assertResponseStatusCodeSame(422);

        $this->gateway()->failure = new \Stripe\Exception\InvalidArgumentException('api_key cannot be the empty string');
        $logSize = $this->logSize();
        $post($other);
        self::assertResponseStatusCodeSame(502);
        self::assertStringContainsString('Appel Stripe échoué : change-plan', $this->logsSince($logSize));
    }

    public function testChangePlanTellsTheProducerWhenThePaymentOfTheChangeFails(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $subscription = $this->makeActiveSubscription($producer);
        $subscription->setProviderSubscriptionId('sub_test_declined');
        $other = $this->makePlanPriceWithProviderId('price_test_declined');
        $this->em->flush();

        // * Stripe répond 402 (carte refusée) et n'applique pas le changement
        $declined = new \Stripe\Exception\CardException('Your card was declined.');
        $declined->setHttpStatus(402);
        $this->gateway()->failure = $declined;
        $this->client->request('POST', '/api/subscription/change-plan', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['planPriceId' => $other->getId()->toRfc4122()]));

        self::assertResponseStatusCodeSame(402);
        self::assertStringContainsString("n'a pas changé", json_decode($this->client->getResponse()->getContent(), true)['error']);
    }

    public function testChangePlanRejectsWhenNoActiveSubscription(): void
    {
        [$token] = $this->registerProducerAndLogin();
        $planPrice = $this->makePlanPriceWithProviderId('price_test_changeplan_2');
        $this->em->flush();

        $this->client->request('POST', '/api/subscription/change-plan', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['planPriceId' => $planPrice->getId()->toRfc4122()]));

        self::assertResponseStatusCodeSame(404);
    }

    public function testChangePlanRejectsUnsynchronizedSubscription(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        // * makeActiveSubscription() ne définit jamais providerSubscriptionId -- exactement le cas
        // * "abonnement jamais synchronisé avec Stripe" que ce test vérifie.
        $this->makeActiveSubscription($producer);
        $planPrice = $this->makePlanPriceWithProviderId('price_test_changeplan_3');
        $this->em->flush();

        $this->client->request('POST', '/api/subscription/change-plan', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['planPriceId' => $planPrice->getId()->toRfc4122()]));

        self::assertResponseStatusCodeSame(409);
    }
}
