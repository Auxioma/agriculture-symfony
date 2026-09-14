<?php

/**
 * Comptes utilisateurs de démo : un admin, des clients et des propriétaires de fiche producteur (les
 * ProducerProfile eux-mêmes sont créés par ProducerFixtures, qui dépend de celle-ci). Faker (fr_FR) génère
 * noms/emails -- contrairement au catalogue, ce sont des données qui n'ont pas besoin d'être "vraies",
 * juste variées pour peupler les listes du back-office. Mots de passe réellement hashés (pas de valeur
 * bidon comme dans tests/Fixtures/EntityFactoryTrait.php) pour pouvoir se connecter avec ces comptes.
 */

namespace App\DataFixtures;

use App\Entity\Identity\User;
use App\Enum\UserStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
    public const ADMIN_EMAIL = 'admin@trouvemoi.local';
    public const CLIENT_COUNT = 6;
    public const PRODUCER_OWNER_COUNT = 6;
    public const CLIENT_REFERENCE_PREFIX = 'user-client-';
    public const PRODUCER_OWNER_REFERENCE_PREFIX = 'user-producer-owner-';

    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        $admin = new User();
        $admin->setEmail(self::ADMIN_EMAIL);
        $admin->setPasswordHash($this->passwordHasher->hashPassword($admin, 'AdminDemo123!'));
        $admin->setRoles([User::ROLE_ADMIN]);
        $admin->setFirstName('Admin');
        $admin->setLastName('Démo');
        $admin->setStatus(UserStatus::Active);
        $manager->persist($admin);

        for ($i = 0; $i < self::CLIENT_COUNT; ++$i) {
            $client = new User();
            $client->setEmail($faker->unique()->safeEmail());
            $client->setPasswordHash($this->passwordHasher->hashPassword($client, 'ClientDemo123!'));
            $client->setRoles([User::ROLE_CLIENT]);
            $client->setFirstName($faker->firstName());
            $client->setLastName($faker->lastName());
            $client->setPhone($faker->phoneNumber());
            $client->setStatus(UserStatus::Active);
            $manager->persist($client);
            $this->addReference(self::CLIENT_REFERENCE_PREFIX.$i, $client);
        }

        for ($i = 0; $i < self::PRODUCER_OWNER_COUNT; ++$i) {
            $owner = new User();
            $owner->setEmail($faker->unique()->safeEmail());
            $owner->setPasswordHash($this->passwordHasher->hashPassword($owner, 'ProducerDemo123!'));
            $owner->setRoles([User::ROLE_PRODUCER]);
            $owner->setFirstName($faker->firstName());
            $owner->setLastName($faker->lastName());
            $owner->setPhone($faker->phoneNumber());
            $owner->setStatus(UserStatus::Active);
            $manager->persist($owner);
            $this->addReference(self::PRODUCER_OWNER_REFERENCE_PREFIX.$i, $owner);
        }

        $manager->flush();
    }
}
