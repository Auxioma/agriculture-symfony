<?php

namespace App\Controller\Api;

use App\Dto\Request\ReplyToRequestRequest;
use App\Entity\Catalog\Currency;
use App\Entity\Catalog\Unit;
use App\Entity\Identity\User;
use App\Entity\Matching\ClientRequest;
use App\Entity\Matching\ProducerReply;
use App\Entity\Matching\RequestAttachment;
use App\Entity\Matching\RequestMatch;
use App\Entity\Messaging\Conversation;
use App\Enum\ReplyStatus;
use App\Service\Matching\AvailableRequestPresenter;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Traitement des demandes clients côté producteur (consultation et réponses).
 */
final class ProducerRequestController extends AbstractController
{
    /**
     * Liste des demandes matching accessibles au producteur.
     */
    #[Route('/api/producer/requests/available', methods: ['GET'])]
    public function listAvailableRequests(#[CurrentUser] User $user, EntityManagerInterface $em, AvailableRequestPresenter $presenter): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $matches = $em->getRepository(RequestMatch::class)->createAvailableQueryBuilder($producer)
            ->orderBy('m.score', 'DESC')
            ->addOrderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->json(array_map(
            static fn (RequestMatch $m) => [
                'matchId' => $m->getId()->toRfc4122(),
                'score' => $m->getScore(),
                'status' => $m->getStatus()->value,
                'needType' => $m->getRequest()->getNeedType()->value,
            ] + $presenter->present($m),
            $matches
        ));
    }

    /**
     * Toutes les demandes reçues par le producteur, la plus récente d'abord (cahier : "Demandes reçues", à côté de
     * "Demandes disponibles"). status : "new" = encore à traiter (même définition que les disponibles), "treated" =
     * réponse envoyée ou refus (un brouillon ne compte pas), "closed" = plus ouverte (expirée, annulée...).
     */
    #[Route('/api/producer/requests/received', methods: ['GET'])]
    public function listReceivedRequests(#[CurrentUser] User $user, EntityManagerInterface $em, AvailableRequestPresenter $presenter): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $repository = $em->getRepository(RequestMatch::class);
        $newIds = array_flip(array_map(
            static fn (RequestMatch $m) => $m->getId()->toRfc4122(),
            $repository->createAvailableQueryBuilder($producer)->getQuery()->getResult()
        ));
        $replies = [];
        foreach ($em->getRepository(ProducerReply::class)->findBy(['producer' => $producer], ['createdAt' => 'ASC']) as $reply) {
            if ($reply->getStatus() !== ReplyStatus::Draft) {
                $replies[$reply->getRequest()->getId()->toRfc4122()] = $reply;
            }
        }

        $matches = $repository->createQueryBuilder('m')
            ->select('m', 'r', 'c')
            ->join('m.request', 'r')
            ->join('r.client', 'c')
            ->where('m.producer = :producer')
            ->orderBy('m.createdAt', 'DESC')
            ->setParameter('producer', $producer)
            ->getQuery()
            ->getResult();

        return $this->json(array_map(function (RequestMatch $m) use ($newIds, $replies, $presenter) {
            $request = $m->getRequest();
            $reply = $replies[$request->getId()->toRfc4122()] ?? null;
            $quantity = $request->getQuantity();

            return [
                'requestId' => $request->getId()->toRfc4122(),
                'clientName' => $presenter->clientName($request->getClient()),
                'product' => $request->getProduct()?->getName() ?? $request->getCustomProduct(),
                'quantity' => $quantity !== null ? (float) $quantity : null,
                'unit' => $request->getUnit()?->getCode(),
                'urgent' => $request->getUrgencyLevel() > 0,
                'status' => $this->requestStatus($reply, isset($newIds[$m->getId()->toRfc4122()])),
                'receivedAt' => $m->getCreatedAt()->format(DATE_ATOM),
                'respondedAt' => $reply?->getCreatedAt()->format(DATE_ATOM),
                'declined' => $reply?->getStatus() === ReplyStatus::Declined,
            ];
        }, $matches));
    }

    /**
     * Détail d'une demande client (cahier : "Informations client, besoin, localisation, pièces jointes"), avec les
     * champs de la liste des demandes plus la date souhaitée, le département (France), retrait/livraison et les
     * pièces jointes. status/respondedAt/declined : comme pour la liste des demandes reçues.
     */
    #[Route('/api/producer/requests/{id}', methods: ['GET'])]
    public function getRequestDetailForProducer(string $id, #[CurrentUser] User $user, EntityManagerInterface $em, AvailableRequestPresenter $presenter): JsonResponse
    {
        $result = $this->findMatchedRequest($id, $user, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        [$clientRequest, $producer, $match] = $result;

        $isNew = $em->getRepository(RequestMatch::class)->createAvailableQueryBuilder($producer)
            ->andWhere('m = :match')
            ->setParameter('match', $match)
            ->getQuery()
            ->getOneOrNullResult() !== null;
        $reply = $em->getRepository(ProducerReply::class)->createQueryBuilder('pr')
            ->where('pr.request = :request')
            ->andWhere('pr.producer = :producer')
            ->andWhere('pr.status <> :draft')
            ->orderBy('pr.createdAt', 'DESC')
            ->setMaxResults(1)
            ->setParameter('request', $clientRequest)
            ->setParameter('producer', $producer)
            ->setParameter('draft', ReplyStatus::Draft->value)
            ->getQuery()
            ->getOneOrNullResult();
        $draft = $em->getRepository(ProducerReply::class)->findOneBy(
            ['request' => $clientRequest, 'producer' => $producer, 'status' => ReplyStatus::Draft],
            ['createdAt' => 'DESC']
        );
        $postalCode = (string) $clientRequest->getPostalCode();

        return $this->json($presenter->present($match) + [
            // * brouillon de réponse en cours (page "Répondre à la demande"), null s'il n'y en a pas
            'draft' => $draft === null ? null : [
                'replyText' => $draft->getReplyText(),
                'priceAmount' => $draft->getPriceAmount() !== null ? (float) $draft->getPriceAmount() : null,
                'priceUnitId' => $draft->getPriceUnit()?->getId()->toRfc4122(),
                'availableQuantity' => $draft->getAvailableQuantity() !== null ? (float) $draft->getAvailableQuantity() : null,
                'availabilityDate' => $draft->getAvailabilityDate()?->format(DATE_ATOM),
                'validUntil' => $draft->getValidUntil()?->format(DATE_ATOM),
                'pickupConditions' => $draft->getPickupConditions(),
                'deliveryConditions' => $draft->getDeliveryConditions(),
            ],
            'id' => $clientRequest->getId()->toRfc4122(),
            'needType' => $clientRequest->getNeedType()->value,
            'matchScore' => $match->getScore(),
            'desiredDate' => $clientRequest->getDesiredDate()?->format(DATE_ATOM),
            'department' => $clientRequest->getCountry()?->getCode() === 'FR' && strlen($postalCode) >= 2
                ? substr($postalCode, 0, preg_match('/^9[78]/', $postalCode) ? 3 : 2)
                : null,
            'pickupWanted' => $clientRequest->isPickupWanted(),
            'deliveryWanted' => $clientRequest->isDeliveryWanted(),
            'attachments' => array_map(
                static fn (RequestAttachment $a) => ['fileName' => $a->getFileName(), 'fileUrl' => $a->getFileUrl()],
                $clientRequest->getAttachments()->toArray()
            ),
            'status' => $this->requestStatus($reply, $isNew),
            'respondedAt' => $reply?->getCreatedAt()->format(DATE_ATOM),
            'declined' => $reply?->getStatus() === ReplyStatus::Declined,
        ]);
    }

    // * new = encore à traiter, treated = réponse envoyée ou refus, closed = plus ouverte (expirée, annulée...)
    private function requestStatus(?ProducerReply $reply, bool $isNew): string
    {
        return $reply !== null ? 'treated' : ($isNew ? 'new' : 'closed');
    }

    /**
     * Répondre à une demande (message et/ou devis chiffré), ou enregistrer un brouillon (draft : true).
     */
    #[Route('/api/producer/requests/{id}/reply', methods: ['POST'])]
    public function replyToRequest(
        string $id,
        #[MapRequestPayload] ReplyToRequestRequest $requestDto,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        NotificationService $notificationService,
    ): JsonResponse {
        $result = $this->findMatchedRequest($id, $user, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        [$clientRequest, $producer] = $result;

        // * Une demande déjà traitée (réponse envoyée ou refus) ne reçoit pas de seconde réponse ; un brouillon
        // * existant est repris et mis à jour plutôt que dupliqué.
        $draft = null;
        foreach ($em->getRepository(ProducerReply::class)->findBy(['request' => $clientRequest, 'producer' => $producer], ['createdAt' => 'DESC']) as $previous) {
            if ($previous->getStatus() !== ReplyStatus::Draft) {
                return $this->json(['error' => 'Cette demande est déjà traitée.'], 409);
            }
            $draft ??= $previous;
        }

        if (!$requestDto->draft) {
            if ($requestDto->replyText === null && $requestDto->priceAmount === null) {
                return $this->json(['error' => 'replyText ou priceAmount est requis.'], 422);
            }

            // Vérification des droits d'abonnement producteur (feature 'reply_to_requests')
            $hasFeature = $em->getConnection()->fetchOne(
                "SELECT billing.producer_has_feature(:pid, 'reply_to_requests')",
                ['pid' => $producer->getId()->toRfc4122()]
            );
            if (!$hasFeature) {
                return $this->json(['error' => "Votre abonnement ne vous permet pas de répondre aux demandes."], 403);
            }
        }

        if ($requestDto->availableQuantity !== null && (!is_numeric($requestDto->availableQuantity) || (float) $requestDto->availableQuantity < 0)) {
            return $this->json(['error' => 'availableQuantity doit être un nombre positif.'], 422);
        }
        $unit = $requestDto->priceUnitId !== null ? $em->find(Unit::class, $requestDto->priceUnitId) : null;
        if ($requestDto->priceUnitId !== null && $unit === null) {
            return $this->json(['error' => 'Unité inconnue.'], 422);
        }
        $currency = $requestDto->currencyCode !== null ? $em->find(Currency::class, strtoupper($requestDto->currencyCode)) : null;
        if ($requestDto->currencyCode !== null && $currency === null) {
            return $this->json(['error' => 'Devise inconnue.'], 422);
        }

        // * Envoyer remplace le brouillon par une nouvelle réponse : "Répondu le..." doit afficher la date d'envoi, pas
        // * celle du brouillon. Enregistrer un brouillon met à jour le précédent.
        $reply = $requestDto->draft ? ($draft ?? new ProducerReply()) : new ProducerReply();
        if (!$requestDto->draft && $draft !== null) {
            $em->remove($draft);
        }
        $reply->setRequest($clientRequest);
        $reply->setProducer($producer);
        $reply->setReplyText($requestDto->replyText);
        $reply->setPriceAmount($requestDto->priceAmount);
        $reply->setPriceUnit($unit);
        $reply->setCurrency($currency);
        $reply->setAvailableQuantity($requestDto->availableQuantity);
        $reply->setAvailabilityDate($requestDto->availabilityDate);
        $reply->setValidUntil($requestDto->validUntil);
        $reply->setConditions($requestDto->conditions);
        $reply->setPickupConditions($requestDto->pickupConditions);
        $reply->setDeliveryConditions($requestDto->deliveryConditions);
        $reply->setStatus($requestDto->draft ? ReplyStatus::Draft : ReplyStatus::Sent);

        if (!$requestDto->draft) {
            // Création implicite de la conversation au premier message/devis
            $conversation = $em->getRepository(Conversation::class)->findOneBy(['request' => $clientRequest, 'producer' => $producer]);
            if ($conversation === null) {
                $conversation = new Conversation();
                $conversation->setRequest($clientRequest);
                $conversation->setProducer($producer);
                $conversation->setClient($clientRequest->getClient());
                $em->persist($conversation);
            }

            $notificationService->notify(
                $clientRequest->getClient(),
                $requestDto->priceAmount !== null ? 'quote_received' : 'producer_replied',
                $requestDto->priceAmount !== null ? 'Devis reçu' : 'Un producteur a répondu',
                'Vous avez une nouvelle réponse à votre demande.'
            );
        }

        $em->persist($reply);
        $em->flush();

        return $this->json(['id' => $reply->getId()->toRfc4122(), 'status' => $reply->getStatus()->value], 201);
    }

    /**
     * Refuser une demande transmise.
     */
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

    /**
     * Vérifie le profil producteur et l'accès au match de la demande.
     */
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
}