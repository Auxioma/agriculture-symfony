<?php

namespace App\Tests\Functional;

use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste GET /api/producers et GET /api/producers/{id} (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf §20.3, round 1).
 * Routes publiques : aucun header Authorization envoyé dans ces tests.
 */
final class ProducerControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    public function testListProducersReturnsOnlyActiveOnes(): void
    {
        $country = $this->makeCountry();
        // * farmName distinct pour chaque producteur : makeProducerProfile() a la même valeur par défaut pour
        // * les deux sinon, ce qui rend assertNotContains() ci-dessous impossible à vérifier correctement.
        $active = $this->makeProducerProfile($this->makeUser('active'), $country, farmName: 'Ferme Active');
        $active->setIsActive(true);
        $inactive = $this->makeProducerProfile($this->makeUser('inactive'), $country, farmName: 'Ferme Inactive');
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