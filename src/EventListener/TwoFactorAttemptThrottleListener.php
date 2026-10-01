<?php

namespace App\EventListener;

use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Scheb\TwoFactorBundle\Security\Http\Authenticator\Passport\Credentials\TwoFactorCodeCredentials;
use Scheb\TwoFactorBundle\Security\Http\Authenticator\TwoFactorAuthenticator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Cahier DevOps, sécurité "Authentification" : "protection brute force". Plafonne les essais de code 2FA (TOTP ou
 * code de secours) à 5 par 15 minutes PAR COMPTE, sans tenir compte de l'IP : le login_throttling du firewall
 * admin (security.yaml) est par compte+IP, donc une attaque répartie sur plusieurs IP le contourne -- or celui qui
 * connaît le mot de passe n'a plus que ce code de 6 chiffres (ou 8 caractères hexadécimaux pour un code de secours)
 * à deviner. Contrepartie assumée : quelqu'un qui connaît déjà le mot de passe peut verrouiller le compte 15
 * minutes en multipliant les essais ; sans le mot de passe il n'atteint pas cette étape.
 *
 * Compte chaque tentative avant la vérification du code (elle échoue donc même si le bon code est fourni une fois
 * la limite atteinte), et repart de zéro dès qu'une 2FA aboutit.
 */
class TwoFactorAttemptThrottleListener implements EventSubscriberInterface
{
    public function __construct(
        // * Nom de service explicite : plusieurs RateLimiterFactory coexistent (voir AuthController::forgotPassword()).
        #[Autowire(service: 'limiter.admin_2fa_code_attempts')]
        private readonly RateLimiterFactory $limiterFactory,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // * Priorité 10 : avant la vérification du code par Scheb (CheckTwoFactorCodeListener), sinon le code serait
        // * déjà évalué quand on constate que la limite est atteinte.
        return [
            CheckPassportEvent::class => ['onCheckPassport', 10],
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();
        if (!$passport->hasBadge(TwoFactorCodeCredentials::class) || !$passport->hasBadge(UserBadge::class)) {
            return;
        }

        $limit = $this->limiterFactory->create($this->key($passport->getBadge(UserBadge::class)->getUserIdentifier()))->consume();
        if (!$limit->isAccepted()) {
            $minutes = max(1, (int) ceil(($limit->getRetryAfter()->getTimestamp() - time()) / 60));

            throw new TooManyLoginAttemptsAuthenticationException($minutes);
        }
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $token = $event->getAuthenticatedToken();
        if (!$event->getAuthenticator() instanceof TwoFactorAuthenticator || $token instanceof TwoFactorTokenInterface) {
            return;
        }

        $this->limiterFactory->create($this->key($token->getUserIdentifier()))->reset();
    }

    private function key(string $userIdentifier): string
    {
        return mb_strtolower($userIdentifier);
    }
}
