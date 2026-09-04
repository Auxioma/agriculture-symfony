<?php

namespace App\Tests\Functional;

use App\Entity\Catalog\Label;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;


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

    public function testGetProductReturnsData(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'Tomates');
        $this->em->flush();

        $this->client->request('GET', '/api/products/'.$product->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Tomates', $data['name']);
    }

    public function testGetProductReturns404ForUnknownId(): void
    {
        $this->client->request('GET', '/api/products/'.\Symfony\Component\Uid\Uuid::v4()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
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
}