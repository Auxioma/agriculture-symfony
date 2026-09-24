<?php

namespace App\Tests\Functional\Command;

use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Teste app:reset-admin-2fa (cahier DevOps, "2FA obligatoire admin" -- filet de sécurité pour un admin qui a
 * perdu son application d'authentification et ses codes de secours).
 */
final class ResetAdminTwoFactorCommandTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private function run2faReset(string $email): CommandTester
    {
        $application = new Application(static::getContainer()->get('kernel'));
        $tester = new CommandTester($application->find('app:reset-admin-2fa'));
        $tester->execute(['email' => $email]);

        return $tester;
    }

    public function testResetClearsSecretAndBackupCodesAndIsAudited(): void
    {
        $user = $this->makeUser('locked');
        $user->setRoles([User::ROLE_ADMIN]);
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $user->generateNewBackupCodes(2);
        $this->em->flush();

        $tester = $this->run2faReset($user->getEmail());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT totp_secret, backup_codes FROM identity.users WHERE id = :id',
            ['id' => $user->getId()->toRfc4122()]
        );
        self::assertNull($row['totp_secret']);
        self::assertNull($row['backup_codes']);

        $audit = $this->em->getConnection()->fetchAssociative(
            "SELECT record_id FROM audit.audit_logs WHERE action = 'admin_2fa_reset' AND record_id = :id",
            ['id' => $user->getId()->toRfc4122()]
        );
        self::assertNotFalse($audit);
    }

    public function testResetOnAccountWithoutTwoFactorChangesNothing(): void
    {
        $user = $this->makeUser('plain');
        $this->em->flush();

        $tester = $this->run2faReset($user->getEmail());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('rien à réinitialiser', $tester->getDisplay());
        $audit = $this->em->getConnection()->fetchAssociative(
            "SELECT 1 FROM audit.audit_logs WHERE action = 'admin_2fa_reset' AND record_id = :id",
            ['id' => $user->getId()->toRfc4122()]
        );
        self::assertFalse($audit);
    }

    public function testUnknownEmailFails(): void
    {
        $tester = $this->run2faReset('personne@test.local');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
