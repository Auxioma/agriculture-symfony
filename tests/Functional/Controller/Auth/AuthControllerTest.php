<?php

namespace App\Tests\Functional\Controller\Auth;

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

    public function testExportMyDataReturnsProfileAndRequests(): void
    {
        $client = $this->makeUserWithPassword('export-client', 'motdepasse123');
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $request = $this->makeClientRequest($client, $product);
        $request->setMessage('Bonjour, je cherche des tomates.');
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $client->getEmail(),
            'password' => 'motdepasse123',
        ]));
        $token = json_decode($this->client->getResponse()->getContent(), true)['token'];

        $this->client->request('GET', '/api/me/export', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($client->getEmail(), $data['user']['email']);
        self::assertArrayNotHasKey('producerProfile', $data);
        self::assertCount(1, $data['clientRequests']);
        self::assertSame('Bonjour, je cherche des tomates.', $data['clientRequests'][0]['message']);
    }

    public function testExportMyDataIncludesProducerProfileWhenApplicable(): void
    {
        $country = $this->makeCountry();
        $owner = $this->makeUserWithPassword('export-producer', 'motdepasse123');
        $this->makeProducerProfile($owner, $country, farmName: 'Ferme Export');
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $owner->getEmail(),
            'password' => 'motdepasse123',
        ]));
        $token = json_decode($this->client->getResponse()->getContent(), true)['token'];

        $this->client->request('GET', '/api/me/export', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('producerProfile', $data);
        self::assertSame('Ferme Export', $data['producerProfile']['farmName']);
    }

    public function testDeleteMyAccountAnonymizesUser(): void
    {
        $client = $this->makeUserWithPassword('delete-me', 'motdepasse123');
        $userId = $client->getId()->toRfc4122();
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $client->getEmail(),
            'password' => 'motdepasse123',
        ]));
        $token = json_decode($this->client->getResponse()->getContent(), true)['token'];

        $this->client->request('DELETE', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(204);

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT email, first_name, status, password_hash FROM identity.users WHERE id = :id',
            ['id' => $userId]
        );
        self::assertStringContainsString('@anonymized.local', $row['email']);
        self::assertNull($row['first_name']);
        self::assertSame('deleted', $row['status']);
        self::assertSame('', $row['password_hash']);
    }

    public function testDeleteMyAccountClearsClientRequestMessages(): void
    {
        $client = $this->makeUserWithPassword('delete-me-2', 'motdepasse123');
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $clientRequest = $this->makeClientRequest($client, $product);
        $clientRequest->setMessage('Message confidentiel');
        $this->em->flush();
        $requestId = $clientRequest->getId()->toRfc4122();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $client->getEmail(),
            'password' => 'motdepasse123',
        ]));
        $token = json_decode($this->client->getResponse()->getContent(), true)['token'];

        $this->client->request('DELETE', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(204);

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT message FROM matching.client_requests WHERE id = :id',
            ['id' => $requestId]
        );
        self::assertNull($row['message']);
    }
}