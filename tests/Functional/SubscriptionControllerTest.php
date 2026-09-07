<?php

namespace App\Tests\Functional;

use App\Entity\Billing\Invoice;
use App\Entity\Catalog\Country;
use App\Entity\Identity\User;
use App\Entity\Producer\ProducerProfile;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste les 4 routes non-paiement de Abonnements (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf) :
 * GET /api/subscription/plans (public), GET .../current, GET .../invoices, POST .../cancel.
 * checkout et change-plan sont hors scope de ce round -- ils attendent l'intégration Stripe.
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

    public function testCancelSubscriptionReturns404WhenNoActiveSubscription(): void
    {
        [$token] = $this->registerProducerAndLogin();

        $this->client->request('POST', '/api/subscription/cancel', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testCancelSubscriptionRejectsAccountWithoutProducerProfile(): void
    {
        $token = $this->registerClientAndLogin();

        $this->client->request('POST', '/api/subscription/cancel', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(403);
    }
}
