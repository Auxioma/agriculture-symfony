<?php

namespace App\Controller\Api;

use App\Dto\Auth\ForgotPasswordRequest;
use App\Dto\Auth\RegisterClientRequest;
use App\Dto\Auth\RegisterProducerRequest;
use App\Dto\Auth\ResetPasswordRequest;
use App\Entity\Catalog\Country;
use App\Entity\Identity\PasswordResetToken;
use App\Entity\Identity\User;
use App\Entity\Producer\ProducerProfile;
use App\Repository\Identity\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * register-client, register-producer, logout, forgot-password, reset-password, et GET /api/me.
 * /api/auth/login est traitée par le firewall json_login (security.yaml), pas par le corps de la méthode
 * login() ci-dessous.
 *
 * /api/me (hors de /api/auth) ne peut pas partager un préfixe commun avec les autres routes. Chaque route
 * porte donc son chemin complet.
 */

final class AuthController extends AbstractController
{
    #[Route('/api/auth/login', methods: ['POST'])]
    public function login(): never
    {
        throw new \LogicException('Cette route est interceptée par le firewall json_login avant d\'atteindre le contrôleur.');
    }

    #[Route('/api/auth/register-client', methods: ['POST'])]
    public function registerClient(
        #[MapRequestPayload] RegisterClientRequest $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): JsonResponse {
        if ($em->getRepository(User::class)->findOneBy(['email' => $request->email]) !== null) {
            return $this->json(['error' => 'Un compte existe déjà avec cet email.'], 409);
        }

        $user = new User();
        $user->setEmail($request->email);
        $user->setFirstName($request->firstName);
        $user->setLastName($request->lastName);
        $user->setPasswordHash($hasher->hashPassword($user, $request->password));
        // * Rôle déjà ROLE_CLIENT par défaut (constructeur de User) : rien à définir explicitement ici

        $em->persist($user);
        $em->flush();

        return $this->json(['id' => $user->getId()->toRfc4122()], 201);
    }


    #[Route('/api/auth/register-producer', methods: ['POST'])]
    public function registerProducer(
        #[MapRequestPayload] RegisterProducerRequest $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): JsonResponse {
        if ($em->getRepository(User::class)->findOneBy(['email' => $request->email]) !== null) {
            return $this->json(['error' => 'Un compte existe déjà avec cet email.'], 409);
        }

        $country = $em->find(Country::class, strtoupper($request->countryCode));
        if ($country === null) {
            return $this->json(['error' => 'Pays inconnu.'], 422);
        }

        $user = new User();
        $user->setEmail($request->email);
        $user->setFirstName($request->firstName);
        $user->setLastName($request->lastName);
        $user->setPasswordHash($hasher->hashPassword($user, $request->password));
        $user->setRoles([User::ROLE_PRODUCER]);

        $producer = new ProducerProfile();
        $producer->setFarmName($request->farmName);
        $producer->setSlug($this->generateUniqueSlug($request->farmName));
        $producer->setCountry($country);
        // * Sync bidirectionnelle déjà écrite dans User::setProducerProfile() (met aussi producer->owner)
        $user->setProducerProfile($producer);

        // ! Un seul persist($user) suffit : User::$producerProfile porte cascade: ['persist'],
        // ! donc persister le User persiste automatiquement le ProducerProfile associé
        $em->persist($user);
        $em->flush();

        return $this->json(['id' => $user->getId()->toRfc4122()], 201);
    }

    // ? Suffixe aléatoire pour rendre une collision de slug quasi impossible ; la contrainte UNIQUE
    // ? (citext) en base rattrape de toute façon le cas résiduel avec une exception explicite.
    private function generateUniqueSlug(string $farmName): string
    {
        $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $farmName), '-'));

        return $base . '-' . bin2hex(random_bytes(3));
    }

    #[Route('/api/auth/logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        // * Stateless JWT : rien à révoquer côté serveur pour le MVP, le front oublie simplement le token.
        // * La route reste protégée par le firewall "api" (JWT obligatoire) même sans #[CurrentUser] ici.
        return new JsonResponse(null, 204);
    }

    #[Route('/api/auth/forgot-password', methods: ['POST'])]
    public function forgotPassword(
        #[MapRequestPayload] ForgotPasswordRequest $request,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        // ! L'auto-wiring par convention de nom (password_reset_requests -> $passwordResetRequestsLimiter)
        // ! ne suffit pas ici (plusieurs RateLimiterFactory coexistent, dont celles de login_throttling) :
        // ! il faut référencer le service exact.
        #[Autowire(service: 'limiter.password_reset_requests')]
        RateLimiterFactory $passwordResetRequestsLimiter,
    ): JsonResponse {
        $limiter = $passwordResetRequestsLimiter->create($request->email);
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de tentatives, réessayez plus tard.'], 429);
        }
        
        // ! Le reste de la méthode est inchangé : on ne révèle pas si l'email existe ou non, pour éviter l'énumération de comptes.
        $user = $em->getRepository(User::class)->findOneBy(['email' => $request->email]);

        if ($user !== null) {
            $plainToken = bin2hex(random_bytes(32));

            $resetToken = new PasswordResetToken();
            $resetToken->setTokenHash(hash('sha256', $plainToken));
            $resetToken->setExpiresAt(new \DateTimeImmutable('+1 hour'));
            $user->addPasswordResetToken($resetToken);

            // ! Pas de cascade persist ici (contrairement à producerProfile plus haut) : User::$passwordResetTokens
            // ! n'a que orphanRemoval, donc ce persist() explicite est obligatoire.
            $em->persist($resetToken);
            $em->flush();

            $mailer->send(
                (new Email())
                    ->to($user->getEmail())
                    ->subject('Réinitialisation de votre mot de passe')
                    ->text('Voici votre code de réinitialisation : '.$plainToken)
            );
        }

        // Toujours 200, même si l'email n'existe pas -- anti-énumération de comptes
        return $this->json(null, 200);
    }


    #[Route('/api/auth/reset-password', methods: ['POST'])]
    public function resetPassword(
        #[MapRequestPayload] ResetPasswordRequest $request,
        EntityManagerInterface $em,
        UserRepository $userRepository,
        UserPasswordHasherInterface $hasher,
    ): JsonResponse {
        $tokenHash = hash('sha256', $request->token);
        $resetToken = $em->getRepository(PasswordResetToken::class)->findOneBy(['tokenHash' => $tokenHash]);

        $isValid = $resetToken !== null
            && $resetToken->getUsedAt() === null
            && $resetToken->getExpiresAt() > new \DateTimeImmutable();

        if (!$isValid) {
            return $this->json(['error' => 'Token invalide ou expiré.'], 422);
        }

        $user = $resetToken->getIdUser();
        $newHash = $hasher->hashPassword($user, $request->newPassword);
        // * upgradePassword() est déjà écrit et déjà testé (UserSecurityTest::testUpgradePasswordUpdatesTheHash)
        $userRepository->upgradePassword($user, $newHash);

        $resetToken->setUsedAt(new \DateTimeImmutable());
        // * upgradePassword() a déjà flush le mot de passe ; ce flush-ci sauve juste usedAt.
        $em->flush();

        return $this->json(null, 200);
    }

    #[Route('/api/me', methods: ['GET'])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        // !Tableau construit à la main plutôt que sérialiser $user brut : évite d'exposer passwordHash
        // ! par accident si un jour la sérialisation par défaut change
        return $this->json([
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'roles' => $user->getRoles(),
        ]);
    }
}