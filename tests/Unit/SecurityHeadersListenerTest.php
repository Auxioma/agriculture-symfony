<?php

namespace App\Tests\Unit\EventListener;

use App\EventListener\SecurityHeadersListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Teste l'exposition de X-Total-Count au front (pagination de GET /api/producers). Test unitaire : pas de base de
 * données ni de requête HTTP, juste le listener appelé avec une requête et une réponse construites à la main.
 */
final class SecurityHeadersListenerTest extends TestCase
{
    private const FRONT_ORIGIN = 'http://localhost:4200';

    private function respondTo(Request $request): Response
    {
        $response = new Response('');
        $event = new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);

        (new SecurityHeadersListener([self::FRONT_ORIGIN]))->onKernelResponse($event);

        return $response;
    }

    private function apiRequest(?string $origin): Request
    {
        $request = Request::create('/api/producers');
        if ($origin !== null) {
            $request->headers->set('Origin', $origin);
        }

        return $request;
    }

    public function testExposesTotalCountHeaderToAnAllowedOrigin(): void
    {
        $response = $this->respondTo($this->apiRequest(self::FRONT_ORIGIN));

        self::assertSame(self::FRONT_ORIGIN, $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('X-Total-Count', $response->headers->get('Access-Control-Expose-Headers'));
    }

    public function testDoesNotExposeAnythingToAnUnknownOrigin(): void
    {
        $response = $this->respondTo($this->apiRequest('https://site-inconnu.example'));

        // * CORS strict : une origine hors CORS_ALLOWED_ORIGINS ne reçoit aucun en-tête CORS, expose-headers compris.
        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
        self::assertNull($response->headers->get('Access-Control-Expose-Headers'));
    }

    public function testDoesNotExposeAnythingWithoutAnOriginHeader(): void
    {
        $response = $this->respondTo($this->apiRequest(null));

        self::assertNull($response->headers->get('Access-Control-Expose-Headers'));
    }

    public function testDoesNotAddCorsHeadersOutsideTheApi(): void
    {
        $request = Request::create('/admin');
        $request->headers->set('Origin', self::FRONT_ORIGIN);

        $response = $this->respondTo($request);

        self::assertNull($response->headers->get('Access-Control-Expose-Headers'));
    }
}