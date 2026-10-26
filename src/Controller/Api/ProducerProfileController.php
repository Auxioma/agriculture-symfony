<?php

namespace App\Controller\Api;

use App\Dto\Producer\CreateProducerProfileRequest;
use App\Dto\Producer\UpdateProducerProfileRequest;
use App\Entity\Catalog\Country;
use App\Entity\Identity\User;
use App\Entity\Producer\ProducerProfile;
use App\Entity\Producer\ProducerSetting;
use App\Enum\VerificationStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Gestion du profil producteur pour l'utilisateur connecté. La création normale d'un profil se fait
 * en une seule opération avec le compte, dans AuthController::registerProducer(). createMyProfile()
 * ci-dessous couvre l'autre cas réel du projet : UserCrudController expose $user->roles comme un champ
 * générique modifiable par un admin -- un client peut donc se retrouver avec ROLE_PRODUCER sans jamais
 * être passé par l'inscription producteur, et sans ProducerProfile associé.
 */
final class ProducerProfileController extends AbstractController
{
    /**
     * Récupère les données du profil producteur de l'utilisateur.
     */
    #[Route('/api/producer/profile', methods: ['GET'])]
    public function getMyProfile(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        // * retrait et livraison : null tant qu'il n'y a pas de ligne producer_settings (rien de configuré), pas false
        $settings = $producer->getSettings();

        // * couverture : la première photo publique (position, sans position en dernier), comme la fiche publique et les cartes.
        // * fetchOne() renvoie false sans photo : "?: null" pour le JSON
        $photoUrl = $em->getConnection()->fetchOne(
            'SELECT file_url FROM producer.producer_media
             WHERE producer_id = :producerId AND is_public = true
             ORDER BY position ASC NULLS LAST
             LIMIT 1',
            ['producerId' => $producer->getId()->toRfc4122()]
        ) ?: null;

        // * date de vérification : la dernière validation faite par l'équipe, lue dans l'audit (MLD : « validation producteur »
        // * est une action tracée, le profil n'a pas de colonne pour ça). null tant que le profil n'est pas vérifié, ou si aucune
        // * validation n'a été tracée (profil créé déjà vérifié).
        $validatedAt = VerificationStatus::Verified === $producer->getVerificationStatus()
            ? $em->getConnection()->fetchOne(
                "SELECT max(created_at) FROM audit.audit_logs WHERE action = 'producer_validated' AND schema_name = 'producer' AND table_name = 'producer_profiles' AND record_id = :producerId",
                ['producerId' => $producer->getId()->toRfc4122()]
            )
            : null;

        return $this->json([
            'id' => $producer->getId()->toRfc4122(),
            'farmName' => $producer->getFarmName(),
            'slug' => $producer->getSlug(),
            'description' => $producer->getDescription(),
            'story' => $producer->getStory(),
            'city' => $producer->getCity(),
            'postalCode' => $producer->getPostalCode(),
            'addressVisibility' => $producer->getAddressVisibility(),
            'countryCode' => $producer->getCountry()?->getCode(),
            'verificationStatus' => $producer->getVerificationStatus()->value,
            'isActive' => $producer->isActive(),
            'pickupAvailable' => $settings?->isPickupEnabled(),
            'deliveryAvailable' => $settings?->isDeliveryEnabled(),
            'photoUrl' => $photoUrl,
            'verifiedAt' => $validatedAt ? (new \DateTimeImmutable($validatedAt))->format(DATE_ATOM) : null,
        ]);
    }

    /**
     * Mettre à jour les informations du profil producteur.
     */
    #[Route('/api/producer/profile', methods: ['PUT'])]
    public function updateMyProfile(
        #[MapRequestPayload] UpdateProducerProfileRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
    ): JsonResponse {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $producer->setFarmName($request->farmName);
        $producer->setDescription($request->description);
        $producer->setStory($request->story);
        $producer->setCity($request->city);
        $producer->setPostalCode($request->postalCode);
        $producer->setAddressVisibility($request->addressVisibility);

        // * sans ligne de réglages, elle naît au premier retrait ou à la première livraison enregistrés
        if ($request->pickupAvailable !== null || $request->deliveryAvailable !== null) {
            $settings = $producer->getSettings();
            if ($settings === null) {
                $settings = new ProducerSetting();
                $producer->setSettings($settings);
                $em->persist($settings);
            }
            if ($request->pickupAvailable !== null) {
                $settings->setPickupEnabled($request->pickupAvailable);
            }
            if ($request->deliveryAvailable !== null) {
                $settings->setDeliveryEnabled($request->deliveryAvailable);
            }
        }

        // Mise à jour du point spatial (PostGIS / EWKT format SRID 4326)
        if ($request->latitude !== null && $request->longitude !== null) {
            $producer->setLocation(sprintf('SRID=4326;POINT(%F %F)', $request->longitude, $request->latitude));
        }

        $em->flush();

        return $this->json(null, 200);
    }

    /**
     * Crée le profil producteur de l'utilisateur connecté -- réservé au cas d'un compte ROLE_PRODUCER
     * promu depuis le back-office (voir le docblock de la classe) : à l'inscription normale
     * (AuthController::registerProducer()), le profil existe déjà et cette route renvoie 409.
     */
    #[Route('/api/producer/profile', methods: ['POST'])]
    public function createMyProfile(
        #[MapRequestPayload] CreateProducerProfileRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (!in_array(User::ROLE_PRODUCER, $user->getRoles(), true)) {
            return $this->json(['error' => "Ce compte n'a pas le rôle producteur."], 403);
        }
        if ($user->getProducerProfile() !== null) {
            return $this->json(['error' => 'Ce compte a déjà un profil producteur.'], 409);
        }

        $country = $em->find(Country::class, strtoupper($request->countryCode));
        if ($country === null) {
            return $this->json(['error' => 'Pays inconnu.'], 422);
        }

        $producer = new ProducerProfile();
        $producer->setFarmName($request->farmName);
        $producer->setSlug($this->generateUniqueSlug($request->farmName));
        $producer->setCountry($country);
        $producer->setDescription($request->description);
        $producer->setStory($request->story);
        $producer->setCity($request->city);
        $producer->setPostalCode($request->postalCode);
        $producer->setAddressVisibility($request->addressVisibility);
        if ($request->latitude !== null && $request->longitude !== null) {
            $producer->setLocation(sprintf('SRID=4326;POINT(%F %F)', $request->longitude, $request->latitude));
        }

        // * Sync bidirectionnelle déjà écrite dans User::setProducerProfile() (met aussi producer->owner) --
        // * même séquence que AuthController::registerProducer().
        $user->setProducerProfile($producer);

        $em->persist($producer);
        $em->flush();

        return $this->json(['id' => $producer->getId()->toRfc4122()], 201);
    }

    // ? Même algorithme que AuthController::generateUniqueSlug() (dupliqué plutôt que partagé : un seul
    // ? appelant de plus ne justifie pas d'en faire un service pour ces 3 lignes).
    private function generateUniqueSlug(string $farmName): string
    {
        $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $farmName), '-'));

        return $base.'-'.bin2hex(random_bytes(3));
    }
}