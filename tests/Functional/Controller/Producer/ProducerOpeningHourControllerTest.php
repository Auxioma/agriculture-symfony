<?php

namespace App\Tests\Functional\Controller\Producer;

use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste GET/POST /api/producer/opening-hours et PUT/DELETE /api/producer/opening-hours/{id}
 * (cahier fonctionnel, profil producteur enrichi), ainsi que leur exposition publique sur
 * GET /api/producers/{id}.
 */
final class ProducerOpeningHourControllerTest extends ApiTestCase
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

    public function testCreateOpeningHourSucceedsAndAppearsInListAndPublicRoute(): void
    {
        [$token, $producer] = $this->loginAsProducer();

        $this->client->request('POST', '/api/producer/opening-hours', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['weekday' => 0, 'opensAt' => '08:00', 'closesAt' => '12:30']));
        self::assertResponseStatusCodeSame(201);
        $created = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('08:00', $created['opensAt']);
        self::assertSame('12:30', $created['closesAt']);

        $this->client->request('GET', '/api/producer/opening-hours', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        self::assertCount(1, json_decode($this->client->getResponse()->getContent(), true));

        $this->client->request('GET', '/api/producers/'.$producer->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        $public = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $public['openingHours']);
        self::assertSame(0, $public['openingHours'][0]['weekday']);
    }

    public function testCreateClosedDayIgnoresProvidedTimes(): void
    {
        [$token] = $this->loginAsProducer();

        $this->client->request('POST', '/api/producer/opening-hours', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['weekday' => 6, 'opensAt' => '08:00', 'isClosed' => true]));
        self::assertResponseStatusCodeSame(201);
        $created = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($created['isClosed']);
        self::assertNull($created['opensAt']);
    }

    public function testCreateRejectsInvalidTimeFormat(): void
    {
        [$token] = $this->loginAsProducer();

        $this->client->request('POST', '/api/producer/opening-hours', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['weekday' => 0, 'opensAt' => '8h00']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateRejectsInvalidWeekday(): void
    {
        [$token] = $this->loginAsProducer();

        $this->client->request('POST', '/api/producer/opening-hours', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['weekday' => 9]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testUpdateAndDeleteSucceed(): void
    {
        [$token] = $this->loginAsProducer();

        $this->client->request('POST', '/api/producer/opening-hours', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['weekday' => 1, 'opensAt' => '09:00', 'closesAt' => '18:00']));
        $id = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('PUT', '/api/producer/opening-hours/'.$id, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['weekday' => 1, 'isClosed' => true]));
        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode($this->client->getResponse()->getContent(), true)['isClosed']);

        $this->client->request('DELETE', '/api/producer/opening-hours/'.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/producer/opening-hours', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertCount(0, json_decode($this->client->getResponse()->getContent(), true));
    }

    public function testUpdateRejectsNonOwner(): void
    {
        [$token] = $this->loginAsProducer();
        [$otherToken] = $this->loginAsProducer('BE');

        $this->client->request('POST', '/api/producer/opening-hours', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['weekday' => 2]));
        $id = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('PUT', '/api/producer/opening-hours/'.$id, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$otherToken,
        ], content: json_encode(['weekday' => 3]));
        self::assertResponseStatusCodeSame(404);
    }
}
