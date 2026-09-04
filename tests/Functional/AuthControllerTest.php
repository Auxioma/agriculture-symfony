<?php

namespace App\Tests\Functional;

use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste les 6 routes de AuthController (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf §20.1) en
 * conditions réelles via KernelBrowser : inscription client/producteur, connexion JWT, /api/me,
 * mot de passe oublié. registerClientAndLogin() (le helper "donne-moi juste un token valide") vit
 * dans ApiTestCase, pas ici -- ClientRequestControllerTest en a besoin aussi.
 */

class AuthControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    public function testRegisterClientCreatesAUserAndReturnsCreated(): void
    {
        $this->client->request('POST', '/api/auth/register-client', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'nouveau_'.bin2hex(random_bytes(4)).'@test.local',
            'password' => 'motdepasse123',
            'firstName' => 'Jean',
            'lastName' => 'Dupont',
        ]));

    self::assertResponseStatusCodeSame(201);
    }

    public function testLoginReturnsAJwtForValidCredentials(): void
    {
        $email = 'nouveau_'.bin2hex(random_bytes(4)).'@test.local';
        $password = 'motdepasse123';

        $this->client->request('POST', '/api/auth/register-client', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'password' => $password,
            'firstName' => 'Jean',
            'lastName' => 'Dupont',
        ]));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'password' => $password,
        ]));

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('token', $data);
    }

    public function testMeReturnsUserInfoForAuthenticatedRequest(): void
    {
        $token = $this->registerClientAndLogin();

        $this->client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
        self::assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayNotHasKey('passwordHash', $data);
    }

    public function testRegisterClientRejectsDuplicateEmail(): void
    {
        $payload = json_encode([
            'email' => 'duplique_'.bin2hex(random_bytes(4)).'@test.local',
            'password' => 'motdepasse123',
            'firstName' => 'Jean',
            'lastName' => 'Dupont',
        ]);

        $this->client->request('POST', '/api/auth/register-client', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/api/auth/register-client', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
        self::assertResponseStatusCodeSame(409);
    }

    public function testRegisterClientRejectsInvalidPayload(): void
    {
        $this->client->request('POST', '/api/auth/register-client', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'pas-un-email',
            'password' => 'court',
            'firstName' => 'Jean',
            'lastName' => 'Dupont',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testRegisterProducerCreatesUserAndProducerProfile(): void
    {
        $this->makeCountry(); // FR par défaut
        $this->em->flush();

        $this->client->request('POST', '/api/auth/register-producer', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'producteur_'.bin2hex(random_bytes(4)).'@test.local',
            'password' => 'motdepasse123',
            'firstName' => 'Marie',
            'lastName' => 'Martin',
            'farmName' => 'Ferme du Soleil',
            'countryCode' => 'FR',
        ]));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT farm_name FROM producer.producer_profiles WHERE owner_user_id = :id',
            ['id' => $data['id']]
        );
        self::assertSame('Ferme du Soleil', $row['farm_name']);
    }

    public function testRegisterProducerRejectsUnknownCountry(): void
    {
        $this->client->request('POST', '/api/auth/register-producer', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'producteur2_'.bin2hex(random_bytes(4)).'@test.local',
            'password' => 'motdepasse123',
            'firstName' => 'Marie',
            'lastName' => 'Martin',
            'farmName' => 'Ferme Test',
            'countryCode' => 'ZZ',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testLoginRejectsWrongPassword(): void
    {
        $email = 'mauvais_mdp_'.bin2hex(random_bytes(4)).'@test.local';

        $this->client->request('POST', '/api/auth/register-client', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'password' => 'motdepasse123',
            'firstName' => 'Jean',
            'lastName' => 'Dupont',
        ]));

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'password' => 'mauvais-mot-de-passe',
        ]));

        self::assertResponseStatusCodeSame(401);
    }

    public function testMeRejectsUnauthenticatedRequest(): void
    {
        $this->client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testForgotPasswordAlwaysReturns200EvenForUnknownEmail(): void
    {
        $this->client->request('POST', '/api/auth/forgot-password', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'inconnu_'.bin2hex(random_bytes(4)).'@test.local',
        ]));

        self::assertResponseStatusCodeSame(200);
    }
}