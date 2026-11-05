<?php

namespace App\Tests\Functional\Controller\Producer;

use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Teste GET/POST/PUT /api/producer/opening-hours (le PUT remplace la semaine entière) et PUT/DELETE
 * /api/producer/opening-hours/{id} (cahier fonctionnel, profil producteur enrichi), ainsi que leur exposition publique sur
 * GET /api/producers/{id}. Les jours vont de 1 (lundi) à 7 (dimanche).
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
        ], content: json_encode(['weekday' => 1, 'opensAt' => '08:00', 'closesAt' => '12:30']));
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
        self::assertSame(1, $public['openingHours'][0]['weekday']);
    }

    public function testCreateClosedDayIgnoresProvidedTimes(): void
    {
        [$token] = $this->loginAsProducer();

        $this->client->request('POST', '/api/producer/opening-hours', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['weekday' => 7, 'opensAt' => '08:00', 'isClosed' => true]));
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
        ], content: json_encode(['weekday' => 1, 'opensAt' => '8h00']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateRejectsInvalidWeekday(): void
    {
        [$token] = $this->loginAsProducer();

        // * 1 (lundi) à 7 (dimanche) : 0 était l'ancienne numérotation du back, le front et la base de dev n'ont jamais compté ainsi
        foreach ([0, 8, 9] as $weekday) {
            $this->client->request('POST', '/api/producer/opening-hours', server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ], content: json_encode(['weekday' => $weekday]));

            self::assertResponseStatusCodeSame(422, 'weekday '.$weekday);
        }
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

    /**
     * @param list<array{weekday: int, opensAt: string, closesAt: string}> $hours
     */
    private function replaceHours(string $token, array $hours): void
    {
        $this->client->request('PUT', '/api/producer/opening-hours', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['hours' => $hours]));
    }

    public function testReplaceOpeningHoursSwapsTheWholeWeek(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        foreach ([1, 2] as $weekday) {
            $this->client->request('POST', '/api/producer/opening-hours', server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ], content: json_encode(['weekday' => $weekday, 'opensAt' => '09:00', 'closesAt' => '18:00']));
        }

        // * envoyés dans le désordre, deux créneaux le même jour (matin et après-midi)
        $this->replaceHours($token, [
            ['weekday' => 6, 'opensAt' => '09:00', 'closesAt' => '13:00'],
            ['weekday' => 1, 'opensAt' => '14:00', 'closesAt' => '18:00'],
            ['weekday' => 1, 'opensAt' => '08:00', 'closesAt' => '12:00'],
        ]);

        self::assertResponseIsSuccessful();
        $given = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame([[1, '08:00', '12:00'], [1, '14:00', '18:00'], [6, '09:00', '13:00']], array_map(static fn (array $h) => [$h['weekday'], $h['opensAt'], $h['closesAt']], $given));

        // * les anciens créneaux (lundi et mardi 9h-18h) ont disparu, la liste et la fiche publique donnent la nouvelle semaine
        $this->client->request('GET', '/api/producer/opening-hours', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $listed = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame([[1, '08:00'], [1, '14:00'], [6, '09:00']], array_map(static fn (array $h) => [$h['weekday'], $h['opensAt']], $listed));
        self::assertSame([false, false, false], array_column($listed, 'isClosed'));

        $this->client->request('GET', '/api/producers/'.$producer->getId()->toRfc4122());
        self::assertCount(3, json_decode($this->client->getResponse()->getContent(), true)['openingHours']);
    }

    public function testReplaceWithAnEmptyListClosesEveryDay(): void
    {
        [$token] = $this->loginAsProducer();
        $this->replaceHours($token, [['weekday' => 3, 'opensAt' => '09:00', 'closesAt' => '18:00']]);

        $this->replaceHours($token, []);

        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/producer/opening-hours', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertSame([], json_decode($this->client->getResponse()->getContent(), true));
    }

    public function testReplaceWithoutTheHoursFieldKeepsTheExistingOnes(): void
    {
        [$token] = $this->loginAsProducer();
        $this->replaceHours($token, [['weekday' => 3, 'opensAt' => '09:00', 'closesAt' => '18:00']]);

        // * un champ oublié n'est pas « fermé toute la semaine »
        $this->client->request('PUT', '/api/producer/opening-hours', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([]));

        self::assertResponseStatusCodeSame(422);
        $this->client->request('GET', '/api/producer/opening-hours', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertCount(1, json_decode($this->client->getResponse()->getContent(), true));
    }

    /**
     * @return iterable<string, array{0: list<array<string, mixed>>}>
     */
    public static function badSlots(): iterable
    {
        yield 'lundi avant 1' => [[['weekday' => 0, 'opensAt' => '09:00', 'closesAt' => '18:00']]];
        yield 'après le dimanche' => [[['weekday' => 8, 'opensAt' => '09:00', 'closesAt' => '18:00']]];
        yield 'jour en texte' => [[['weekday' => '1', 'opensAt' => '09:00', 'closesAt' => '18:00']]];
        yield 'heure mal écrite' => [[['weekday' => 1, 'opensAt' => '9h', 'closesAt' => '18:00']]];
        yield 'heure impossible' => [[['weekday' => 1, 'opensAt' => '09:00', 'closesAt' => '24:30']]];
        yield 'fermeture manquante' => [[['weekday' => 1, 'opensAt' => '09:00']]];
        yield 'champ en trop' => [[['weekday' => 1, 'opensAt' => '09:00', 'closesAt' => '18:00', 'isClosed' => true]]];
        yield 'fermeture avant ouverture' => [[['weekday' => 1, 'opensAt' => '18:00', 'closesAt' => '09:00']]];
        yield 'ouverture égale à la fermeture' => [[['weekday' => 1, 'opensAt' => '09:00', 'closesAt' => '09:00']]];
        yield 'trop de créneaux' => [array_fill(0, 29, ['weekday' => 1, 'opensAt' => '09:00', 'closesAt' => '18:00'])];
    }

    /**
     * @param list<array<string, mixed>> $hours
     */
    #[DataProvider('badSlots')]
    public function testReplaceRejectsBadSlotsAndKeepsTheExistingWeek(array $hours): void
    {
        [$token] = $this->loginAsProducer();
        $this->replaceHours($token, [['weekday' => 3, 'opensAt' => '09:00', 'closesAt' => '18:00']]);

        $this->client->request('PUT', '/api/producer/opening-hours', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['hours' => $hours]));

        self::assertResponseStatusCodeSame(422);
        $this->client->request('GET', '/api/producer/opening-hours', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertCount(1, json_decode($this->client->getResponse()->getContent(), true));
    }

    public function testReplaceNeverTouchesAnotherProducersHours(): void
    {
        [$token] = $this->loginAsProducer();
        [$otherToken] = $this->loginAsProducer('BE');
        $this->replaceHours($otherToken, [['weekday' => 2, 'opensAt' => '10:00', 'closesAt' => '16:00']]);

        $this->replaceHours($token, []);

        $this->client->request('GET', '/api/producer/opening-hours', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$otherToken]);
        self::assertCount(1, json_decode($this->client->getResponse()->getContent(), true));
    }

    public function testReplaceRejectsAccountWithoutProducerProfile(): void
    {
        $client = $this->makeUserWithPassword('client', 'motdepasse123');
        $this->em->flush();
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $client->getEmail(),
            'password' => 'motdepasse123',
        ]));
        $token = json_decode($this->client->getResponse()->getContent(), true)['token'];

        $this->replaceHours($token, []);

        self::assertResponseStatusCodeSame(403);
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
