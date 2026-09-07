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

use App\Tests\DatabaseTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

final class BlockedUserTest extends DatabaseTestCase
{
    use EntityFactoryTrait;

    public function testCompositePrimaryKeyPersistsAndCanBeFound(): void
    {
        $blocker = $this->makeUser('blocker');
        $blocked = $this->makeUser('blocked');
        $this->em->flush();

        $blockedUser = new \App\Entity\Messaging\BlockedUser();
        $blockedUser->setBlocker($blocker);
        $blockedUser->setBlocked($blocked);
        $blockedUser->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($blockedUser);
        $this->em->flush();

        $blockerId = $blocker->getId();
        $blockedId = $blocked->getId();
        $this->em->clear();

        $found = $this->em->find(\App\Entity\Messaging\BlockedUser::class, ['blocker' => $blockerId, 'blocked' => $blockedId]);
        self::assertNotNull($found, 'la clé composite (blocker, blocked) doit permettre de retrouver la ligne');
    }

    public function testAddBlockedUserSynchronizesTheInverseSide(): void
    {
        $blocker = $this->makeUser('blocker2');
        $blocked = $this->makeUser('blocked2');
        $this->em->flush();

        $blockedUser = new \App\Entity\Messaging\BlockedUser();
        $blockedUser->setBlocked($blocked);
        $blockedUser->setCreatedAt(new \DateTimeImmutable());
        // * Pas de setBlocker() direct : on vérifie qu'addBlockedUser() fait la synchronisation.
        $blocker->addBlockedUser($blockedUser);

        self::assertSame($blocker, $blockedUser->getBlocker());

        $this->em->persist($blockedUser);
        $this->em->flush();
        self::assertNotNull($blockedUser->getBlocker());
    }

    public function testRemoveBlockedUserWithOrphanRemovalDeletesTheRow(): void
    {
        $blocker = $this->makeUser('blocker3');
        $blocked = $this->makeUser('blocked3');
        $this->em->flush();

        $blockedUser = new \App\Entity\Messaging\BlockedUser();
        $blockedUser->setBlocked($blocked);
        $blockedUser->setCreatedAt(new \DateTimeImmutable());
        $blocker->addBlockedUser($blockedUser);
        $this->em->persist($blockedUser);
        $this->em->flush();

        $blockerId = $blocker->getId()->toRfc4122();
        $blockedId = $blocked->getId()->toRfc4122();
        $this->em->clear();

        $reloadedBlocker = $this->em->find(\App\Entity\Identity\User::class, $blockerId);
        $toRemove = $reloadedBlocker->getBlockedUsers()->first();
        self::assertNotFalse($toRemove);

        // ! removeBlockedUser() met aussi blocker à null en PHP avant flush() : on vérifie que ça ne casse rien
        // ! puisque orphanRemoval doit de toute façon supprimer la ligne entière.
        $reloadedBlocker->removeBlockedUser($toRemove);
        $this->em->flush();

        $count = $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM messaging.blocked_users WHERE blocker_id = :blocker AND blocked_id = :blocked',
            ['blocker' => $blockerId, 'blocked' => $blockedId]
        );
        self::assertSame(0, (int) $count);
    }

    public function testDuplicateBlockPairViolatesThePrimaryKey(): void
    {
        $blocker = $this->makeUser('blocker4');
        $blocked = $this->makeUser('blocked4');
        $this->em->flush();

        $first = new \App\Entity\Messaging\BlockedUser();
        $first->setBlocker($blocker);
        $first->setBlocked($blocked);
        $first->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($first);
        $this->em->flush();

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO messaging.blocked_users (blocker_id, blocked_id, created_at) VALUES (:blocker, :blocked, now())',
            ['blocker' => $blocker->getId()->toRfc4122(), 'blocked' => $blocked->getId()->toRfc4122()]
        );
    }
}
