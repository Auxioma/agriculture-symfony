<?php

namespace App\Tests\Functional;

use App\Entity\Catalog\Country;
use App\Entity\Identity\User;
use App\Entity\Producer\ProducerProfile;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste POST/PUT/DELETE /api/producer/products (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf §20.3, round 3).
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

    public function testCreateProductRejectsDuplicateProduct(): void
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
}
