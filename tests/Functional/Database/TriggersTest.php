<?php

/**
 * Copyright(c)2026 TrouveMoi (https://trouvemoi.com)
 *
 * Ce fichier fait partie d’un projet développé par Auxioma Web Agency pour l’entreprise.
 * Tous droits réservés.
 *
 * Ce code source est la propriété exclusive de Auxioma Web Agency et.
 * Toute reproduction, modification, distribution ou utilisation sans autorisation préalable est interdite.
 */

namespace App\Tests\Functional;

use App\Tests\Fixtures\EntityFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TriggersTest extends KernelTestCase
{
    use EntityFactoryTrait;
    protected EntityManagerInterface $em;

    public function testUpdatedAtTriggerBumpsTimestampOnRawUpdate(): void
    {
        $user = $this->makeUser('trigger');
        $this->em->flush();

        $connection = $this->em->getConnection();
        $id = $user->getId()->toRfc4122();

        $before = $connection->fetchOne('SELECT updated_at FROM identity.users WHERE id = :id', ['id' => $id]);

        sleep(1);
        // !Un UPDATE SQL brut, qui contourne le #[PreUpdate] de Doctrine isole le trigger de la base lui-même.
        $connection->executeStatement("UPDATE identity.users SET first_name = 'Changed' WHERE id = :id", ['id' => $id]);

        $after = $connection->fetchOne('SELECT updated_at FROM identity.users WHERE id = :id', ['id' => $id]);

        self::assertGreaterThan($before, $after, 'the trg_users_updated_at trigger should refresh updated_at on every UPDATE');
    }

    public function testAuditTriggerLogsInsertUpdateAndDelete(): void
    {
        $user = $this->makeUser('audited');
        $this->em->flush();

        $id = $user->getId()->toRfc4122();
        $connection = $this->em->getConnection();

        $connection->executeStatement("UPDATE identity.users SET first_name = 'Edited' WHERE id = :id", ['id' => $id]);
        $connection->executeStatement('DELETE FROM identity.users WHERE id = :id', ['id' => $id]);

        // Pas de tri par created_at : la colonne est TIMESTAMP(0) (pas de sous-seconde), et les 3 opérations
        // arrivent souvent dans la même seconde — leur ordre entre elles n'est alors pas garanti par Postgres.
        $actions = $connection->fetchFirstColumn(
            "SELECT action FROM audit.audit_logs WHERE table_name = 'users' AND record_id = :id",
            ['id' => $id]
        );
        sort($actions);

        self::assertSame(['DELETE', 'INSERT', 'UPDATE'], $actions);

        $updateRow = $connection->fetchAssociative(
            "SELECT old_data, new_data FROM audit.audit_logs WHERE table_name = 'users' AND record_id = :id AND action = 'UPDATE'",
            ['id' => $id]
        );
        $old = json_decode($updateRow['old_data'], true);
        $new = json_decode($updateRow['new_data'], true);
        self::assertNull($old['first_name']);
        self::assertSame('Edited', $new['first_name']);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement(
            'DELETE FROM identity.users WHERE email LIKE :pattern',
            ['pattern' => 'trigger_%@test.local']
        );
        parent::tearDown();
    }
}
