<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste l'obligation de la 2FA sur le back-office (cahier DevOps, "2FA obligatoire admin") : un compte
 * admin/support sans 2FA est renvoyé vers l'écran d'activation, sauf pour les routes d'activation/déconnexion.
 */
final class RequireAdminTwoFactorListenerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    // * ADMIN_2FA_REQUIRED vaut 0 en test (.env.test) : réactivé ici avant le boot du kernel (setUp() du
    // * parent), la variable étant lue à l'instanciation du listener et non compilée dans le conteneur.
    protected function setUp(): void
    {
        $_ENV['ADMIN_2FA_REQUIRED'] = $_SERVER['ADMIN_2FA_REQUIRED'] = '1';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $_ENV['ADMIN_2FA_REQUIRED'] = $_SERVER['ADMIN_2FA_REQUIRED'] = '0';
        parent::tearDown();
    }

    /**
     * @param list<string> $roles
     */
    private function login(array $roles): User
    {
        $user = $this->makeUserWithPassword('require2fa', 'motdepasse123');
        $user->setRoles($roles);
        $this->em->flush();

        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Se connecter', [
            '_username' => $user->getEmail(),
            '_password' => 'motdepasse123',
        ]);

        return $user;
    }

    public function testAdminWithoutTwoFactorIsRedirectedToSetupFromAnyScreen(): void
    {
        $this->login([User::ROLE_ADMIN]);

        foreach (['/admin', '/admin/user', '/admin/settings'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseRedirects('/admin/2fa/setup', null, "{$path} devrait renvoyer vers l'activation.");
        }
    }

    public function testSupportWithoutTwoFactorIsRedirectedToo(): void
    {
        $this->login([User::ROLE_SUPPORT]);

        $this->client->request('GET', '/admin/user');

        self::assertResponseRedirects('/admin/2fa/setup');
    }

    public function testSetupScreenAndLogoutStayReachableWithoutTwoFactor(): void
    {
        $this->login([User::ROLE_ADMIN]);

        $this->client->request('GET', '/admin/2fa/setup');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/logout');
        self::assertResponseRedirects();
        self::assertStringContainsString('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testRedirectExplainsWhyThroughAFlashMessage(): void
    {
        $this->login([User::ROLE_ADMIN]);

        $this->client->request('GET', '/admin/user');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert', 'obligatoire');
    }

    public function testApiIsNotAffected(): void
    {
        $this->client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
    }
}
