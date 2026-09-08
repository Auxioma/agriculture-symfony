<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste la connexion au back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf), qui passe par
 * un firewall à session (form_login) totalement distinct du firewall JWT stateless utilisé par l'API.
 */
final class SecurityControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    public function testAdminLoginSucceedsAndReachesDashboard(): void
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

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Utilisateurs', (string) $this->client->getResponse()->getContent());
    }

    public function testAdminLoginFailsWithWrongPassword(): void
    {
        $admin = $this->makeUserWithPassword('admin', 'motdepasse123');
        $admin->setRoles([User::ROLE_ADMIN]);
        $this->em->flush();

        $this->client->followRedirects(true);
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Se connecter', [
            '_username' => $admin->getEmail(),
            '_password' => 'mot-de-passe-invalide',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-danger');
    }

    // * ROLE_CLIENT (rôle par défaut de makeUserWithPassword()) peut s'authentifier -- le firewall admin ne
    // * vérifie que les identifiants -- mais access_control doit ensuite bloquer l'accès à /admin (403), qui
    // * exige explicitement ROLE_ADMIN ou ROLE_SUPER_ADMIN.
    public function testNonAdminUserIsForbiddenFromAdmin(): void
    {
        $client = $this->makeUserWithPassword('client', 'motdepasse123');
        $this->em->flush();

        $this->client->followRedirects(true);
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Se connecter', [
            '_username' => $client->getEmail(),
            '_password' => 'motdepasse123',
        ]);

        self::assertResponseStatusCodeSame(403);
    }
}
