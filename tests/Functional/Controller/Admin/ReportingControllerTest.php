<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\Billing\Invoice;
use App\Entity\Identity\User;
use App\Entity\Matching\ProducerReply;
use App\Enum\ReplyStatus;
use App\Enum\SubscriptionStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste le module "Reporting" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf :
 * "Demandes par région, taux de réponse, churn, revenus, catégories populaires"). Pas de CRUD ici : voir le
 * docblock de DashboardController::reporting() pour le détail des agrégats (regroupement par pays plutôt
 * que par région, churn en cumul global).
 */
final class ReportingControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private function loginAsAdmin(): User
    {
        $admin = $this->makeUserWithPassword('admin', 'motdepasse123');
        $admin->setRoles([User::ROLE_ADMIN]);
        $this->em->flush();

        $this->client->followRedirects(true);
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Se connecter', [
            '_username' => $admin->getEmail(),
            '_password' => 'motdepasse123',
        ]);

        return $admin;
    }

    public function testReportingPageAggregatesAllFiveIndicators(): void
    {
        $this->loginAsAdmin();

        $country = $this->makeCountry('FR', 'France');
        $category = $this->makeCategory('Fruits');
        $product = $this->makeProduct($category, 'Pommes');

        $client = $this->makeUser('client');
        $request = $this->makeClientRequest($client, $product);
        $request->setCountry($country);

        $producer = $this->makeProducerProfile($this->makeUser('producer'), $country);

        $reply = new ProducerReply();
        $reply->setRequest($request);
        $reply->setProducer($producer);
        $reply->setStatus(ReplyStatus::Sent);
        $this->em->persist($reply);

        $activeSubscription = $this->makeActiveSubscription($producer, SubscriptionStatus::Active);
        $cancelledSubscription = $this->makeActiveSubscription($producer, SubscriptionStatus::Cancelled);

        $invoice = new Invoice();
        $invoice->setSubscription($activeSubscription);
        $invoice->setAmount('49.99');
        $invoice->setStatus('paid');
        $invoice->setPaidAt(new \DateTimeImmutable());
        $this->em->persist($invoice);

        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/reporting');
        self::assertResponseIsSuccessful();

        $text = (string) $crawler->filter('body')->text();
        self::assertStringContainsString('France', $text);
        self::assertStringContainsString('Fruits', $text);
        // * 1 réponse pour 1 demande non-brouillon -- 100 % de taux de réponse.
        self::assertStringContainsString('100', $text);
        // * 1 abonnement annulé sur 2 -- 50 % de churn.
        self::assertStringContainsString('50', $text);
        self::assertStringContainsString('49,99', $text);
    }

    public function testReportingPageHandlesEmptyDataGracefully(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', '/admin/reporting');
        self::assertResponseIsSuccessful();
    }
}
