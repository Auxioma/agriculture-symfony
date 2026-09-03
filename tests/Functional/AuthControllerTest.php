<?php

namespace App\Tests\Functional;

use App\Tests\ApiTestCase;

class AuthControllerTest extends ApiTestCase
{
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

    // * Petit helper local pour les tests qui ont juste besoin d'un token valide, sans tester le login lui-même.
    private function registerClientAndLogin(): string
    {
        $email = 'nouveau_'.bin2hex(random_bytes(4)).'@test.local';
        $password = 'motdepasse123';

        $this->client->request('POST', '/api/auth/register-client', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'password' => $password,
            'firstName' => 'Jean',
            'lastName' => 'Dupont',
        ]));

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'password' => $password,
        ]));

        $data = json_decode($this->client->getResponse()->getContent(), true);

        return $data['token'];
    }
}