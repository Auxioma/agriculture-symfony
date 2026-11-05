<?php

namespace App\Tests\Functional;

use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

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

    // * Régression : une CSP générique (default-src 'self') sur /admin casse tout le rendu du back-office -- vérifié
    // * en navigateur (page de connexion sans CSS). On n'y met donc pas de default-src ni de script-src.
    public function testAdminPagesDoNotGetTheGenericContentSecurityPolicy(): void
    {
        $this->client->request('GET', '/admin/login');

        self::assertResponseIsSuccessful();
        $csp = (string) $this->client->getResponse()->headers->get('Content-Security-Policy');
        self::assertStringNotContainsString('default-src', $csp);
        self::assertStringNotContainsString('script-src', $csp);
        self::assertStringNotContainsString('style-src', $csp);
    }

    // * L'admin pouvait être affichée dans une iframe d'un autre site (clickjacking sur Valider, Anonymiser...) :
    // * aucun en-tête ne l'interdisait sur /admin.
    public function testAdminPagesCannotBeFramed(): void
    {
        $this->client->request('GET', '/admin/login');

        $headers = $this->client->getResponse()->headers;
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        $csp = (string) $headers->get('Content-Security-Policy');
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringContainsString("form-action 'self'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringContainsString("base-uri 'self'", $csp);
    }

    // * Les pages avec des boutons (Valider, Anonymiser...) sont celles d'après la connexion : c'est elles qu'une iframe
    // * piégée viserait, pas seulement la page de connexion.
    public function testLoggedInAdminPagesCannotBeFramedEither(): void
    {
        $admin = new User();
        $admin->setEmail('admin_'.bin2hex(random_bytes(6)).'@test.local');
        $admin->setPasswordHash(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($admin, 'motdepasse123'));
        $admin->setRoles([User::ROLE_ADMIN]);
        $this->em->persist($admin);
        $this->em->flush();

        $this->client->followRedirects(true);
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Se connecter', ['_username' => $admin->getEmail(), '_password' => 'motdepasse123']);
        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $headers = $this->client->getResponse()->headers;
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertStringContainsString("frame-ancestors 'none'", (string) $headers->get('Content-Security-Policy'));
    }
}
