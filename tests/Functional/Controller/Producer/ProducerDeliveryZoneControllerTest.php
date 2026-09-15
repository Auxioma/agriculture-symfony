<?php

namespace App\Tests\Functional\Controller\Producer;

use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste GET/POST /api/producer/delivery-zones et PUT/DELETE /api/producer/delivery-zones/{id}
 * (cahier fonctionnel, profil producteur enrichi), ainsi que leur exposition publique sur
 * GET /api/producers/{id}.
 */
final class ProducerDeliveryZoneControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    /**
     * @return array{0: string, 1: \App\Entity\Producer\ProducerProfile}
     */
    private function loginAsProducer(string $countryCode = 'FR'): array
    {
        $country = $this->makeCountry($countryCode);
        $owner = $this->makeUserWithPassword('producer', 'motdepasse123');
        $owner->setRoles([User::ROLE_PRODUCER]);
        $producer = $this->makeProducerProfile($owner, $country);
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $owner->getEmail(),
            'password' => 'motdepasse123',
        ]));
        $token = json_decode($this->client->getResponse()->getContent(), true)['token'];

        return [$token, $producer];
    }

    public function testCreateWithRadiusSucceedsAndAppearsInListAndPublicRoute(): void
    {
        [$token, $producer] = $this->loginAsProducer();

        $this->client->request('POST', '/api/producer/delivery-zones', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['radiusKm' => 25, 'rules' => ['freeShipping' => true]]));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/producer/delivery-zones', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame('25.00', $data[0]['radiusKm']);
        self::assertTrue($data[0]['rules']['freeShipping']);

        $this->client->request('GET', '/api/producers/'.$producer->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        $public = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $public['deliveryZones']);
        self::assertSame('25.00', $public['deliveryZones'][0]['radiusKm']);
    }

    public function testCreateWithPolygonStoresAClosedPolygon(): void
    {
        [$token] = $this->loginAsProducer();

        // * Triangle non refermé volontairement (le premier et dernier point diffèrent) -- le
        // * contrôleur doit refermer l'anneau automatiquement pour que PostGIS l'accepte.
        $this->client->request('POST', '/api/producer/delivery-zones', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['polygon' => [[2.3, 48.8], [2.4, 48.8], [2.35, 48.9]]]));
        self::assertResponseStatusCodeSame(201);
        $id = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $wkt = $this->em->getConnection()->fetchOne('SELECT ST_AsText(zone) FROM producer.delivery_zones WHERE id = :id', ['id' => $id]);
        self::assertStringStartsWith('POLYGON', $wkt);
    }

    public function testCreateRejectsWhenNeitherRadiusNorPolygonProvided(): void
    {
        [$token] = $this->loginAsProducer();

        $this->client->request('POST', '/api/producer/delivery-zones', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testUpdateAndDeleteSucceed(): void
    {
        [$token] = $this->loginAsProducer();

        $this->client->request('POST', '/api/producer/delivery-zones', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['radiusKm' => 10]));
        $id = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('PUT', '/api/producer/delivery-zones/'.$id, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['radiusKm' => 50]));
        self::assertResponseIsSuccessful();
        // * '50' et non '50.00' ici : cette assertion porte sur la réponse JSON immédiate du PUT
        // * (valeur PHP telle que reçue, avant tout aller-retour DB), contrairement au test précédent
        // * qui relit via un GET séparé -- ServicesResetter vide l'identity map entre deux requêtes
        // * HTTP du client de test, donc un GET ultérieur reflèterait bien '50.00' (DECIMAL(12,2)).
        self::assertSame('50', json_decode($this->client->getResponse()->getContent(), true)['radiusKm']);

        $this->client->request('DELETE', '/api/producer/delivery-zones/'.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/producer/delivery-zones', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertCount(0, json_decode($this->client->getResponse()->getContent(), true));
    }

    public function testUpdateRejectsNonOwner(): void
    {
        [$token] = $this->loginAsProducer();
        [$otherToken] = $this->loginAsProducer('BE');

        $this->client->request('POST', '/api/producer/delivery-zones', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['radiusKm' => 10]));
        $id = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('PUT', '/api/producer/delivery-zones/'.$id, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$otherToken,
        ], content: json_encode(['radiusKm' => 99]));
        self::assertResponseStatusCodeSame(404);
    }
}
