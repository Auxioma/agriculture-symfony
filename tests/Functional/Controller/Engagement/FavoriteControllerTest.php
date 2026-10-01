<?php

namespace App\Tests\Functional\Controller\Engagement;

use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste GET/POST /api/favorites et DELETE /api/favorites/{id} (cahier fonctionnel, dashboard
 * client : "Producteurs, produits, catégories" mis en favori).
 */
final class FavoriteControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    public function testAddFavoriteProducerSucceedsAndAppearsInListWithTargetDetails(): void
    {
        $token = $this->registerClientAndLogin('client');
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $country, farmName: 'Ferme du Soleil');
        $this->em->flush();

        $this->client->request('POST', '/api/favorites', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['targetType' => 'producer_profile', 'targetId' => $producer->getId()->toRfc4122()]));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/favorites', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame('producer_profile', $data[0]['targetType']);
        self::assertSame('Ferme du Soleil', $data[0]['target']['farmName']);
    }

    public function testAddFavoriteRejectsUnknownTarget(): void
    {
        $token = $this->registerClientAndLogin('client');

        $this->client->request('POST', '/api/favorites', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['targetType' => 'producer_profile', 'targetId' => '00000000-0000-4000-8000-000000000000']));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAddFavoriteRejectsInvalidTargetType(): void
    {
        $token = $this->registerClientAndLogin('client');

        $this->client->request('POST', '/api/favorites', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['targetType' => 'bogus', 'targetId' => '00000000-0000-4000-8000-000000000000']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testAddFavoriteRejectsDuplicate(): void
    {
        $token = $this->registerClientAndLogin('client');
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $country);
        $this->em->flush();

        $payload = json_encode(['targetType' => 'producer_profile', 'targetId' => $producer->getId()->toRfc4122()]);
        $this->client->request('POST', '/api/favorites', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: $payload);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/api/favorites', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: $payload);
        self::assertResponseStatusCodeSame(409);
    }

    public function testRemoveFavoriteSucceeds(): void
    {
        $token = $this->registerClientAndLogin('client');
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $country);
        $this->em->flush();

        $this->client->request('POST', '/api/favorites', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['targetType' => 'producer_profile', 'targetId' => $producer->getId()->toRfc4122()]));
        $favoriteId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('DELETE', '/api/favorites/'.$favoriteId, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/favorites', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(0, $data);
    }

    public function testRemoveFavoriteRejectsNonOwner(): void
    {
        $token = $this->registerClientAndLogin('client');
        $otherToken = $this->registerClientAndLogin('other');
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $country);
        $this->em->flush();

        $this->client->request('POST', '/api/favorites', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['targetType' => 'producer_profile', 'targetId' => $producer->getId()->toRfc4122()]));
        $favoriteId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('DELETE', '/api/favorites/'.$favoriteId, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$otherToken]);
        self::assertResponseStatusCodeSame(404);
    }
}
