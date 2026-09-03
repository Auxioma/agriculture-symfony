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

use App\Entity\Identity\User;
use App\Tests\DatabaseTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class UserSecurityTest extends DatabaseTestCase
{
    use EntityFactoryTrait;

    public function testPasswordHasherHashesAndVerifiesCorrectly(): void
    {
        // ? Service pur, sans dépendance DB
        // ? ne tronque pas un hash Argon2/bcrypt réel une fois passé par la base.
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $user = $this->makeUser('secure');
        $hash = $hasher->hashPassword($user, 'CorrectHorseBatteryStaple');
        $user->setPasswordHash($hash);
        $this->em->flush();

        self::assertTrue($hasher->isPasswordValid($user, 'CorrectHorseBatteryStaple'));
        self::assertFalse($hasher->isPasswordValid($user, 'WrongPassword'));
    }

    public function testUpgradePasswordUpdatesTheHash(): void
    {
        $user = $this->makeUser('upgrade');
        $user->setPasswordHash('old-hash');
        $this->em->flush();

        $repository = $this->em->getRepository(User::class);
        $repository->upgradePassword($user, 'new-hash');

        self::assertSame('new-hash', $user->getPassword());

        $row = $this->em->getConnection()->fetchOne(
            'SELECT password_hash FROM identity.users WHERE id = :id',
            ['id' => $user->getId()->toRfc4122()]
        );
        self::assertSame('new-hash', $row);
    }

    public function testUpgradePasswordRejectsANonUserImplementation(): void
    {
        $repository = $this->em->getRepository(User::class);

        // * Classe anonyme minimale
        // * rejette bien tout ce qui n'est pas notre entité User, indépendamment de l'implémentation fournie
        $fakeUser = new class implements PasswordAuthenticatedUserInterface {
            public function getPassword(): ?string
            {
                return 'x';
            }
        };

        $this->expectException(UnsupportedUserException::class);
        $repository->upgradePassword($fakeUser, 'new-hash');
    }

    public function testUserCanBeFoundByEmailRegardlessOfCase(): void
    {
        // ? Insensibilité à la casse gérée par Postgres lui-même (colonne citext), pas par Doctrine/DQL.
        $user = $this->makeUser('provider');
        $user->setEmail('Marie.Curie@Example.com');
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->getRepository(User::class)
            ->findOneBy(['email' => 'marie.curie@example.com']);

        self::assertNotNull($found, 'citext doit permettre de retrouver un email stocké dans une autre casse');
    }

    public function testUserProviderLoadsUserByEmailRegardlessOfCase(): void
    {
        // ! ID de service interne Symfony (convention pour les providers "entity")
        // ! si Symfony change cette convention un jour, relancer `php bin/console debug:container security.user.provider`
        $user = $this->makeUser('provider2');
        $user->setEmail('Jean.Valjean@Example.com');
        $this->em->flush();
        $this->em->clear();

        /** @var UserProviderInterface $provider */
        $provider = self::getContainer()->get('security.user.provider.concrete.app_user_provider');
        // * loadUserByIdentifier() rend UserInterface --> on sait ici qu'il s'agit forcément de notre entité User.
        /** @var User $loaded */
        $loaded = $provider->loadUserByIdentifier('jean.valjean@example.com');

        self::assertSame($user->getId()->toRfc4122(), $loaded->getId()->toRfc4122());
    }

    public function testUserProviderThrowsWhenEmailIsUnknown(): void
    {
        /** @var UserProviderInterface $provider */
        $provider = self::getContainer()->get('security.user.provider.concrete.app_user_provider');

        $this->expectException(UserNotFoundException::class);
        $provider->loadUserByIdentifier('nobody_'.bin2hex(random_bytes(6)).'@test.local');
    }
}
