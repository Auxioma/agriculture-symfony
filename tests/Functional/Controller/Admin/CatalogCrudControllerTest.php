<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\CategoryCrudController;
use App\Controller\Admin\ProductCrudController;
use App\Controller\Admin\UnitCrudController;
use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Teste le module "Catégories et produits" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf :
 * "Catégories, sous-catégories, produits, unités, saisons, traductions, SEO"), y compris l'édition des
 * traductions (categoryTranslations/productTranslations) via un formulaire Symfony fait main -- pas un
 * CollectionField EasyAdmin, qui ne supporte pas les entités à clé primaire composite (voir le docblock de
 * CategoryCrudController).
 */
final class CatalogCrudControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private function loginAsAdmin(): User
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

        return $admin;
    }

    // * Pas de champ "traductions" dans ce formulaire : EasyAdmin 5.5.1 rejette catégoriquement toute
    // * entité à clé primaire composite (CategoryTranslation = category+locale), y compris utilisée
    // * indirectement via un CollectionField -- confirmé en testant, EntityFactory::getEntityMetadata() lève
    // * une RuntimeException avant même la soumission du formulaire. Reste géré via l'API/seed pour l'instant.
    public function testCreatingCategorySucceeds(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(CategoryCrudController::class)
            ->setAction(Action::NEW)
            ->generateUrl());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[method="post"]')->last()->form();
        $values = $form->getPhpValues();
        $rootKey = array_key_first($values);

        $values[$rootKey]['name'] = 'Légumes';
        $values[$rootKey]['slug'] = 'legumes';
        $values[$rootKey]['isActive'] = '1';

        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT id FROM catalog.categories WHERE slug = 'legumes'"
        );
        self::assertNotFalse($row);
    }

    public function testEditingProductSucceeds(): void
    {
        $this->loginAsAdmin();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'Tomates');
        $this->em->flush();

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(ProductCrudController::class)
            ->setAction(Action::EDIT)
            ->setEntityId($product->getId())
            ->generateUrl());
        self::assertResponseIsSuccessful();

        // * id="edit-{EntityName}-form" identifie sans ambiguïté le vrai formulaire d'édition -- filtrer
        // * par method="post" seul remonte aussi la modale de suppression générique, qui apparaît en dernier
        // * dans le DOM et fait planter les valeurs (déjà vu sur les tests Admin/User et Admin/ClientRequest).
        $form = $crawler->filter('form#edit-Product-form')->form();
        $values = $form->getPhpValues();
        $rootKey = array_key_first($values);
        $values[$rootKey]['name'] = 'Tomates anciennes';

        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT name FROM catalog.products WHERE id = :id',
            ['id' => $product->getId()->toRfc4122()]
        );
        self::assertSame('Tomates anciennes', $row['name']);
    }

    public function testEditingCategoryTranslationsSucceeds(): void
    {
        $this->loginAsAdmin();
        $category = $this->makeCategory('Légumes');
        $this->em->flush();

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(CategoryCrudController::class)
            ->setAction('editTranslations')
            ->setEntityId($category->getId())
            ->generateUrl());
        self::assertResponseIsSuccessful();

        // * form[method="post"] et non 'form' seul : la page hérite du layout EasyAdmin, qui inclut sa
        // * propre barre de recherche (GET) avant notre formulaire dans le DOM -- même piège que sur
        // * ConversationCrudController plus tôt.
        $form = $crawler->filter('form[method="post"]')->form();
        $values = $form->getPhpValues();
        $rootKey = array_key_first($values);
        $values[$rootKey]['en']['name'] = 'Vegetables';
        $values[$rootKey]['en']['seoTitle'] = 'Fresh vegetables';

        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT name, seo_title FROM catalog.category_translations WHERE category_id = :id AND locale = 'en'",
            ['id' => $category->getId()->toRfc4122()]
        );
        self::assertNotFalse($row);
        self::assertSame('Vegetables', $row['name']);
        self::assertSame('Fresh vegetables', $row['seo_title']);
    }

    // * Round-trip du transformateur keywords (chaîne séparée par des virgules <-> simple_array Doctrine).
    public function testEditingProductTranslationsPersistsKeywords(): void
    {
        $this->loginAsAdmin();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'Tomates');
        $this->em->flush();

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(ProductCrudController::class)
            ->setAction('editTranslations')
            ->setEntityId($product->getId())
            ->generateUrl());
        self::assertResponseIsSuccessful();

        // * form[method="post"] et non 'form' seul : la page hérite du layout EasyAdmin, qui inclut sa
        // * propre barre de recherche (GET) avant notre formulaire dans le DOM -- même piège que sur
        // * ConversationCrudController plus tôt.
        $form = $crawler->filter('form[method="post"]')->form();
        $values = $form->getPhpValues();
        $rootKey = array_key_first($values);
        $values[$rootKey]['fr']['name'] = 'Tomates anciennes';
        $values[$rootKey]['fr']['keywords'] = 'bio, local, saison';

        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT name, keywords FROM catalog.product_translations WHERE product_id = :id AND locale = 'fr'",
            ['id' => $product->getId()->toRfc4122()]
        );
        self::assertNotFalse($row);
        self::assertSame('Tomates anciennes', $row['name']);
        self::assertSame('bio,local,saison', $row['keywords']);
    }

    // * Effacer le nom d'une traduction existante doit la supprimer (orphanRemoval), pas la laisser vide en base.
    public function testClearingCategoryTranslationNameDeletesIt(): void
    {
        $this->loginAsAdmin();
        $category = $this->makeCategory('Fruits');
        $this->em->flush();

        $translation = new \App\Entity\Catalog\CategoryTranslation();
        $translation->setCategory($category);
        $translation->setLocale('en');
        $translation->setName('Fruits');
        $this->em->persist($translation);
        $this->em->flush();

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(CategoryCrudController::class)
            ->setAction('editTranslations')
            ->setEntityId($category->getId())
            ->generateUrl());
        self::assertResponseIsSuccessful();

        // * form[method="post"] et non 'form' seul : la page hérite du layout EasyAdmin, qui inclut sa
        // * propre barre de recherche (GET) avant notre formulaire dans le DOM -- même piège que sur
        // * ConversationCrudController plus tôt.
        $form = $crawler->filter('form[method="post"]')->form();
        $values = $form->getPhpValues();
        $rootKey = array_key_first($values);
        $values[$rootKey]['en']['name'] = '';

        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT 1 FROM catalog.category_translations WHERE category_id = :id AND locale = 'en'",
            ['id' => $category->getId()->toRfc4122()]
        );
        self::assertFalse($row);
    }

    public function testCreatingUnitSucceeds(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(UnitCrudController::class)
            ->setAction(Action::NEW)
            ->generateUrl());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[method="post"]')->last()->form();
        $values = $form->getPhpValues();
        $rootKey = array_key_first($values);
        $values[$rootKey]['code'] = 'kg';
        $values[$rootKey]['label'] = 'Kilogramme';
        $values[$rootKey]['unitType'] = 'weight';

        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT label FROM catalog.units WHERE code = 'kg'"
        );
        self::assertNotFalse($row);
        self::assertSame('Kilogramme', $row['label']);
    }
}
