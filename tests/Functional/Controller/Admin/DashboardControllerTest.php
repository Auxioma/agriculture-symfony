<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\Billing\Invoice;
use App\Entity\Identity\User;
use App\Enum\VerificationStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste le module "Tableau de bord" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf :
 * "Utilisateurs, producteurs, demandes, conversations, revenus, signalements, tickets support"), qui
 * remplaçait auparavant un simple redirect vers Utilisateurs.
 */
final class DashboardControllerTest extends ApiTestCase
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

    public function testDashboardShowsRealAggregateCounts(): void
    {
        $this->loginAsAdmin();

        $country = $this->makeCountry();
        $pendingOwner = $this->makeUser('pending-producer');
        $this->makeProducerProfile($pendingOwner, $country, VerificationStatus::Pending);

        $verifiedOwner = $this->makeUser('verified-producer');
        $verifiedProducer = $this->makeProducerProfile($verifiedOwner, $country, VerificationStatus::Verified);
        $subscription = $this->makeActiveSubscription($verifiedProducer);

        $invoice = new Invoice();
        $invoice->setSubscription($subscription);
        $invoice->setAmount('49.99');
        $invoice->setStatus('paid');
        $invoice->setPaidAt(new \DateTimeImmutable());
        $this->em->persist($invoice);

        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $text = (string) $crawler->filter('body')->text();
        // * Au moins l'admin connecté + les deux propriétaires producteurs créés ci-dessus.
        self::assertStringContainsString('Tableau de bord', $text);
        self::assertStringContainsString('en attente de validation', $text);
        self::assertStringContainsString('49,99', $text);
    }

    public function testDashboardTilesLinkToTheRightModules(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $hrefs = $crawler->filter('a')->extract(['href']);
        self::assertContains('http://localhost/admin/user', $hrefs);
        self::assertContains('http://localhost/admin/producer-profile', $hrefs);
        self::assertContains('http://localhost/admin/client-request', $hrefs);
        self::assertContains('http://localhost/admin/ticket', $hrefs);
    }
}
