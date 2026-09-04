<?php

namespace App\Controller\Api;

use App\Entity\Identity\User;
use App\Entity\Matching\ClientRequest;
use App\Entity\Matching\RequestMatch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ProducerRequestController extends AbstractController
{
    #[Route('/api/producer/requests/available', methods: ['GET'])]
    public function listAvailableRequests(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $matches = $em->getRepository(RequestMatch::class)->findBy(
            ['producer' => $producer],
            ['score' => 'DESC']
        );

        return $this->json(array_map(
            static fn (RequestMatch $m) => [
                'matchId' => $m->getId()->toRfc4122(),
                'requestId' => $m->getRequest()->getId()->toRfc4122(),
                'score' => $m->getScore(),
                'distanceKm' => $m->getDistanceKm(),
                'status' => $m->getStatus()->value,
                'needType' => $m->getRequest()->getNeedType()->value,
                'customProduct' => $m->getRequest()->getCustomProduct(),
            ],
            $matches
        ));
    }

    #[Route('/api/producer/requests/{id}', methods: ['GET'])]
    public function getRequestDetailForProducer(string $id, #[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $clientRequest = $em->find(ClientRequest::class, $id);
        if ($clientRequest === null) {
            return $this->json(['error' => 'Demande introuvable.'], 404);
        }

        $match = $em->getRepository(RequestMatch::class)->findOneBy(['request' => $clientRequest, 'producer' => $producer]);
        if ($match === null) {
            return $this->json(['error' => 'Cette demande ne vous est pas accessible.'], 403);
        }

        return $this->json([
            'id' => $clientRequest->getId()->toRfc4122(),
            'needType' => $clientRequest->getNeedType()->value,
            'customProduct' => $clientRequest->getCustomProduct(),
            'message' => $clientRequest->getMessage(),
            'city' => $clientRequest->getCity(),
            'quantity' => $clientRequest->getQuantity(),
            'matchScore' => $match->getScore(),
        ]);
    }

}