<?php

namespace App\Tests\Functional\Controller\Engagement;

use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste GET/POST /api/saved-searches, PUT et DELETE /api/saved-searches/{id} (cahier fonctionnel,
 * dashboard client : "recherches et zones sauvegardées").
 */
final class SavedSearchControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    public function testCreateAndListSavedSearch(): void
    {
        $token = $this->registerClientAndLogin('client');

        $this->client->request('POST', '/api/saved-searches', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([
            'name' => 'Pommes bio autour de Paris',
            'criteria' => ['categoryId' => 'xyz', 'radiusKm' => 20],
            'notificationsEnabled' => true,
        ]));
        self::assertResponseStatusCodeSame(201);
        $created = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Pommes bio autour de Paris', $created['name']);
        self::assertTrue($created['notificationsEnabled']);

        $this->client->request('GET', '/api/saved-searches', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame($created['id'], $data[0]['id']);
    }

    public function testCreateSavedSearchRejectsBlankName(): void
    {
        $token = $this->registerClientAndLogin('client');

        $this->client->request('POST', '/api/saved-searches', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['name' => '']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testUpdateSavedSearchSucceeds(): void
    {
        $token = $this->registerClientAndLogin('client');

        $this->client->request('POST', '/api/saved-searches', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['name' => 'Initial']));
        $id = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('PUT', '/api/saved-searches/'.$id, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['name' => 'Renommée', 'notificationsEnabled' => true]));
        self::assertResponseIsSuccessful();
        $updated = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Renommée', $updated['name']);
        self::assertTrue($updated['notificationsEnabled']);
    }

    public function testUpdateSavedSearchRejectsNonOwner(): void
    {
        $token = $this->registerClientAndLogin('client');
        $otherToken = $this->registerClientAndLogin('other');

        $this->client->request('POST', '/api/saved-searches', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['name' => 'Mine']));
        $id = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('PUT', '/api/saved-searches/'.$id, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$otherToken,
        ], content: json_encode(['name' => 'Volée']));
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteSavedSearchSucceeds(): void
    {
        $token = $this->registerClientAndLogin('client');

        $this->client->request('POST', '/api/saved-searches', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['name' => 'À supprimer']));
        $id = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('DELETE', '/api/saved-searches/'.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/saved-searches', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(0, $data);
    }
}
