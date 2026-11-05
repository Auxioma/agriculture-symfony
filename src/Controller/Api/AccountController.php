<?php

namespace App\Controller\Api;

use App\Dto\Auth\ChangePasswordRequest;
use App\Dto\Auth\UpdatePreferencesRequest;
use App\Entity\Identity\User;
use App\Entity\Identity\UserPreference;
use App\Repository\Identity\UserRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Notification\NotificationPreferences;
use App\Service\Platform\PlatformSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Écran Paramètres de l'utilisateur connecté (cahier fonctionnel : tableau de bord, « Paramètres » : notifications, langue,
 * devise, sécurité). Export et suppression du compte sont dans AuthController (/api/me/export, DELETE /api/me).
 */
final class AccountController extends AbstractController
{
    #[Route('/api/me/preferences', methods: ['GET'])]
    public function getPreferences(#[CurrentUser] User $user, PlatformSettings $platform): JsonResponse
    {
        return $this->json($this->describe($user, $platform));
    }

    /**
     * Langue, devise et e-mails de notification. Rien n'existe tant que l'utilisateur n'a rien changé (la table est vide, voir
     * UserPreference) : la première écriture crée la ligne.
     */
    #[Route('/api/me/preferences', methods: ['PUT'])]
    public function updatePreferences(
        #[MapRequestPayload] UpdatePreferencesRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        PlatformSettings $platform,
    ): JsonResponse {
        $preference = $user->getPreference();
        if ($preference === null) {
            $preference = new UserPreference();
            $preference->setIdUser($user);
            $preference->setLocale($user->getLocale());
            $user->setPreference($preference);
            $em->persist($preference);
        }

        if ($request->locale !== null) {
            $preference->setLocale($request->locale);
            // * User::$locale est le réglage réellement lu par le reste de l'API : les deux restent d'accord
            $user->setLocale($request->locale);
        }
        if ($request->currency !== null) {
            $preference->setCurrency($request->currency);
        }
        if ($request->notifications !== null) {
            $settings = $preference->getNotificationSettings() ?? [];
            $settings['types'] = [...NotificationPreferences::settings($settings), ...$request->notifications];
            $preference->setNotificationSettings($settings);
        }
        $em->flush();

        return $this->json($this->describe($user, $platform));
    }

    /**
     * Changement de mot de passe d'un compte connecté. Le mot de passe actuel est redemandé (une session ouverte ne suffit
     * pas) avec un plafond de 5 essais par 15 minutes et par compte, comme la suppression du compte. Les sessions ouvertes
     * sont fermées : le jeton en cours reste valable jusqu'à son expiration, pas les autres.
     */
    #[Route('/api/me/password', methods: ['PUT'])]
    public function changePassword(
        #[MapRequestPayload] ChangePasswordRequest $request,
        #[CurrentUser] User $user,
        UserPasswordHasherInterface $hasher,
        UserRepository $users,
        EntityManagerInterface $em,
        AuditLogger $audit,
        #[Autowire(service: 'limiter.password_confirmation')] RateLimiterFactory $passwordConfirmationLimiter,
    ): JsonResponse {
        if (!$passwordConfirmationLimiter->create($user->getId()->toRfc4122())->consume()->isAccepted()) {
            return $this->json(['error' => 'Trop de tentatives, réessayez dans quelques minutes.'], 429);
        }
        if (!$hasher->isPasswordValid($user, $request->currentPassword)) {
            return $this->json(['error' => 'Mot de passe actuel incorrect.'], 422);
        }
        if ($request->currentPassword === $request->newPassword) {
            return $this->json(['error' => "Le nouveau mot de passe doit être différent de l'actuel."], 422);
        }

        $users->upgradePassword($user, $hasher->hashPassword($user, $request->newPassword));
        $em->getConnection()->executeStatement(
            'UPDATE identity.refresh_tokens SET revoked_at = now() WHERE user_id = :id AND revoked_at IS NULL',
            ['id' => $user->getId()->toRfc4122()]
        );
        // * ni l'ancien ni le nouveau mot de passe dans la trace
        $audit->log('password_changed', 'identity', 'users', $user->getId()->toRfc4122());
        $em->flush();

        return $this->json(null, 204);
    }

    /**
     * @return array{locale: string, currency: string, notifications: array<string, bool>, supportEmail: string}
     */
    private function describe(User $user, PlatformSettings $platform): array
    {
        $preference = $user->getPreference();

        return [
            'locale' => $preference?->getLocale() ?? $user->getLocale(),
            'currency' => $preference?->getCurrency() ?? $platform->get(PlatformSettings::DEFAULT_CURRENCY),
            'notifications' => NotificationPreferences::settings($preference?->getNotificationSettings()),
            // * l'adresse du support est un réglage de l'admin (Paramètres de la plateforme) : l'écran Paramètres la lit ici
            'supportEmail' => $platform->get(PlatformSettings::SUPPORT_EMAIL),
        ];
    }
}
