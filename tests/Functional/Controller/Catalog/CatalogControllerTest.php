<?php

namespace App\Tests\Functional\Controller\Catalog;

use App\Entity\Catalog\Category;
use App\Entity\Catalog\CategoryTranslation;
use App\Entity\Catalog\Label;
use App\Entity\Catalog\LabelTranslation;
use App\Entity\Catalog\ProductTranslation;
use App\Enum\VerificationStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste aussi ?locale= (cahier fonctionnel, "SEO avancé, multi-langue complet") : les traductions
 * catalogue (Category/Product/LabelTranslation) sont désormais lues par ces routes, avec repli sur les
 * champs de base (français) tant qu'aucune traduction n'existe pour la locale demandée.
 */
final class CatalogControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    public function testListCategoriesReturnsOnlyActiveOnes(): void
    {
        $active = $this->makeCategory('Fruits');
        $active->setIsActive(true);
        $inactive = $this->makeCategory('Brouillon');
        $inactive->setIsActive(false);
        $this->em->flush();

        // *Aucun header Authorization : ces routes doivent être accessibles sans authentification
        $this->client->request('GET', '/api/categories');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $names = array_column($data, 'name');
        self::assertContains('Fruits', $names);
        self::assertNotContains('Brouillon', $names);
    }

    public function testListCategoriesReturnsTranslatedFieldsForRequestedLocale(): void
    {
        $category = $this->makeCategory('Fruits');
        $category->setIsActive(true);
        $translation = new CategoryTranslation();
        $translation->setCategory($category);
        $translation->setLocale('en');
        $translation->setName('Fruits');
        $translation->setDescription('Fresh fruits.');
        $translation->setSeoTitle('Buy fresh fruits');
        $translation->setSeoDescription('Fresh fruits directly from local producers.');
        $this->em->persist($translation);
        $this->em->flush();

        $this->client->request('GET', '/api/categories?locale=en');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $row = current(array_filter($data, static fn (array $c) => $c['id'] === $category->getId()->toRfc4122()));
        self::assertSame('Fresh fruits.', $row['description']);
        self::assertSame('Buy fresh fruits', $row['seoTitle']);
        self::assertSame('Fresh fruits directly from local producers.', $row['seoDescription']);
    }

    public function testListCategoriesFallsBackToBaseNameWhenNoTranslationForLocale(): void
    {
        $category = $this->makeCategory('Légumes');
        $category->setIsActive(true);
        $this->em->flush();

        $this->client->request('GET', '/api/categories?locale=de');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $row = current(array_filter($data, static fn (array $c) => $c['id'] === $category->getId()->toRfc4122()));
        self::assertSame('Légumes', $row['name']);
        self::assertNull($row['description']);
        self::assertNull($row['seoTitle']);
    }

    public function testGetProductReturnsData(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'Tomates');
        $product->setIsActive(true);
        $this->em->flush();

        $this->client->request('GET', '/api/products/'.$product->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Tomates', $data['name']);
    }

    public function testGetProductReturnsTranslatedFieldsForRequestedLocale(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'Tomates');
        $product->setIsActive(true);
        $translation = new ProductTranslation();
        $translation->setProduct($product);
        $translation->setLocale('es');
        $translation->setName('Tomates');
        $translation->setDescription('Tomates frescos.');
        $translation->setKeywords(['tomate', 'legumbre']);
        $this->em->persist($translation);
        $this->em->flush();

        $this->client->request('GET', '/api/products/'.$product->getId()->toRfc4122().'?locale=es');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Tomates frescos.', $data['description']);
        self::assertSame(['tomate', 'legumbre'], $data['keywords']);
    }

    public function testListProductsReturnsTranslatedNamesForRequestedLocale(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'Pommes');
        $product->setIsActive(true);
        $translation = new ProductTranslation();
        $translation->setProduct($product);
        $translation->setLocale('en');
        $translation->setName('Apples');
        $this->em->persist($translation);
        $this->em->flush();

        $this->client->request('GET', '/api/products?locale=en');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $names = array_column($data, 'name');
        self::assertContains('Apples', $names);
        self::assertNotContains('Pommes', $names);
    }

    public function testGetProductReturns404ForUnknownId(): void
    {
        $this->client->request('GET', '/api/products/'.\Symfony\Component\Uid\Uuid::v4()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
    }

    public function testGetProductReturns404ForInactiveProduct(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'Brouillon');
        $product->setIsActive(false);
        $this->em->flush();

        // * Cohérent avec la liste : un produit désactivé n'est pas listé, son lien direct non plus
        $this->client->request('GET', '/api/products/'.$product->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
    }

    public function testGetProductReturns404ForMalformedId(): void
    {
        // * Une colonne uuid refuse "abc" : sans contrôle du format, la base lèverait une erreur (HTTP 500)
        $this->client->request('GET', '/api/products/abc');

        self::assertResponseStatusCodeSame(404);
    }

    public function testListCategoriesFallsBackToDefaultLocaleWhenLocaleIsInvalid(): void
    {
        $category = $this->makeCategory('Légumes');
        $category->setIsActive(true);
        $this->em->flush();

        $this->client->request('GET', '/api/categories?locale='.rawurlencode('<script>'));

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $row = current(array_filter($data, static fn (array $c) => $c['id'] === $category->getId()->toRfc4122()));
        self::assertSame('Légumes', $row['name']);
    }

    public function testListCategoriesReturnsZeroProducerCountWithoutProducers(): void
    {
        $category = $this->makeCategory('Fruits');
        $category->setIsActive(true);
        $this->em->flush();

        $this->client->request('GET', '/api/categories');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $row = current(array_filter($data, static fn (array $c) => $c['id'] === $category->getId()->toRfc4122()));
        self::assertSame(0, $row['producerCount']);
    }

    public function testListProductsReturnsOnlyActiveOnes(): void
    {
        $category = $this->makeCategory();
        $active = $this->makeProduct($category, 'Pommes');
        $active->setIsActive(true);
        $inactive = $this->makeProduct($category, 'Brouillon');
        $inactive->setIsActive(false);
        $this->em->flush();

        $this->client->request('GET', '/api/products');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $names = array_column($data, 'name');
        self::assertContains('Pommes', $names);
        self::assertNotContains('Brouillon', $names);
    }

    public function testListLabelsReturnsAllLabels(): void
    {
        $label = new Label();
        $label->setCode('bio');
        $label->setName('Bio');
        $this->em->persist($label);
        $this->em->flush();

        $this->client->request('GET', '/api/labels');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $names = array_column($data, 'name');
        self::assertContains('Bio', $names);
    }

    public function testListLabelsHidesInactiveLabels(): void
    {
        $label = new Label();
        $label->setCode('retired');
        $label->setName('Ancien label');
        $label->setIsActive(false);
        $this->em->persist($label);
        $this->em->flush();

        $this->client->request('GET', '/api/labels');

        self::assertResponseIsSuccessful();
        self::assertNotContains('retired', array_column(json_decode($this->client->getResponse()->getContent(), true), 'code'));
    }

    public function testListLabelsReturnsTranslatedFieldsForRequestedLocale(): void
    {
        $label = new Label();
        $label->setCode('bio');
        $label->setName('Bio');
        $label->setDescription('Produit issu de l\'agriculture biologique.');
        $this->em->persist($label);

        $translation = new LabelTranslation();
        $translation->setLabel($label);
        $translation->setLocale('en');
        $translation->setName('Organic');
        $translation->setDescription('Product from organic farming.');
        $this->em->persist($translation);
        $this->em->flush();

        $this->client->request('GET', '/api/labels?locale=en');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $row = current(array_filter($data, static fn (array $l) => $l['code'] === 'bio'));
        self::assertSame('Organic', $row['name']);
        self::assertSame('Product from organic farming.', $row['description']);
    }

    // ─────────────── producerCount (compteur de la page Catégories) ───────────────

    public function testListCategoriesCountsDistinctProducersIncludingSubcategories(): void
    {
        $country = $this->makeCountry();
        $family = $this->makeCategory('Fruits');
        $family->setIsActive(true);
        $apples = $this->makeCategory('Pommes');
        $apples->setParent($family);
        $apples->setIsActive(true);
        $pears = $this->makeCategory('Poires');
        $pears->setParent($family);
        $pears->setIsActive(true);

        $sellsBoth = $this->makeProducerProfile($this->makeUser('both'), $country, farmName: 'Pommes et poires');
        $sellsPears = $this->makeProducerProfile($this->makeUser('pears'), $country, farmName: 'Poires seules');
        $this->makeProducerProduct($sellsBoth, $this->makeProduct($apples, 'Reinette'));
        $this->makeProducerProduct($sellsBoth, $this->makeProduct($pears, 'Conférence'));
        $this->makeProducerProduct($sellsPears, $this->makeProduct($pears, 'Williams'));
        $this->em->flush();

        $this->client->request('GET', '/api/categories');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        // * Un producteur présent dans deux sous-catégories de la même famille ne compte qu'une fois pour la famille.
        self::assertSame(2, $this->categoryRow($data, $family)['producerCount']);
        self::assertSame(1, $this->categoryRow($data, $apples)['producerCount']);
        self::assertSame(2, $this->categoryRow($data, $pears)['producerCount']);
    }

    public function testListCategoriesDoesNotCountInactiveProducerProducts(): void
    {
        $country = $this->makeCountry();
        $category = $this->makeCategory('Fruits');
        $category->setIsActive(true);
        $producer = $this->makeProducerProfile($this->makeUser('stopped'), $country, farmName: 'Produit retiré de la vente');
        $this->makeProducerProduct($producer, $this->makeProduct($category, 'Pommes'), isActive: false);
        $this->em->flush();

        $this->client->request('GET', '/api/categories');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(0, $this->categoryRow($data, $category)['producerCount']);
    }

    public function testListCategoriesDoesNotCountInactiveProducers(): void
    {
        $country = $this->makeCountry();
        $category = $this->makeCategory('Fruits');
        $category->setIsActive(true);
        $producer = $this->makeProducerProfile($this->makeUser('inactive'), $country, farmName: 'Ferme désactivée');
        $producer->setIsActive(false);
        $this->makeProducerProduct($producer, $this->makeProduct($category, 'Pommes'));
        $this->em->flush();

        $this->client->request('GET', '/api/categories');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(0, $this->categoryRow($data, $category)['producerCount']);
    }

    public function testListCategoriesCountsUnverifiedProducersLikeTheProducersList(): void
    {
        $country = $this->makeCountry();
        $category = $this->makeCategory('Fruits');
        $category->setIsActive(true);
        $pending = $this->makeProducerProfile($this->makeUser('pending'), $country, VerificationStatus::Pending, 'Ferme en attente');
        $this->makeProducerProduct($pending, $this->makeProduct($category, 'Pommes'));
        $this->em->flush();

        $this->client->request('GET', '/api/categories');

        // * Le compteur de la carte doit annoncer autant de producteurs que la page détail en affichera
        // * (GET /api/producers liste les producteurs actifs, vérifiés ou non).
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(1, $this->categoryRow($data, $category)['producerCount']);
    }

    /** @param array<int, array<string, mixed>> $categories réponse de GET /api/categories */
    private function categoryRow(array $categories, Category $category): array
    {
        $rows = array_filter($categories, static fn (array $c) => $c['id'] === $category->getId()->toRfc4122());
        self::assertNotEmpty($rows, 'la catégorie doit figurer dans la réponse');

        return current($rows);
    }
}