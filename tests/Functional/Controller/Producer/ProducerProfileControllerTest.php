<?php

namespace App\Tests\Functional\Controller\Producer;

use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste GET /api/producer/profile et PUT /api/producer/profile (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf, round 2).
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

    // * Simule un client promu ROLE_PRODUCER depuis le back-office (UserCrudController::configureFields(),
    // * champ "roles" générique) sans jamais passer par AuthController::registerProducer() -- donc sans
    // * ProducerProfile associé. C'est le seul cas réel où POST /api/producer/profile a un sens.
    private function promoteToProducerWithoutProfileAndLogin(): string
    {
        $owner = $this->makeUserWithPassword('promoted', 'motdepasse123');
        $owner->setRoles([User::ROLE_PRODUCER]);
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $owner->getEmail(),
            'password' => 'motdepasse123',
        ]));
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true)['token'];
    }

    public function testCreateMyProfileSucceedsForPromotedProducerAccount(): void
    {
        $country = $this->makeCountry();
        $this->em->flush();
        $token = $this->promoteToProducerWithoutProfileAndLogin();

        $this->client->request('POST', '/api/producer/profile', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([
            'farmName' => 'Ferme Nouvellement Créée',
            'countryCode' => $country->getCode(),
            'city' => 'Nantes',
        ]));

        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/producer/profile', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Ferme Nouvellement Créée', $data['farmName']);
    }

    public function testCreateMyProfileRejectsAccountWithoutProducerRole(): void
    {
        $country = $this->makeCountry();
        $this->em->flush();
        $token = $this->registerClientAndLogin();

        $this->client->request('POST', '/api/producer/profile', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['farmName' => 'Peu importe', 'countryCode' => $country->getCode()]));

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateMyProfileRejectsWhenProfileAlreadyExists(): void
    {
        $token = $this->registerProducerAndLogin();

        $this->client->request('POST', '/api/producer/profile', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['farmName' => 'Autre ferme', 'countryCode' => 'FR']));

        self::assertResponseStatusCodeSame(409);
    }

    public function testCreateMyProfileRejectsUnknownCountry(): void
    {
        $token = $this->promoteToProducerWithoutProfileAndLogin();

        $this->client->request('POST', '/api/producer/profile', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['farmName' => 'Ferme Test', 'countryCode' => 'ZZ']));

        self::assertResponseStatusCodeSame(422);
    }
}
