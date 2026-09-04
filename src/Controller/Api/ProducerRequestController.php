<?php

namespace App\Controller\Api;

use App\Dto\Request\ReplyToRequestRequest;
use App\Entity\Catalog\Currency;
use App\Entity\Catalog\Unit;
use App\Entity\Identity\User;
use App\Entity\Matching\ClientRequest;
use App\Entity\Matching\ProducerReply;
use App\Entity\Matching\RequestMatch;
use App\Enum\ReplyStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
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
        $result = $this->findMatchedRequest($id, $user, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        [$clientRequest, , $match] = $result;

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

    private function findMatchedRequest(string $id, User $user, EntityManagerInterface $em): array|JsonResponse
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

        return [$clientRequest, $producer, $match];
    }

    #[Route('/api/producer/requests/{id}/reply', methods: ['POST'])]
    public function replyToRequest(
        string $id,
        #[MapRequestPayload] ReplyToRequestRequest $requestDto,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
    ): JsonResponse {
        $result = $this->findMatchedRequest($id, $user, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        [$clientRequest, $producer] = $result;

        if ($requestDto->replyText === null && $requestDto->priceAmount === null) {
            return $this->json(['error' => 'replyText ou priceAmount est requis.'], 422);
        }

        // ! Règle du CDC "un producteur ne peut répondre que s'il dispose des droits nécessaires"
        // ! exactement ce que teste déjà ProducerHasFeatureTest, jamais branché à une vraie route jusqu'ici.
        $hasFeature = $em->getConnection()->fetchOne(
            "SELECT billing.producer_has_feature(:pid, 'reply_to_requests')",
            ['pid' => $producer->getId()->toRfc4122()]
        );
        if (!$hasFeature) {
            return $this->json(['error' => "Votre abonnement ne vous permet pas de répondre aux demandes."], 403);
        }

        $reply = new ProducerReply();
        $reply->setRequest($clientRequest);
        $reply->setProducer($producer);
        $reply->setReplyText($requestDto->replyText);
        $reply->setPriceAmount($requestDto->priceAmount);
        $reply->setAvailabilityDate($requestDto->availabilityDate);
        $reply->setValidUntil($requestDto->validUntil);
        $reply->setConditions($requestDto->conditions);
        $reply->setStatus(ReplyStatus::Sent);

        if ($requestDto->priceUnitId !== null) {
            $unit = $em->find(Unit::class, $requestDto->priceUnitId);
            if ($unit === null) {
                return $this->json(['error' => 'Unité inconnue.'], 422);
            }
            $reply->setPriceUnit($unit);
        }

        if ($requestDto->currencyCode !== null) {
            $currency = $em->find(Currency::class, strtoupper($requestDto->currencyCode));
            if ($currency === null) {
                return $this->json(['error' => 'Devise inconnue.'], 422);
            }
            $reply->setCurrency($currency);
        }

        $em->persist($reply);
        $em->flush();

        return $this->json(['id' => $reply->getId()->toRfc4122()], 201);
    }

    #[Route('/api/producer/requests/{id}/decline', methods: ['POST'])]
    public function declineRequest(string $id, #[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $result = $this->findMatchedRequest($id, $user, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        [$clientRequest, $producer] = $result;

        $reply = new ProducerReply();
        $reply->setRequest($clientRequest);
        $reply->setProducer($producer);
        $reply->setStatus(ReplyStatus::Declined);

        $em->persist($reply);
        $em->flush();

        return $this->json(['id' => $reply->getId()->toRfc4122()], 201);
    }
    
}