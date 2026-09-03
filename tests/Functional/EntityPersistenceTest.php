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

use App\Entity\Catalog\CategoryTranslation;
use App\Entity\Catalog\Country;
use App\Entity\Identity\UserPreference;
use App\Tests\DatabaseTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Vérifie en aller-retour les conventions de mapping les plus délicates : clé naturelle
 * (trouvemoi-agri-make-entity-guide.md 1.4), PK = FK (trouvemoi-agri-make-entity-guide.md 1.3) et clé composite
 * (trouvemoi-agri-make-entity-guide.md 1.2). Ce sont des cas faciles à casser subtilement, qui méritent d'être
 * verrouillés explicitement, indépendamment de toute logique métier.
 */
final class EntityPersistenceTest extends DatabaseTestCase
{
    use EntityFactoryTrait;

    public function testCountryUsesItsCodeAsNaturalKeyWithNoSurrogateId(): void
    {
        $country = new Country();
        $country->setCode('DE');
        $country->setName('Allemagne');
        $this->em->persist($country);
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->find(Country::class, 'DE');
        self::assertNotNull($found);
        self::assertSame('Allemagne', $found->getName());
    }

    public function testUserPreferenceIsKeyedByItsOwningUser(): void
    {
        $user = $this->makeUser('prefs');
        $this->em->flush();

        $preference = new UserPreference();
        $preference->setIdUser($user);
        $preference->setLocale('fr');
        $this->em->persist($preference);
        $this->em->flush();

        $userId = $user->getId();
        $this->em->clear();

        $found = $this->em->getRepository(UserPreference::class)->findOneBy(['idUser' => $userId]);
        self::assertNotNull($found);
        self::assertSame('fr', $found->getLocale());

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT user_id FROM identity.user_preferences WHERE user_id = :id',
            ['id' => $userId->toRfc4122()]
        );
        self::assertNotFalse($row, 'user_preferences must have no surrogate id column, only user_id as PK/FK');
    }

    public function testCategoryTranslationUsesACompositePrimaryKey(): void
    {
        $category = $this->makeCategory('Legumes');
        $this->em->flush();

        $translation = new CategoryTranslation();
        $translation->setCategory($category);
        $translation->setLocale('en');
        $translation->setName('Vegetables');
        $this->em->persist($translation);

        $secondLocale = new CategoryTranslation();
        $secondLocale->setCategory($category);
        $secondLocale->setLocale('de');
        $secondLocale->setName('Gemuese');
        $this->em->persist($secondLocale);

        $this->em->flush();
        $categoryId = $category->getId();
        $this->em->clear();

        $en = $this->em->find(CategoryTranslation::class, ['category' => $categoryId, 'locale' => 'en']);
        $de = $this->em->find(CategoryTranslation::class, ['category' => $categoryId, 'locale' => 'de']);

        self::assertNotNull($en);
        self::assertNotNull($de);
        self::assertSame('Vegetables', $en->getName());
        self::assertSame('Gemuese', $de->getName());
    }
}
