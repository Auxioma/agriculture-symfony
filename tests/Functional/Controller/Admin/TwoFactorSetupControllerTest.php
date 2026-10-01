<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use OTPHP\TOTP;

/**
 * Teste l'écran d'auto-activation de la 2FA (cahier DevOps, "2FA obligatoire admin" -- étape "Écran
 * d'activation"). Le code TOTP attendu est recalculé avec la même bibliothèque que
 * Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticator (voir son code source :
 * OTPHP\TOTP::createFromSecret(), mêmes secret/algorithme/période/chiffres que
 * User::getTotpAuthenticationConfiguration()).
 */
final class TwoFactorSetupControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private function loginAsAdmin(): User
    {
        $user = $this->makeUserWithPassword('twofa', 'motdepasse123');
        $user->setRoles([User::ROLE_ADMIN]);
        $this->em->flush();

        $this->client->followRedirects(true);
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Se connecter', [
            '_username' => $user->getEmail(),
            '_password' => 'motdepasse123',
        ]);

        return $user;
    }

    public function testSetupPageShowsQrCodeAndSecretWhenNotYetEnabled(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/admin/2fa/setup');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('img[alt="QR code de configuration de la double authentification"]');
        self::assertNotSame('', trim($crawler->filter('code')->first()->text()));
    }

    public function testSubmittingTheCorrectCodeActivatesTwoFactorAndRevealsBackupCodesOnce(): void
    {
        $user = $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/admin/2fa/setup');
        $secret = trim($crawler->filter('code')->first()->text());

        $form = $crawler->filter('form')->form();
        $form['code'] = TOTP::createFromSecret($secret)->now();
        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Ces codes ne seront plus jamais affichés',
            (string) $this->client->getResponse()->getContent()
        );

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT totp_secret, backup_codes FROM identity.users WHERE id = :id',
            ['id' => $user->getId()->toRfc4122()]
        );
        self::assertSame($secret, $row['totp_secret']);
        self::assertNotNull($row['backup_codes']);

        $audit = $this->em->getConnection()->fetchAssociative(
            "SELECT record_id FROM audit.audit_logs WHERE action = 'admin_2fa_enabled' AND record_id = :id",
            ['id' => $user->getId()->toRfc4122()]
        );
        self::assertNotFalse($audit);

        // * La révélation des codes de secours est à usage unique (session) : recharger la page "done"
        // * directement ne doit plus rien montrer (redirigée vers le tableau de bord -- suivie automatiquement
        // * ici, followRedirects(true) ayant été activé par loginAsAdmin()).
        $this->client->request('GET', '/admin/2fa/setup/done');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            'Ces codes ne seront plus jamais affichés',
            (string) $this->client->getResponse()->getContent()
        );
    }

    public function testSubmittingAWrongCodeDoesNotActivateTwoFactor(): void
    {
        $user = $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/admin/2fa/setup');
        $form = $crawler->filter('form')->form();
        $form['code'] = '000000';
        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-danger', 'Code invalide');

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT totp_secret FROM identity.users WHERE id = :id',
            ['id' => $user->getId()->toRfc4122()]
        );
        self::assertNull($row['totp_secret']);
    }

    public function testSetupPageRedirectsToDashboardWhenAlreadyEnabled(): void
    {
        $user = $this->loginAsAdmin();
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $this->em->flush();

        // * followRedirects(true) (loginAsAdmin()) suit automatiquement la redirection vers le tableau de
        // * bord -- on vérifie donc la page finale plutôt que le statut 302 brut.
        $this->client->request('GET', '/admin/2fa/setup');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Tableau de bord', (string) $this->client->getResponse()->getContent());
    }
}
