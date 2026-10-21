<?php

namespace App\Tests\Functional\Controller\Producer;

use App\Entity\Catalog\Country;
use App\Entity\Catalog\Currency;
use App\Entity\Catalog\Unit;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use App\Entity\Identity\User;
use App\Entity\Producer\ProducerProductMedia;
use App\Entity\Producer\ProducerProfile;
use App\Entity\Producer\ProductAvailability;
use App\Service\Producer\ProductAvailabilityWindow;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste GET/POST/PUT/DELETE /api/producer/products (cdc trouvemoi_agri).
 * Gestion du catalogue produit d'un producteur -- réservé au producteur propriétaire de chaque fiche.
 */
final class ProducerProductControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    // * $countryCode (et non un objet Country) pour les tests à deux producteurs : repasser le même objet
    // * PHP d'un appel à l'autre casse dès que la frontière d'une requête HTTP a été traversée entre-temps
    // * (ServicesResetter vide l'identity map après chaque requête -- l'objet devient détaché). Recharger
    // * par code à chaque appel via find() est le seul moyen sûr d'obtenir un Country toujours "managed".
    private function registerProducerAndLogin(string $emailPrefix = 'producer', ?string $countryCode = null): array
    {
        $country = $countryCode !== null
            ? $this->em->getRepository(Country::class)->find($countryCode)
            : $this->makeCountry();
        // * makeUserWithPassword() (pas makeUser()) : ce compte doit pouvoir se logger pour de vrai
        // * via /api/auth/login plus bas.
        $owner = $this->makeUserWithPassword($emailPrefix, 'motdepasse123');
        $owner->setRoles([User::ROLE_PRODUCER]);
        $producer = $this->makeProducerProfile($owner, $country, farmName: 'Ferme '.$emailPrefix);
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $owner->getEmail(),
            'password' => 'motdepasse123',
        ]));
        self::assertResponseIsSuccessful();
        $token = json_decode($this->client->getResponse()->getContent(), true)['token'];

        // ! ServicesResetter vide l'identity map de l'EntityManager après la requête HTTP ci-dessus :
        // ! on recharge $producer pour repartir avec une entité "managed", sinon un persist() ultérieur qui
        // ! la référence (ex. makeProducerProduct dans un autre test) plante avec "A new entity was found...".
        $producer = $this->em->getRepository(ProducerProfile::class)->find($producer->getId());

        return [$token, $producer];
    }

    public function testCreateProductSucceeds(): void
    {
        [$token] = $this->registerProducerAndLogin();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'Tomates');
        $this->em->flush();

        $this->client->request('POST', '/api/producer/products', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([
            'productId' => $product->getId()->toRfc4122(),
            'variety' => 'Cœur de bœuf',
            'defaultPrice' => '3.50',
        ]));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT variety, default_price FROM producer.producer_products WHERE id = :id',
            ['id' => $data['id']]
        );
        self::assertSame('Cœur de bœuf', $row['variety']);
        self::assertSame('3.50', $row['default_price']);
    }

    public function testCreateProductRejectsTheSameProductWithoutVarietyTwice(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'Tomates');
        $this->makeProducerProduct($producer, $product);
        $this->em->flush();

        $this->client->request('POST', '/api/producer/products', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['productId' => $product->getId()->toRfc4122()]));

        self::assertResponseStatusCodeSame(409);
    }

    // ---- plusieurs variétés d'un même produit : Gala et Golden pour les pommes

    /** @param array<string, mixed> $payload */
    private function postProduct(string $token, array $payload): int
    {
        $this->client->request('POST', '/api/producer/products', server: $this->jsonServer($token), content: json_encode($payload));

        return $this->client->getResponse()->getStatusCode();
    }

    public function testAProducerCanHaveSeveralVarietiesOfTheSameProduct(): void
    {
        [$token] = $this->registerProducerAndLogin();
        $apples = $this->makeProduct($this->makeCategory('Fruits'), 'Pommes');
        $this->em->flush();
        $productId = $apples->getId()->toRfc4122();

        self::assertSame(201, $this->postProduct($token, ['productId' => $productId, 'variety' => 'Gala']));
        self::assertSame(201, $this->postProduct($token, ['productId' => $productId, 'variety' => 'Golden']));
        // * et la même sans variété à côté, une seule fois
        self::assertSame(201, $this->postProduct($token, ['productId' => $productId]));

        // * triées par variété, celle qui n'en a pas en dernier (PostgreSQL range les valeurs nulles à la fin)
        $varieties = array_column($this->listProducts($token), 'variety');
        self::assertSame(['Gala', 'Golden', null], $varieties);
    }

    public function testTheSameVarietyTwiceIsRefusedWhateverTheCaseOrTheSpaces(): void
    {
        [$token] = $this->registerProducerAndLogin();
        $apples = $this->makeProduct($this->makeCategory('Fruits'), 'Pommes');
        $this->em->flush();
        $productId = $apples->getId()->toRfc4122();
        self::assertSame(201, $this->postProduct($token, ['productId' => $productId, 'variety' => 'Gala']));

        self::assertSame(409, $this->postProduct($token, ['productId' => $productId, 'variety' => 'Gala']));
        self::assertSame(409, $this->postProduct($token, ['productId' => $productId, 'variety' => '  gala ']));
        // * une variété vide, c'est « sans variété » : pas de doublon avec Gala, un seul exemplaire
        self::assertSame(201, $this->postProduct($token, ['productId' => $productId, 'variety' => '   ']));
        self::assertSame(409, $this->postProduct($token, ['productId' => $productId]));
        self::assertStringContainsString('avec cette variété', json_decode($this->client->getResponse()->getContent(), true)['error']);
    }

    public function testTheVarietyIsStoredTrimmedAndEmptyBecomesNull(): void
    {
        [$token] = $this->registerProducerAndLogin();
        $apples = $this->makeProduct($this->makeCategory('Fruits'), 'Pommes');
        $tomatoes = $this->makeProduct($this->makeCategory('Légumes'), 'Tomates');
        $this->em->flush();

        self::assertSame(201, $this->postProduct($token, ['productId' => $apples->getId()->toRfc4122(), 'variety' => '  Gala  ']));
        self::assertSame(201, $this->postProduct($token, ['productId' => $tomatoes->getId()->toRfc4122(), 'variety' => '']));

        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT p.name, pp.variety FROM producer.producer_products pp JOIN catalog.products p ON p.id = pp.product_id ORDER BY p.name');
        self::assertSame([['name' => 'Pommes', 'variety' => 'Gala'], ['name' => 'Tomates', 'variety' => null]], $rows);
    }

    public function testTwoProducersCanHaveTheSameProductAndVariety(): void
    {
        $this->makeCountry();
        $this->em->flush();
        [$tokenA] = $this->registerProducerAndLogin('producerA', 'FR');
        [$tokenB] = $this->registerProducerAndLogin('producerB', 'FR');
        $apples = $this->makeProduct($this->makeCategory('Fruits'), 'Pommes');
        $this->em->flush();

        self::assertSame(201, $this->postProduct($tokenA, ['productId' => $apples->getId()->toRfc4122(), 'variety' => 'Gala']));
        self::assertSame(201, $this->postProduct($tokenB, ['productId' => $apples->getId()->toRfc4122(), 'variety' => 'Gala']));
    }

    public function testUpdatingTheVarietyCannotTakeTheOneOfAnotherLineOfTheSameProduct(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $apples = $this->makeProduct($this->makeCategory('Fruits'), 'Pommes');
        $this->makeProducerProduct($producer, $apples)->setVariety('Gala');
        $golden = $this->makeProducerProduct($producer, $apples)->setVariety('Golden');
        $this->em->flush();
        $url = '/api/producer/products/'.$golden->getId()->toRfc4122();

        $this->client->request('PUT', $url, server: $this->jsonServer($token), content: json_encode(['variety' => 'GALA']));
        self::assertResponseStatusCodeSame(409);

        // * enregistrer une ligne sans changer sa variété n'est pas un doublon avec elle-même
        $this->client->request('PUT', $url, server: $this->jsonServer($token), content: json_encode(['variety' => 'Golden', 'defaultPrice' => '2.5']));
        self::assertResponseIsSuccessful();

        $this->client->request('PUT', $url, server: $this->jsonServer($token), content: json_encode(['variety' => 'Reine des reinettes']));
        self::assertResponseIsSuccessful();
        self::assertSame(['Gala', 'Reine des reinettes'], array_column($this->listProducts($token), 'variety'));
    }

    public function testTheDatabaseRefusesTheSameVarietyTwiceToo(): void
    {
        [, $producer] = $this->registerProducerAndLogin();
        $apples = $this->makeProduct($this->makeCategory('Fruits'), 'Pommes');
        $this->makeProducerProduct($producer, $apples)->setVariety('Gala');
        $this->makeProducerProduct($producer, $apples)->setVariety(' gala');

        // * l'index uniq_producer_products_variety : le dernier garde-fou, derrière le contrôle de l'API (deux requêtes simultanées)
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testCreateProductRejectsUnknownProduct(): void
    {
        [$token] = $this->registerProducerAndLogin();

        $this->client->request('POST', '/api/producer/products', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['productId' => \Symfony\Component\Uid\Uuid::v4()->toRfc4122()]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateProductRejectsAccountWithoutProducerProfile(): void
    {
        $token = $this->registerClientAndLogin();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $this->em->flush();

        $this->client->request('POST', '/api/producer/products', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['productId' => $product->getId()->toRfc4122()]));

        self::assertResponseStatusCodeSame(403);
    }

    public function testUpdateProductChangesFields(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'Tomates');
        $producerProduct = $this->makeProducerProduct($producer, $product);
        $this->em->flush();
        $id = $producerProduct->getId()->toRfc4122();

        $this->client->request('PUT', '/api/producer/products/'.$id, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['variety' => 'Ancienne variété', 'isActive' => false]));

        self::assertResponseIsSuccessful();
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT variety, is_active FROM producer.producer_products WHERE id = :id',
            ['id' => $id]
        );
        self::assertSame('Ancienne variété', $row['variety']);
        self::assertFalse((bool) $row['is_active']);
    }

    public function testUpdateProductReturns404ForUnknownId(): void
    {
        [$token] = $this->registerProducerAndLogin();

        $this->client->request('PUT', '/api/producer/products/'.\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([]));

        self::assertResponseStatusCodeSame(404);
    }

    public function testUpdateProductRejectsProductOwnedByAnotherProducer(): void
    {
        $this->makeCountry();
        $this->em->flush();
        [, $producerA] = $this->registerProducerAndLogin('producerA', 'FR');
        [$tokenB] = $this->registerProducerAndLogin('producerB', 'FR');
        // * $producerA a été rechargé à l'intérieur du 1er appel, mais la requête HTTP de login du 2e appel
        // * a de nouveau vidé l'identity map depuis -- on le recharge une dernière fois avant de s'en servir.
        $producerA = $this->em->getRepository(ProducerProfile::class)->find($producerA->getId());
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'Tomates');
        $producerProduct = $this->makeProducerProduct($producerA, $product);
        $this->em->flush();

        $this->client->request('PUT', '/api/producer/products/'.$producerProduct->getId()->toRfc4122(), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenB,
        ], content: json_encode(['variety' => 'Tentative intrusion']));

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteProductSucceeds(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'Tomates');
        $producerProduct = $this->makeProducerProduct($producer, $product);
        $this->em->flush();
        $id = $producerProduct->getId()->toRfc4122();

        $this->client->request('DELETE', '/api/producer/products/'.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(204);
        $row = $this->em->getConnection()->fetchAssociative('SELECT id FROM producer.producer_products WHERE id = :id', ['id' => $id]);
        self::assertFalse($row);
    }

    public function testDeleteProductRejectsProductOwnedByAnotherProducer(): void
    {
        $this->makeCountry();
        $this->em->flush();
        [, $producerA] = $this->registerProducerAndLogin('producerA', 'FR');
        [$tokenB] = $this->registerProducerAndLogin('producerB', 'FR');
        // * Même recharge que dans testUpdateProductRejectsProductOwnedByAnotherProducer -- voir ce test.
        $producerA = $this->em->getRepository(ProducerProfile::class)->find($producerA->getId());
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'Tomates');
        $producerProduct = $this->makeProducerProduct($producerA, $product);
        $this->em->flush();

        $this->client->request('DELETE', '/api/producer/products/'.$producerProduct->getId()->toRfc4122(), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokenB]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testListProductsReturnsOwnProductsWithUnitAndSeason(): void
    {
        $this->makeCountry();
        $this->em->flush();
        [$token, $producerA] = $this->registerProducerAndLogin('producerA', 'FR');
        [, $producerB] = $this->registerProducerAndLogin('producerB', 'FR');
        // * Même recharge que dans testUpdateProductRejectsProductOwnedByAnotherProducer : chaque login vide l'identity map.
        $producerA = $this->em->getRepository(ProducerProfile::class)->find($producerA->getId());
        $producerB = $this->em->getRepository(ProducerProfile::class)->find($producerB->getId());

        $category = $this->makeCategory();
        $kg = (new Unit())->setCode('kg')->setLabel('Kilogramme');
        $this->em->persist($kg);
        $euro = (new Currency())->setCode('EUR')->setName('Euro');
        $this->em->persist($euro);

        $tomates = $this->makeProduct($category, 'Tomates')->setDefaultUnit($kg)->setSeasonStartMonth(6)->setSeasonEndMonth(9);
        $pommes = $this->makeProduct($category, 'Pommes');
        $miel = $this->makeProduct($category, 'Miel');
        $this->makeProducerProduct($producerA, $tomates)
            ->setVariety('Cœur de bœuf')->setEstimatedVolume('90.000')->setDefaultPrice('3.50')->setCurrency($euro);
        // * un produit désactivé reste dans la liste (c'est l'interrupteur de la page), celui de l'autre producteur non
        $this->makeProducerProduct($producerA, $pommes, isActive: false);
        $this->makeProducerProduct($producerB, $miel);
        $this->em->flush();

        $this->client->request('GET', '/api/producer/products', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        // * trié par nom : Pommes avant Tomates
        self::assertSame(['Pommes', 'Tomates'], array_column($data, 'name'));

        [$apples, $tomatoes] = $data;
        self::assertFalse($apples['isActive']);
        self::assertNull($apples['unit']);
        self::assertNull($apples['seasonStartMonth']);
        self::assertNull($apples['variety']);
        self::assertNull($apples['defaultPrice']);
        self::assertNull($apples['currencyCode']);

        self::assertTrue($tomatoes['isActive']);
        self::assertSame($tomates->getId()->toRfc4122(), $tomatoes['productId']);
        self::assertSame('Cœur de bœuf', $tomatoes['variety']);
        self::assertEquals(90, $tomatoes['estimatedVolume']);
        self::assertEquals(3.5, $tomatoes['defaultPrice']);
        self::assertSame('EUR', $tomatoes['currencyCode']);
        self::assertSame(['id' => $kg->getId()->toRfc4122(), 'code' => 'kg', 'label' => 'Kilogramme'], $tomatoes['unit']);
        self::assertSame(6, $tomatoes['seasonStartMonth']);
        self::assertSame(9, $tomatoes['seasonEndMonth']);
    }

    public function testListProductsIsEmptyForAProducerWithoutProducts(): void
    {
        [$token] = $this->registerProducerAndLogin();

        $this->client->request('GET', '/api/producer/products', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($this->client->getResponse()->getContent(), true));
    }

    public function testListProductsRejectsAccountWithoutProducerProfile(): void
    {
        $token = $this->registerClientAndLogin();

        $this->client->request('GET', '/api/producer/products', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testListProductsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/producer/products');

        self::assertResponseStatusCodeSame(401);
    }

    // * "abc" ou "3,50" plantaient en 500 (Postgres refuse le numérique) : maintenant 422 avant d'écrire quoi que ce soit
    public function testCreateAndUpdateRejectNumbersInTheWrongFormat(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'Tomates');
        $producerProduct = $this->makeProducerProduct($producer, $product);
        $this->em->flush();
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token];

        foreach ([['estimatedVolume' => 'abc'], ['defaultPrice' => '3,50'], ['defaultPrice' => '3.505'], ['estimatedVolume' => '-4']] as $bad) {
            $this->client->request('POST', '/api/producer/products', server: $server, content: json_encode($bad + ['productId' => $product->getId()->toRfc4122()]));
            self::assertResponseStatusCodeSame(422, 'POST '.json_encode($bad));

            $this->client->request('PUT', '/api/producer/products/'.$producerProduct->getId()->toRfc4122(), server: $server, content: json_encode($bad));
            self::assertResponseStatusCodeSame(422, 'PUT '.json_encode($bad));
        }

        // * et des nombres bien écrits passent, avec ou sans décimales
        $this->client->request('PUT', '/api/producer/products/'.$producerProduct->getId()->toRfc4122(), server: $server, content: json_encode([
            'estimatedVolume' => '90', 'defaultPrice' => '3.5', 'currencyCode' => null,
        ]));
        self::assertResponseIsSuccessful();
    }

    // ---- période de disponibilité, labels et photos du produit

    /** @return array<string, string> */
    private function jsonServer(string $token): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    /** @return array<int, array<string, mixed>> */
    private function listProducts(string $token): array
    {
        $this->client->request('GET', '/api/producer/products', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function testCreateProductWithItsPeriodAndLabels(): void
    {
        [$token] = $this->registerProducerAndLogin();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'Tomates');
        $kg = (new Unit())->setCode('kg')->setLabel('Kilogramme');
        $this->em->persist($kg);
        $bio = $this->makeLabel('bio', 'Bio');
        $local = $this->makeLabel('local', 'Local');
        $this->em->flush();

        $this->client->request('POST', '/api/producer/products', server: $this->jsonServer($token), content: json_encode([
            'productId' => $product->getId()->toRfc4122(),
            'labelIds' => [$bio->getId()->toRfc4122(), $local->getId()->toRfc4122()],
            'availability' => ['startMonth' => 6, 'endMonth' => 9, 'quantity' => '50', 'unitId' => $kg->getId()->toRfc4122()],
        ]));

        self::assertResponseStatusCodeSame(201);
        $id = json_decode($this->client->getResponse()->getContent(), true)['id'];

        // * "Juin à Septembre" devient les dates du prochain créneau de ces mois
        [$from, $to] = ProductAvailabilityWindow::forMonths(6, 9, new \DateTimeImmutable('today'));
        $slot = $this->em->getConnection()->fetchAssociative(
            'SELECT available_from, available_to, quantity_estimate, unit_id FROM producer.product_availabilities WHERE producer_product_id = :id',
            ['id' => $id]
        );
        self::assertSame($from->format('Y-m-d'), $slot['available_from']);
        self::assertSame($to->format('Y-m-d'), $slot['available_to']);
        self::assertEquals(50, $slot['quantity_estimate']);
        self::assertSame($kg->getId()->toRfc4122(), $slot['unit_id']);

        $listed = $this->listProducts($token)[0];
        self::assertSame(6, $listed['availability']['startMonth']);
        self::assertSame(9, $listed['availability']['endMonth']);
        self::assertEquals(50, $listed['availability']['quantity']);
        self::assertSame('kg', $listed['availability']['unit']['code']);
        self::assertSame(['Bio', 'Local'], array_column($listed['labels'], 'name'));
        self::assertSame([], $listed['photos']);
    }

    public function testListProductsHasNoPeriodWhenThereIsNoSlot(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $this->makeProducerProduct($producer, $this->makeProduct($this->makeCategory(), 'Pommes'));
        $this->em->flush();

        $listed = $this->listProducts($token)[0];

        self::assertNull($listed['availability']);
        self::assertSame([], $listed['labels']);
    }

    public function testUpdateKeepsPeriodAndLabelsWhenTheyAreNotSent(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $item = $this->makeProducerProduct($producer, $this->makeProduct($this->makeCategory(), 'Tomates'));
        $item->addLabel($this->makeLabel('bio', 'Bio'));
        $slot = (new ProductAvailability())->setAvailableFrom(new \DateTimeImmutable('+10 days'))->setAvailableTo(new \DateTimeImmutable('+40 days'));
        $item->addProductAvailability($slot);
        $this->em->persist($slot);
        $this->em->flush();
        $id = $item->getId()->toRfc4122();

        // * ce que envoie l'interrupteur de la liste : ni labelIds ni availability
        $this->client->request('PUT', '/api/producer/products/'.$id, server: $this->jsonServer($token), content: json_encode(['isActive' => false]));

        self::assertResponseIsSuccessful();
        $listed = $this->listProducts($token)[0];
        self::assertFalse($listed['isActive']);
        self::assertSame(['Bio'], array_column($listed['labels'], 'name'));
        self::assertNotNull($listed['availability']);
    }

    public function testUpdateReplacesLabelsAndChangesThePeriod(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $item = $this->makeProducerProduct($producer, $this->makeProduct($this->makeCategory(), 'Tomates'));
        $bio = $this->makeLabel('bio', 'Bio');
        $hve = $this->makeLabel('hve', 'HVE');
        $item->addLabel($bio);
        $slot = (new ProductAvailability())->setAvailableFrom(new \DateTimeImmutable('2020-06-01'))->setAvailableTo(new \DateTimeImmutable('2020-09-30'));
        $item->addProductAvailability($slot);
        $this->em->persist($slot);
        $this->em->flush();
        $id = $item->getId()->toRfc4122();

        $this->client->request('PUT', '/api/producer/products/'.$id, server: $this->jsonServer($token), content: json_encode([
            'labelIds' => [$hve->getId()->toRfc4122()],
            'availability' => ['startMonth' => 3, 'endMonth' => 5, 'quantity' => '12.5'],
        ]));

        self::assertResponseIsSuccessful();
        $listed = $this->listProducts($token)[0];
        self::assertSame(['HVE'], array_column($listed['labels'], 'name'));
        self::assertSame(3, $listed['availability']['startMonth']);
        self::assertSame(5, $listed['availability']['endMonth']);
        self::assertEquals(12.5, $listed['availability']['quantity']);
        self::assertNull($listed['availability']['unit']);
        // * c'est bien le créneau existant qui est modifié, pas un second
        $count = $this->em->getConnection()->fetchOne('SELECT count(*) FROM producer.product_availabilities WHERE producer_product_id = :id', ['id' => $id]);
        self::assertSame(1, (int) $count);
    }

    public function testUpdateWithNoLabelsRemovesThemAll(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $item = $this->makeProducerProduct($producer, $this->makeProduct($this->makeCategory(), 'Tomates'));
        $item->addLabel($this->makeLabel('bio', 'Bio'));
        $this->em->flush();

        $this->client->request('PUT', '/api/producer/products/'.$item->getId()->toRfc4122(), server: $this->jsonServer($token), content: json_encode(['labelIds' => []]));

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->listProducts($token)[0]['labels']);
    }

    public function testUpdateWithAnEmptyPeriodRemovesTheSlot(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $item = $this->makeProducerProduct($producer, $this->makeProduct($this->makeCategory(), 'Tomates'));
        $slot = (new ProductAvailability())->setAvailableFrom(new \DateTimeImmutable('+10 days'))->setAvailableTo(new \DateTimeImmutable('+40 days'));
        $item->addProductAvailability($slot);
        $this->em->persist($slot);
        $this->em->flush();

        $this->client->request('PUT', '/api/producer/products/'.$item->getId()->toRfc4122(), server: $this->jsonServer($token), content: json_encode(['availability' => []]));

        self::assertResponseIsSuccessful();
        self::assertNull($this->listProducts($token)[0]['availability']);
    }

    public function testSavingTheSameMonthsKeepsTheSlotDatesUntilTheyArePast(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $item = $this->makeProducerProduct($producer, $this->makeProduct($this->makeCategory(), 'Tomates'));
        // * des dates précises posées ailleurs (admin, import) : un simple "Enregistrer" ne doit pas les élargir au mois entier
        $from = new \DateTimeImmutable('+10 days');
        $to = new \DateTimeImmutable('+20 days');
        $slot = (new ProductAvailability())->setAvailableFrom($from)->setAvailableTo($to);
        $item->addProductAvailability($slot);
        $this->em->persist($slot);
        $this->em->flush();
        $id = $item->getId()->toRfc4122();
        $months = ['startMonth' => (int) $from->format('n'), 'endMonth' => (int) $to->format('n')];

        $this->client->request('PUT', '/api/producer/products/'.$id, server: $this->jsonServer($token), content: json_encode(['availability' => $months]));
        self::assertResponseIsSuccessful();
        $row = $this->em->getConnection()->fetchAssociative('SELECT available_from, available_to FROM producer.product_availabilities WHERE producer_product_id = :id', ['id' => $id]);
        self::assertSame($from->format('Y-m-d'), $row['available_from']);
        self::assertSame($to->format('Y-m-d'), $row['available_to']);

        // * un créneau fini qu'on réenregistre se renouvelle : ses mois reprennent à la prochaine occurrence
        $this->em->getConnection()->executeStatement("UPDATE producer.product_availabilities SET available_from = '2020-06-01', available_to = '2020-09-30' WHERE producer_product_id = :id", ['id' => $id]);
        $this->client->request('PUT', '/api/producer/products/'.$id, server: $this->jsonServer($token), content: json_encode(['availability' => ['startMonth' => 6, 'endMonth' => 9]]));
        self::assertResponseIsSuccessful();
        $row = $this->em->getConnection()->fetchAssociative('SELECT available_to FROM producer.product_availabilities WHERE producer_product_id = :id', ['id' => $id]);
        self::assertGreaterThanOrEqual((new \DateTimeImmutable('today'))->format('Y-m-d'), $row['available_to']);
    }

    public function testAnUnitAloneDoesNotCreateASlot(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $kg = (new Unit())->setCode('kg')->setLabel('Kilogramme');
        $this->em->persist($kg);
        $item = $this->makeProducerProduct($producer, $this->makeProduct($this->makeCategory(), 'Tomates'));
        $this->em->flush();

        $this->client->request('PUT', '/api/producer/products/'.$item->getId()->toRfc4122(), server: $this->jsonServer($token), content: json_encode([
            'availability' => ['unitId' => $kg->getId()->toRfc4122()],
        ]));

        self::assertResponseIsSuccessful();
        self::assertNull($this->listProducts($token)[0]['availability']);
    }

    public function testRejectsUnknownLabelUnknownUnitAndInvalidMonths(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $item = $this->makeProducerProduct($producer, $this->makeProduct($this->makeCategory(), 'Tomates'));
        $this->em->flush();
        $url = '/api/producer/products/'.$item->getId()->toRfc4122();
        $unknown = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();

        foreach ([
            'label inconnu' => ['labelIds' => [$unknown]],
            'label mal formé' => ['labelIds' => ['pas-un-uuid']],
            'unité inconnue' => ['availability' => ['startMonth' => 6, 'endMonth' => 9, 'unitId' => $unknown]],
            'mois 13' => ['availability' => ['startMonth' => 13, 'endMonth' => 9]],
            'début sans fin' => ['availability' => ['startMonth' => 6]],
            'volume mal écrit' => ['availability' => ['startMonth' => 6, 'endMonth' => 9, 'quantity' => '12,5']],
        ] as $case => $payload) {
            $this->client->request('PUT', $url, server: $this->jsonServer($token), content: json_encode($payload));
            self::assertResponseStatusCodeSame(422, $case);
        }
        // * rien n'a été écrit en chemin
        self::assertNull($this->listProducts($token)[0]['availability']);
    }

    public function testDeleteProductAlsoRemovesItsPhotosPeriodAndLabels(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $item = $this->makeProducerProduct($producer, $this->makeProduct($this->makeCategory(), 'Tomates'));
        $item->addLabel($this->makeLabel('bio', 'Bio'));
        $slot = (new ProductAvailability())->setAvailableFrom(new \DateTimeImmutable('+10 days'));
        $item->addProductAvailability($slot);
        $photo = (new ProducerProductMedia())->setFileUrl('http://localhost/test-storage/absente.jpg')->setPosition(0);
        $item->addMedium($photo);
        $this->em->persist($slot);
        $this->em->persist($photo);
        $this->em->flush();
        $id = $item->getId()->toRfc4122();

        $this->client->request('DELETE', '/api/producer/products/'.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(204);
        $connection = $this->em->getConnection();
        foreach (['producer.product_availabilities', 'producer.producer_product_labels', 'producer.producer_product_media'] as $table) {
            self::assertSame(0, (int) $connection->fetchOne("SELECT count(*) FROM $table WHERE producer_product_id = :id", ['id' => $id]), $table);
        }
    }
}
