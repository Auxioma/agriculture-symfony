<?php

namespace App\Controller\Api;

use App\Dto\Producer\UpdateProducerProfileRequest;
use App\Entity\Identity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ProducerProfileController extends AbstractController
{
    #[Route('/api/producer/profile', methods: ['GET'])]
    public function getMyProfile(#[CurrentUser] User $user): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

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
        ]);
    }

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

        if ($request->latitude !== null && $request->longitude !== null) {
            $producer->setLocation(sprintf('SRID=4326;POINT(%F %F)', $request->longitude, $request->latitude));
        }

        $em->flush();

        return $this->json(null, 200);
    }
}