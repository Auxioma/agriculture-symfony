<?php

namespace App\Tests\Functional;

use App\Tests\ApiTestCase;

/**
 * Teste SecurityHeadersListener (cahier DevOps : "CORS strict, headers sécurité"). CORS_ALLOWED_ORIGINS
 * vaut http://localhost:4200 dans .env, non surchargé par .env.test.
 */
final class SecurityHeadersTest extends ApiTestCase
{
    public function testPreflightRequestToApiIsAcceptedWithoutAuthentication(): void
    {
        $this->client->request('OPTIONS', '/api/categories', server: [
            'HTTP_ORIGIN' => 'http://localhost:4200',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        self::assertResponseStatusCodeSame(204);
        $response = $this->client->getResponse();
        self::assertSame('http://localhost:4200', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('GET', $response->headers->get('Access-Control-Allow-Methods'));
    }

    public function testAllowedOriginGetsCorsHeaderOnRealRequest(): void
    {
        $this->client->request('GET', '/api/categories', server: ['HTTP_ORIGIN' => 'http://localhost:4200']);

        self::assertResponseIsSuccessful();
        self::assertSame('http://localhost:4200', $this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    public function testUnknownOriginDoesNotGetCorsHeader(): void
    {
        $this->client->request('GET', '/api/categories', server: ['HTTP_ORIGIN' => 'http://evil.example']);

        self::assertResponseIsSuccessful();
        self::assertNull($this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    public function testStaticSecurityHeadersArePresentOnEveryResponse(): void
    {
        $this->client->request('GET', '/api/categories');

        $response = $this->client->getResponse();
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        self::assertNotNull($response->headers->get('Content-Security-Policy'));
    }

    // * Régression : une CSP générique sur /admin entre en conflit avec celle qu'EasyAdmin gère déjà
    // * (styles inline via nonce, Bootstrap CDN) et casse tout le rendu du back-office -- vérifié en
    // * navigateur (page de connexion sans CSS) avant ce correctif.
    public function testAdminPagesDoNotGetTheGenericContentSecurityPolicy(): void
    {
        $this->client->request('GET', '/admin/login');

        self::assertResponseIsSuccessful();
        self::assertNull($this->client->getResponse()->headers->get('Content-Security-Policy'));
    }
}
