<?php

use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;


final class ProducerControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    public function testListProducersReturnsOnlyActiveOnes(): void
    {
        $country = $this->makeCountry();
        $active = $this->makeProducerProfile($this->makeUser('active'), $country);
        $active->setIsActive(true);
        $inactive = $this->makeProducerProfile($this->makeUser('inactive'), $country);
        $inactive->setIsActive(false);
        $this->em->flush();

        $this->client->request('GET', '/api/producers');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $names = array_column($data, 'farmName');
        self::assertContains($active->getFarmName(), $names);
        self::assertNotContains($inactive->getFarmName(), $names);
    }

    public function testGetProducerReturnsData(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser(), $country);
        $producer->setIsActive(true);
        $producer->setDescription('Une ferme locale');
        $this->em->flush();

        $this->client->request('GET', '/api/producers/'.$producer->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Une ferme locale', $data['description']);
    }

    public function testGetProducerReturns404WhenInactive(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser(), $country);
        $producer->setIsActive(false);
        $this->em->flush();

        $this->client->request('GET', '/api/producers/'.$producer->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
    }
}