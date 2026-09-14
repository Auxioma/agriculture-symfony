<?php

/**
 * Redirige la racine du site (admin-agriculture.trouvemoi.com) vers le dashboard back-office. Reste volontairement en
 * dehors du dashboard EasyAdmin lui-même : le dashboard doit rester sous /admin pour correspondre au
 * firewall "admin" et à l'access_control ^/admin de security.yaml, ainsi qu'à l'exclusion CSP de
 * SecurityHeadersListener -- tous ancrés sur ce préfixe littéral.
 */

namespace App\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class HomeController
{
    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): RedirectResponse
    {
        // * 301 (et non 302) : ce mapping / -> /admin est permanent, le navigateur peut le mettre en cache
        // * et sauter complètement le serveur sur les visites suivantes.
        return new RedirectResponse($this->urlGenerator->generate('admin'), RedirectResponse::HTTP_MOVED_PERMANENTLY);
    }
}
