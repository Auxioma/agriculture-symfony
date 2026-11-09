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

    public function testListIsPaginatedAndTheTotalIsInTheHeader(): void
    {
        $token = $this->registerClientAndLogin('client');
        $country = $this->makeCountry();
        $producers = [];
        for ($i = 0; $i < 5; ++$i) {
            $producers[] = $this->makeProducerProfile($this->makeUser('producer'.$i), $country, farmName: 'Ferme '.$i);
        }
        $this->em->flush();
        foreach ($producers as $producer) {
            $this->client->request('POST', '/api/favorites', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: json_encode(['targetType' => 'producer_profile', 'targetId' => $producer->getId()->toRfc4122()]));
            self::assertResponseStatusCodeSame(201);
        }

        $seen = [];
        foreach ([1 => 2, 2 => 2, 3 => 1] as $page => $expectedCount) {
            $this->client->request('GET', '/api/favorites?pageSize=2&page='.$page, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

            self::assertResponseIsSuccessful();
            $data = json_decode($this->client->getResponse()->getContent(), true);
            self::assertCount($expectedCount, $data, 'page '.$page);
            self::assertSame('5', $this->client->getResponse()->headers->get('X-Total-Count'));
            $seen = [...$seen, ...array_column($data, 'id')];
        }
        // * le départage des égalités (même seconde) garde les pages stables : chaque favori une fois, aucun oublié
        self::assertCount(5, array_unique($seen));
    }

    public function testListRejectsInvalidPagination(): void
    {
        $token = $this->registerClientAndLogin('client');

        foreach (['page=0', 'pageSize=0', 'pageSize=101', 'pageSize=abc'] as $query) {
            $this->client->request('GET', '/api/favorites?'.$query, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
            self::assertResponseStatusCodeSame(400, $query);
        }
    }
}
