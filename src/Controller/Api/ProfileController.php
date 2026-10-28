<?php

namespace App\Controller\Api;

use App\Dto\Profile\AddressRequest;
use App\Dto\Profile\ChangePasswordRequest;
use App\Dto\Profile\UpdatePreferencesRequest;
use App\Entity\Identity\User;
use App\Entity\Identity\UserAddress;
use App\Entity\Identity\UserPreference;
use App\Repository\Identity\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Page "Profil" du dashboard client : préférences (langue, devise, notifications), carnet d'adresses
 * et changement de mot de passe. Export et suppression du compte restent dans AuthController.
 */
final class ProfileController extends AbstractController
{
    private const DEFAULT_NOTIFICATIONS = [
        'producerReply' => true,
        'newMessage' => true,
        'requestExpiring' => false,
    ];

    // ---------------------------------------------------------------- Préférences

    #[Route('/api/me/preferences', methods: ['GET'])]
    public function getPreferences(#[CurrentUser] User $user): JsonResponse
    {
        // * Une requête GET ne doit rien écrire en base : sans ligne, on renvoie les valeurs par défaut.
        return $this->json($this->serializePreferences($user));
    }

    #[Route('/api/me/preferences', methods: ['PATCH'])]
    public function updatePreferences(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdatePreferencesRequest $request,
        EntityManagerInterface $em,
    ): JsonResponse {
        $preference = $user->getPreference();

        if ($preference === null) {
            $preference = new UserPreference();
            // ! Ordre important : $idUser est une propriété typée non initialisée, donc la lire avant
            // ! de l'avoir définie lève une Error. setPreference() la lit, on définit donc l'user d'abord.
            $preference->setIdUser($user);
            $preference->setLocale($user->getLocale());
            $user->setPreference($preference);
            $em->persist($preference);
        }

        if ($request->locale !== null) {
            $preference->setLocale($request->locale);
            $user->setLocale($request->locale); // garde User::$locale (utilisé ailleurs) synchronisé
        }
        if ($request->currency !== null) {
            $preference->setCurrency($request->currency);
        }
        if ($request->notificationSettings !== null) {
            $preference->setNotificationSettings(array_merge(
                self::DEFAULT_NOTIFICATIONS,
                $preference->getNotificationSettings() ?? [],
                $request->notificationSettings,
            ));
        }

        $em->flush();

        return $this->json($this->serializePreferences($user));
    }

    /** @return array{locale: string, currency: string, notificationSettings: array<string, bool>} */
    private function serializePreferences(User $user): array
    {
        $preference = $user->getPreference();

        return [
            'locale' => $preference?->getLocale() ?? $user->getLocale(),
            'currency' => $preference?->getCurrency() ?? 'EUR',
            'notificationSettings' => array_merge(
                self::DEFAULT_NOTIFICATIONS,
                $preference?->getNotificationSettings() ?? [],
            ),
        ];
    }

    // ---------------------------------------------------------------- Adresses

    #[Route('/api/me/addresses', methods: ['GET'])]
    public function listAddresses(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $addresses = $em->getRepository(UserAddress::class)->findBy(['idUser' => $user], ['isDefault' => 'DESC']);

        return $this->json(array_map($this->serializeAddress(...), $addresses));
    }

    #[Route('/api/me/addresses', methods: ['POST'])]
    public function createAddress(
        #[CurrentUser] User $user,
        #[MapRequestPayload] AddressRequest $request,
        EntityManagerInterface $em,
    ): JsonResponse {
        $address = new UserAddress();
        $user->addUserAddress($address);
        $this->hydrateAddress($address, $request);

        // * La première adresse devient automatiquement l'adresse par défaut
        if ($user->getUserAddresses()->count() === 1) {
            $address->setIsDefault(true);
        }
        if ($address->isDefault()) {
            $this->unsetOtherDefaults($user, $address);
        }

        // ! User::$userAddresses n'a pas cascade persist : ce persist() est obligatoire
        $em->persist($address);
        $em->flush();

        return $this->json($this->serializeAddress($address), 201);
    }

    #[Route('/api/me/addresses/{id}', methods: ['PUT'], requirements: ['id' => Requirement::UUID])]
    public function updateAddress(
        string $id,
        #[CurrentUser] User $user,
        #[MapRequestPayload] AddressRequest $request,
        EntityManagerInterface $em,
    ): JsonResponse {
        $address = $this->findOwnedAddress($id, $user, $em);
        if ($address === null) {
            return $this->json(['error' => 'Adresse introuvable.'], 404);
        }

        $this->hydrateAddress($address, $request);
        if ($address->isDefault()) {
            $this->unsetOtherDefaults($user, $address);
        }
        $em->flush();

        return $this->json($this->serializeAddress($address));
    }

    #[Route('/api/me/addresses/{id}', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    public function deleteAddress(string $id, #[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $address = $this->findOwnedAddress($id, $user, $em);
        if ($address === null) {
            return $this->json(['error' => 'Adresse introuvable.'], 404);
        }

        $wasDefault = $address->isDefault();
        $user->removeUserAddress($address); // orphanRemoval : Doctrine supprime la ligne au flush

        // * Si on supprime l'adresse par défaut, une autre prend le relais
        if ($wasDefault) {
            $next = $user->getUserAddresses()->first();
            if ($next !== false) {
                $next->setIsDefault(true);
            }
        }
        $em->flush();

        return $this->json(null, 204);
    }

    // ! Filtre par propriétaire : sans 'idUser' => $user, un utilisateur pourrait modifier l'adresse d'un
    // ! autre en changeant l'id dans l'URL (faille IDOR). On renvoie 404 (pas 403) pour ne rien révéler.
    private function findOwnedAddress(string $id, User $user, EntityManagerInterface $em): ?UserAddress
    {
        return $em->getRepository(UserAddress::class)->findOneBy(['id' => $id, 'idUser' => $user]);
    }

    private function hydrateAddress(UserAddress $address, AddressRequest $request): void
    {
        $address->setLabel($request->label);
        $address->setAddress1($request->address1);
        $address->setAddress2($request->address2);
        $address->setCity($request->city);
        $address->setPostalCode($request->postalCode);
        $address->setCountryCode(strtoupper($request->countryCode));
        $address->setIsDefault($request->isDefault);
    }

    private function unsetOtherDefaults(User $user, UserAddress $current): void
    {
        foreach ($user->getUserAddresses() as $other) {
            if ($other !== $current && $other->isDefault()) {
                $other->setIsDefault(false);
            }
        }
    }

    /** @return array<string, mixed> */
    private function serializeAddress(UserAddress $address): array
    {
        return [
            'id' => $address->getId()->toRfc4122(),
            'label' => $address->getLabel(),
            'address1' => $address->getAddress1(),
            'address2' => $address->getAddress2(),
            'city' => $address->getCity(),
            'postalCode' => $address->getPostalCode(),
            'countryCode' => $address->getCountryCode(),
            'isDefault' => $address->isDefault(),
        ];
    }

    // ---------------------------------------------------------------- Sécurité

    #[Route('/api/me/password', methods: ['PUT'])]
    public function changePassword(
        #[CurrentUser] User $user,
        #[MapRequestPayload] ChangePasswordRequest $request,
        UserPasswordHasherInterface $hasher,
        UserRepository $userRepository,
    ): JsonResponse {
        if (!$hasher->isPasswordValid($user, $request->currentPassword)) {
            return $this->json(['error' => 'Mot de passe actuel incorrect.'], 422);
        }

        // * Même méthode que reset-password (déjà testée) : hash + flush
        $userRepository->upgradePassword($user, $hasher->hashPassword($user, $request->newPassword));

        return $this->json(null, 204);
    }
}