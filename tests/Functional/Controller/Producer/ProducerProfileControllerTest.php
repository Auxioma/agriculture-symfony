<?php

namespace App\Tests\Functional;

use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste GET /api/producer/profile et PUT /api/producer/profile (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf §20.3, round 2).
 * Routes réservées au producteur propriétaire du profil.
 */
final class ProducerProfileControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private function registerProducerAndLogin(): string
    {
        $country = $this->makeCountry();
        // * makeUserWithPassword() (pas makeUser()) : ce compte doit pouvoir se logger pour de vrai
        // * via /api/auth/login plus bas.
        $owner = $this->makeUserWithPassword('producer', 'motdepasse123');
        $owner->setRoles([User::ROLE_PRODUCER]);
        $this->makeProducerProfile($owner, $country, farmName: 'Ferme Origine');
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $owner->getEmail(),
            'password' => 'motdepasse123',
        ]));
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true)['token'];
    }

    public function testGetMyProfileReturnsData(): void
    {
        $token = $this->registerProducerAndLogin();

        $this->client->request('GET', '/api/producer/profile', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Ferme Origine', $data['farmName']);
        self::assertArrayHasKey('slug', $data);
        self::assertArrayHasKey('verificationStatus', $data);
    }

    public function testUpdateMyProfileChangesFields(): void
    {
        $token = $this->registerProducerAndLogin();

        $this->client->request('PUT', '/api/producer/profile', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([
            'farmName' => 'Ferme Renommée',
            'description' => 'Nouvelle description',
            'city' => 'Lyon',
            'latitude' => 45.75,
            'longitude' => 4.85,
        ]));
        self::assertResponseIsSuccessful();

        // * Vérifie via une nouvelle requête GET plutôt qu'en réutilisant l'entité $producer d'avant les
        // * appels HTTP -- ServicesResetter vide l'identity map de l'EntityManager après chaque requête.
        $this->client->request('GET', '/api/producer/profile', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Ferme Renommée', $data['farmName']);
        self::assertSame('Nouvelle description', $data['description']);
        self::assertSame('Lyon', $data['city']);
    }

    public function testUpdateMyProfileRejectsEmptyFarmName(): void
    {
        $token = $this->registerProducerAndLogin();

        $this->client->request('PUT', '/api/producer/profile', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['farmName' => '']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testProfileRoutesRejectAccountWithoutProducerProfile(): void
    {
        // * Un compte client "normal" (sans ProducerProfile) ne doit pas pouvoir consulter ni modifier cette route.
        $token = $this->registerClientAndLogin();

        $this->client->request('GET', '/api/producer/profile', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('PUT', '/api/producer/profile', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['farmName' => 'Peu importe']));
        self::assertResponseStatusCodeSame(403);
    }
}
