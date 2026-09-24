<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use OTPHP\TOTP;

/**
 * Teste la connexion admin lorsque la 2FA est activée sur le compte (cahier DevOps, "2FA obligatoire
 * admin") : mot de passe, puis code TOTP ou code de secours (à usage unique).
 */
final class TwoFactorLoginTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    /**
     * @return array{0: User, 1: list<string>}
     */
    private function makeAdminWithTwoFactor(): array
    {
        $user = $this->makeUserWithPassword('twofalogin', 'motdepasse123');
        $user->setRoles([User::ROLE_ADMIN]);
        $user->setTotpSecret(self::SECRET);
        $backupCodes = $user->generateNewBackupCodes(2);
        $this->em->flush();

        return [$user, $backupCodes];
    }

    private function submitPassword(User $user): void
    {
        $this->client->followRedirects(true);
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Se connecter', [
            '_username' => $user->getEmail(),
            '_password' => 'motdepasse123',
        ]);
    }

    private function submitCode(string $code): void
    {
        $this->client->submitForm('Se connecter', ['_auth_code' => $code]);
    }

    public function testPasswordAloneDoesNotOpenTheBackOffice(): void
    {
        [$user] = $this->makeAdminWithTwoFactor();

        $this->submitPassword($user);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="_auth_code"]');

        $this->client->request('GET', '/admin/user');
        self::assertSelectorExists('input[name="_auth_code"]');
    }

    public function testValidTotpCodeOpensTheBackOffice(): void
    {
        [$user] = $this->makeAdminWithTwoFactor();

        $this->submitPassword($user);
        $this->submitCode(TOTP::createFromSecret(self::SECRET)->now());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="_auth_code"]');
        self::assertStringContainsString('Tableau de bord', (string) $this->client->getResponse()->getContent());
    }

    public function testWrongCodeIsRejected(): void
    {
        [$user] = $this->makeAdminWithTwoFactor();

        $this->submitPassword($user);
        $this->submitCode('000000');

        self::assertSelectorExists('input[name="_auth_code"]');
        self::assertSelectorExists('.alert-danger');
    }

    public function testBackupCodeOpensTheBackOfficeOnlyOnce(): void
    {
        [$user, $backupCodes] = $this->makeAdminWithTwoFactor();

        $this->submitPassword($user);
        $this->submitCode($backupCodes[0]);

        self::assertSelectorNotExists('input[name="_auth_code"]');

        $this->client->request('GET', '/admin/logout');
        $this->em->clear();
        $this->submitPassword($this->em->find(User::class, $user->getId()));
        $this->submitCode($backupCodes[0]);

        self::assertSelectorExists('input[name="_auth_code"]');
        self::assertSelectorExists('.alert-danger');
    }
}
