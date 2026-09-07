<?php

namespace App\Tests\Functional\Controller\Producer;

use App\Entity\Catalog\Label;
use App\Entity\Producer\ProducerLabel;
use App\Entity\Producer\ProducerSetting;
use App\Enum\VerificationStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste GET /api/producers et GET /api/producers/{id} (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf §20.3,
 * round 1) et les filtres de recherche ajoutés sur GET /api/producers (§5.2, "recherche et listing producteurs" :
 * produit, catégorie, label, retrait/livraison, producteur vérifié, localisation+rayon, tri par distance).
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

    public function testListProducersFiltersByProduct(): void
    {
        $country = $this->makeCountry();
        $category = $this->makeCategory();
        $sellsIt = $this->makeProducerProfile($this->makeUser('sells'), $country, farmName: 'Vend le produit');
        $doesNotSellIt = $this->makeProducerProfile($this->makeUser('nosell'), $country, farmName: 'Ne vend pas');
        $product = $this->makeProduct($category, 'Tomates');
        // * isActive=true sur ProducerProduct : le filtre productId= exige une fiche produit active,
        // * pas juste une ligne producer_products existante (cf. le AND prp.is_active = true du contrôleur).
        $this->makeProducerProduct($sellsIt, $product, true);
        $this->em->flush();

        $this->client->request('GET', '/api/producers?productId='.$product->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $names = array_column(json_decode($this->client->getResponse()->getContent(), true), 'farmName');
        self::assertContains($sellsIt->getFarmName(), $names);
        self::assertNotContains($doesNotSellIt->getFarmName(), $names);
    }

    public function testListProducersFiltersByCategory(): void
    {
        $country = $this->makeCountry();
        $matchingCategory = $this->makeCategory('Fruits');
        $otherCategory = $this->makeCategory('Légumes');
        $inCategory = $this->makeProducerProfile($this->makeUser('in-cat'), $country, farmName: 'Dans la catégorie');
        $outOfCategory = $this->makeProducerProfile($this->makeUser('out-cat'), $country, farmName: 'Hors catégorie');
        $this->makeProducerProduct($inCategory, $this->makeProduct($matchingCategory, 'Pommes'), true);
        $this->makeProducerProduct($outOfCategory, $this->makeProduct($otherCategory, 'Carottes'), true);
        $this->em->flush();

        $this->client->request('GET', '/api/producers?categoryId='.$matchingCategory->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $names = array_column(json_decode($this->client->getResponse()->getContent(), true), 'farmName');
        self::assertContains($inCategory->getFarmName(), $names);
        self::assertNotContains($outOfCategory->getFarmName(), $names);
    }

    public function testListProducersFiltersByLabel(): void
    {
        $country = $this->makeCountry();
        $labeled = $this->makeProducerProfile($this->makeUser('labeled'), $country, farmName: 'Ferme labellisée');
        $unlabeled = $this->makeProducerProfile($this->makeUser('unlabeled'), $country, farmName: 'Ferme sans label');

        // * Pas de fabrique dédiée dans EntityFactoryTrait pour Label/ProducerLabel (cas encore rare dans les
        // * tests) -- construites directement ici, comme pour ProducerSetting juste après.
        $label = new Label();
        $label->setCode('bio');
        $label->setName('Bio');
        $this->em->persist($label);

        $producerLabel = new ProducerLabel();
        $producerLabel->setProducer($labeled);
        $producerLabel->setLabel($label);
        $this->em->persist($producerLabel);
        $this->em->flush();

        $this->client->request('GET', '/api/producers?label=bio');

        self::assertResponseIsSuccessful();
        $names = array_column(json_decode($this->client->getResponse()->getContent(), true), 'farmName');
        self::assertContains($labeled->getFarmName(), $names);
        self::assertNotContains($unlabeled->getFarmName(), $names);
    }

    public function testListProducersFiltersByPickupAvailable(): void
    {
        $country = $this->makeCountry();
        $withPickup = $this->makeProducerProfile($this->makeUser('pickup'), $country, farmName: 'Retrait possible');
        // * withoutSettings n'a même pas de ligne producer_settings -- le filtre doit l'exclure aussi
        // * (EXISTS échoue naturellement dans ce cas), pas seulement les settings avec pickup_enabled=false.
        $withoutSettings = $this->makeProducerProfile($this->makeUser('no-settings'), $country, farmName: 'Pas de réglages');

        $settings = new ProducerSetting();
        $settings->setProducer($withPickup);
        $settings->setPickupEnabled(true);
        $this->em->persist($settings);
        $this->em->flush();

        $this->client->request('GET', '/api/producers?pickupAvailable=true');

        self::assertResponseIsSuccessful();
        $names = array_column(json_decode($this->client->getResponse()->getContent(), true), 'farmName');
        self::assertContains($withPickup->getFarmName(), $names);
        self::assertNotContains($withoutSettings->getFarmName(), $names);
    }

    public function testListProducersFiltersByVerifiedOnly(): void
    {
        $country = $this->makeCountry();
        $verified = $this->makeProducerProfile($this->makeUser('verified'), $country, VerificationStatus::Verified, 'Ferme vérifiée');
        $pending = $this->makeProducerProfile($this->makeUser('pending'), $country, VerificationStatus::Pending, 'Ferme en attente');
        $this->em->flush();

        $this->client->request('GET', '/api/producers?verifiedOnly=true');

        self::assertResponseIsSuccessful();
        $names = array_column(json_decode($this->client->getResponse()->getContent(), true), 'farmName');
        self::assertContains($verified->getFarmName(), $names);
        self::assertNotContains($pending->getFarmName(), $names);
    }

    public function testListProducersFiltersByLocationRadius(): void
    {
        $country = $this->makeCountry();
        $nearby = $this->makeProducerProfile($this->makeUser('nearby'), $country, farmName: 'Ferme proche');
        $farAway = $this->makeProducerProfile($this->makeUser('faraway'), $country, farmName: 'Ferme lointaine');
        $this->em->flush();

        // * Paris (2.35, 48.85) et un point à ~5km, contre Marseille (~660km de Paris) largement hors du
        // * rayon de 20km demandé -- pas besoin de calcul précis, juste une distance sans ambiguïté possible.
        $this->setGeographyPoint('producer.producer_profiles', 'location', $nearby->getId()->toRfc4122(), 2.39, 48.85);
        $this->setGeographyPoint('producer.producer_profiles', 'location', $farAway->getId()->toRfc4122(), 5.37, 43.30);

        $this->client->request('GET', '/api/producers?latitude=48.85&longitude=2.35&radiusKm=20');

        self::assertResponseIsSuccessful();
        $names = array_column(json_decode($this->client->getResponse()->getContent(), true), 'farmName');
        self::assertContains($nearby->getFarmName(), $names);
        self::assertNotContains($farAway->getFarmName(), $names);
    }

    public function testListProducersSortsByDistance(): void
    {
        $country = $this->makeCountry();
        $closer = $this->makeProducerProfile($this->makeUser('closer'), $country, farmName: 'Plus proche');
        $further = $this->makeProducerProfile($this->makeUser('further'), $country, farmName: 'Plus loin');
        $this->em->flush();

        $this->setGeographyPoint('producer.producer_profiles', 'location', $closer->getId()->toRfc4122(), 2.36, 48.85);
        $this->setGeographyPoint('producer.producer_profiles', 'location', $further->getId()->toRfc4122(), 2.45, 48.85);

        $this->client->request('GET', '/api/producers?latitude=48.85&longitude=2.35&radiusKm=50&sort=distance');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($closer->getFarmName(), $data[0]['farmName']);
        self::assertSame($further->getFarmName(), $data[1]['farmName']);
        // * distanceKm doit être renseigné (pas null) dès qu'une position est fournie dans la requête.
        self::assertNotNull($data[0]['distanceKm']);
        self::assertLessThan($data[1]['distanceKm'], $data[0]['distanceKm']);
    }
}