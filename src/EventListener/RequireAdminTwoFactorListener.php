<?php

namespace App\EventListener;

use App\Entity\Identity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Cahier DevOps, sécurité DevSecOps -- "Admin : ... 2FA obligatoire". Tout compte pleinement authentifié
 * sur /admin (ROLE_SUPPORT et au-dessus, voir role_hierarchy dans security.yaml) qui n'a pas encore activé sa
 * 2FA est renvoyé vers l'écran d'activation (DashboardController::twoFactorSetup()) : impossible d'atteindre
 * le reste du back-office tant que ce n'est pas fait.
 *
 * Un compte qui a déjà activé la 2FA n'est jamais concerné ici : Scheb (firewall "admin", two_factor:) exige
 * son code à la connexion et son jeton reste "2FA en cours" (donc sans ROLE_SUPPORT) jusqu'à ce qu'il soit saisi.
 */
class RequireAdminTwoFactorListener implements EventSubscriberInterface
{
    // * Routes qui doivent rester atteignables pour un compte sans 2FA : l'activation elle-même, sa page de
    // * révélation des codes de secours, et la sortie (déconnexion) si l'admin renonce.
    private const ALLOWED_ROUTES = ['admin_2fa_setup', 'admin_2fa_setup_done', 'admin_login', 'admin_logout'];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
        // * ADMIN_2FA_REQUIRED=1 par défaut (.env) : l'exigence du cahier. Passé à 0 uniquement dans .env.test
        // * (la suite de tests se connecte en admin sans 2FA) ou en développement local via .env.local.
        #[Autowire(env: 'bool:ADMIN_2FA_REQUIRED')]
        private readonly bool $required,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // * Priorité 5 : après le firewall Symfony (8), qui doit avoir authentifié la requête pour qu'un
        // * utilisateur soit connu ici.
        return [KernelEvents::REQUEST => ['onKernelRequest', 5]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->required || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/admin')) {
            return;
        }

        if (\in_array($request->attributes->get('_route'), self::ALLOWED_ROUTES, true)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || !$this->security->isGranted('ROLE_SUPPORT') || $user->isTotpAuthenticationEnabled()) {
            return;
        }

        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('warning', 'La double authentification est obligatoire pour accéder au back-office : activez-la pour continuer.');
        }
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('admin_2fa_setup')));
    }
}
