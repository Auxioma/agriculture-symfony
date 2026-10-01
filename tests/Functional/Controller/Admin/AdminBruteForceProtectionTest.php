<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use OTPHP\TOTP;

/**
 * Teste la protection contre la force brute du back-office (cahier DevOps, sécurité "Authentification" :
 * "protection brute force") : mot de passe (login_throttling de security.yaml) et code 2FA (en plus, limite par
 * compte sans tenir compte de l'IP -- TwoFactorAttemptThrottleListener).
 */
final class AdminBruteForceProtectionTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private function makeAdmin(bool $withTwoFactor = false): User
    {
        $user = $this->makeUserWithPassword('brute', 'motdepasse123');
        $user->setRoles([User::ROLE_ADMIN]);
        if ($withTwoFactor) {
            $user->setTotpSecret(self::SECRET);
        }
        $this->em->flush();

        return $user;
    }

    private function fromIp(string $ip): void
    {
        $this->client->setServerParameter('REMOTE_ADDR', $ip);
    }

    private function submitPassword(User $user, string $password = 'motdepasse123'): void
    {
        $this->client->followRedirects(true);
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Se connecter', ['_username' => $user->getEmail(), '_password' => $password]);
    }

    private function submitCode(string $code): void
    {
        $this->client->submitForm('Se connecter', ['_auth_code' => $code]);
    }

    private function validCode(): string
    {
        return TOTP::createFromSecret(self::SECRET)->now();
    }

    public function testAdminLoginIsBlockedAfterFiveWrongPasswordsEvenWithTheRightOne(): void
    {
        $user = $this->makeAdmin();

        for ($i = 0; $i < 5; ++$i) {
            $this->submitPassword($user, 'mauvais-mot-de-passe');
        }
        $this->submitPassword($user);

        self::assertSelectorTextContains('.alert-danger', 'Trop de tentatives');
        $this->client->request('GET', '/admin');
        self::assertStringContainsString('/admin/login', $this->client->getRequest()->getUri());
    }

    public function testATwoFactorCodeCannotBeGuessedByChangingIpAddress(): void
    {
        $user = $this->makeAdmin(true);
        $this->fromIp('10.9.0.1');
        $this->submitPassword($user);
        self::assertSelectorExists('input[name="_auth_code"]');

        // * Chaque essai part d'une IP différente : le limiteur compte+IP de Symfony ne voit jamais deux fois la même clé.
        for ($i = 2; $i <= 6; ++$i) {
            $this->fromIp('10.9.0.'.$i);
            $this->submitCode('00000'.$i);
        }
        $this->fromIp('10.9.0.7');
        $this->submitCode($this->validCode());

        self::assertSelectorTextContains('.alert-danger', 'Trop de tentatives');
        self::assertSelectorExists('input[name="_auth_code"]');
        $this->client->request('GET', '/admin/user');
        self::assertSelectorExists('input[name="_auth_code"]');
    }

    public function testASuccessfulTwoFactorLoginResetsTheCounter(): void
    {
        $user = $this->makeAdmin(true);

        foreach (['10.8.0.1', '10.8.1.1'] as $round => $baseIp) {
            if ($round > 0) {
                $this->client->request('GET', '/admin/logout');
            }
            $this->fromIp($baseIp);
            $this->submitPassword($user);
            for ($i = 2; $i <= 5; ++$i) {
                $this->fromIp(substr($baseIp, 0, -1).$i);
                $this->submitCode('00000'.$i);
            }
            $this->fromIp(substr($baseIp, 0, -1).'6');
            $this->submitCode($this->validCode());

            self::assertSelectorNotExists('input[name="_auth_code"]', "Tour {$round} : 4 erreurs puis le bon code doivent passer.");
            self::assertStringContainsString('Tableau de bord', (string) $this->client->getResponse()->getContent());
        }
    }

    public function testAnotherAccountIsNotAffectedByTheLimit(): void
    {
        $locked = $this->makeAdmin(true);
        $this->fromIp('10.7.0.1');
        $this->submitPassword($locked);
        for ($i = 2; $i <= 7; ++$i) {
            $this->fromIp('10.7.0.'.$i);
            $this->submitCode('00000'.$i);
        }

        $other = $this->makeUserWithPassword('brute-other', 'motdepasse123');
        $other->setRoles([User::ROLE_ADMIN]);
        $other->setTotpSecret('KRSXG5CTMVRXEZLU');
        $this->em->flush();
        $this->client->request('GET', '/admin/logout');
        $this->fromIp('10.7.1.1');
        $this->submitPassword($other);
        $this->submitCode(TOTP::createFromSecret('KRSXG5CTMVRXEZLU')->now());

        self::assertSelectorNotExists('input[name="_auth_code"]');
    }
}
