<?php
namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Cahier DevOps -- "CORS strict, headers sécurité" : en-têtes statiques sur toute réponse, CORS restreint
 * au front Angular sur /api. Pas de bundle Nelmio ici (même raisonnement que pour app:send-expiry-reminders
 * / symfony-scheduler) : liste d'origines fixe + 5 en-têtes, un listener suffit et reste 100% vérifiable.
 */

class SecurityHeadersListener implements EventSubscriberInterface
{
    /** @param string[] $allowedOrigins */
    public function __construct(private readonly array $allowedOrigins)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 250],
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    // * Le firewall Symfony (priorité 8) rejetterait un preflight OPTIONS sans token JWT avant même d'arriver
    // * ici -- priorité 250 court-circuite la requête (setResponse()) avant que le firewall ne s'exécute.
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ('OPTIONS' !== $request->getMethod() || !str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $event->setResponse(new Response('', 204));
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), camera=(), microphone=()');

        // * EasyAdmin gère sa propre CSP (styles inline via nonce, Bootstrap depuis un CDN) pour ses pages
        // * /admin -- un default-src 'self' générique écrase/entre en conflit avec elle et casse tout le
        // * rendu du back-office (vérifié en navigateur : page de connexion sans CSS, CDN bloqué, styles
        // * inline bloqués). On ne pose donc cette CSP que hors /admin, là où rien d'autre ne la gère déjà.
        if (!str_starts_with($request->getPathInfo(), '/admin')) {
            $response->headers->set('Content-Security-Policy', "default-src 'self'; frame-ancestors 'none'");
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $origin = $request->headers->get('Origin');
        if (null !== $origin && \in_array($origin, $this->allowedOrigins, true)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Vary', 'Origin');
        }

        if ('OPTIONS' === $request->getMethod()) {
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
            $response->headers->set('Access-Control-Max-Age', '3600');
        }
    }
}