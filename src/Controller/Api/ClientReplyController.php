<?php

namespace App\Controller\Api;

use App\Entity\Identity\User;
use App\Entity\Matching\ClientRequest;
use App\Entity\Matching\ProducerReply;
use App\Entity\Messaging\Conversation;
use App\Entity\Trust\Review;
use App\Enum\ReplyStatus;
use App\Enum\VerificationStatus;
use App\Repository\Matching\RequestMatchRepository;
use App\Service\Matching\ReplyAttachmentManager;
use App\Service\Notification\NotificationService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Réponses et devis reçus par le client (cahier fonctionnel, dashboard client : "Réponses / devis -- comparaison des propositions,
 * prix indicatif, disponibilité, distance, CTA chat") et sa décision : accepter ("le client souhaite poursuivre") ou refuser ("le client
 * ne retient pas la proposition"), les statuts de réponse du cahier que rien ne posait côté client. Le producteur voit ces statuts dans "Mes devis".
 *
 * Les réponses viennent de ProducerRequestController::replyToRequest() ; la demande elle-même (détail, modification, annulation...) reste
 * dans ClientRequestController.
 */
final class ClientReplyController extends AbstractController
{
    #[Route('/api/client/requests/{id}/replies', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function listReplies(string $id, #[CurrentUser] User $client, EntityManagerInterface $em, ReplyAttachmentManager $attachments): JsonResponse
    {
        $clientRequest = $em->find(ClientRequest::class, $id);
        if ($clientRequest === null || $clientRequest->getClient() !== $client) {
            return $this->json(['error' => 'Demande introuvable.'], 404);
        }

        /** @var list<ProducerReply> $replies */
        $replies = $em->getRepository(ProducerReply::class)->createQueryBuilder('pr')
            ->select('pr', 'p', 'pu', 'cur')
            ->join('pr.producer', 'p')
            ->leftJoin('pr.priceUnit', 'pu')
            ->leftJoin('pr.currency', 'cur')
            ->where('pr.request = :request')
            ->andWhere('pr.status != :draft')
            // * le refus poli d'un producteur ("Je ne peux pas répondre") n'est pas une proposition : rien à comparer
            ->andWhere('NOT (pr.status = :declined AND pr.priceAmount IS NULL AND pr.replyText IS NULL)')
            ->orderBy('pr.createdAt', 'DESC')
            ->setParameter('request', $clientRequest)
            ->setParameter('draft', ReplyStatus::Draft->value)
            ->setParameter('declined', ReplyStatus::Declined->value)
            ->getQuery()
            ->getResult();

        $producerIds = array_map(static fn (ProducerReply $r) => $r->getProducer()->getId()->toRfc4122(), $replies);
        $conversations = $this->conversationIdsByProducer($clientRequest, $em);
        $reviews = $this->reviewsByProducer($clientRequest, $client, $em);
        $distances = $this->distancesInKm($clientRequest, $producerIds, $em);

        $rows = array_map(function (ProducerReply $reply) use ($clientRequest, $conversations, $reviews, $distances, $attachments) {
            $producer = $reply->getProducer();
            $producerId = $producer->getId()->toRfc4122();
            $review = $reviews[$producerId] ?? null;

            return [
                'id' => $reply->getId()->toRfc4122(),
                // * "sent" = pas encore consultée : l'écran la signale comme nouvelle, puis la passe en "vue" (voir plus bas)
                'status' => $reply->getStatus()->value,
                'sentAt' => $reply->getCreatedAt()->format(DATE_ATOM),
                'producer' => [
                    'id' => $producerId,
                    'name' => $producer->getFarmName(),
                    'slug' => $producer->getSlug(),
                    'city' => $producer->getCity(),
                    'verified' => $producer->getVerificationStatus() === VerificationStatus::Verified,
                    'distanceKm' => $distances[$producerId] ?? null,
                ],
                'message' => $reply->getReplyText(),
                'priceAmount' => $reply->getPriceAmount() !== null ? (float) $reply->getPriceAmount() : null,
                'priceUnit' => $reply->getPriceUnit()?->getCode(),
                'currency' => $reply->getCurrency()?->getSymbol() ?? $reply->getCurrency()?->getCode(),
                'availableQuantity' => $reply->getAvailableQuantity() !== null ? (float) $reply->getAvailableQuantity() : null,
                'quantityUnit' => $clientRequest->getUnit()?->getCode(),
                'availabilityDate' => $reply->getAvailabilityDate()?->format('Y-m-d'),
                'validUntil' => $reply->getValidUntil()?->format(DATE_ATOM),
                'conditions' => $reply->getConditions(),
                'pickupConditions' => $reply->getPickupConditions(),
                'deliveryConditions' => $reply->getDeliveryConditions(),
                'conversationId' => $conversations[$producerId] ?? null,
                'review' => $review !== null ? ['rating' => $review->getRating(), 'status' => $review->getStatus()->value] : null,
                'attachments' => array_map($attachments->present(...), $reply->getAttachments()->toArray()),
            ];
        }, $replies);

        // * Cahier fonctionnel, statuts de réponse : "Vue -- le client a consulté la réponse". Ouvrir la liste la passe en "vue" ; la
        // * réponse envoyée plus haut garde "sent" pour cet appel-ci. Comme ConversationController::getConversation() pour les messages lus.
        foreach ($replies as $reply) {
            if ($reply->getStatus() === ReplyStatus::Sent) {
                $reply->setStatus(ReplyStatus::Seen);
            }
        }
        $em->flush();

        return $this->json([
            'request' => [
                'id' => $clientRequest->getId()->toRfc4122(),
                'status' => $clientRequest->getStatus()->value,
                'product' => $clientRequest->getProduct()?->getName() ?? $clientRequest->getCustomProduct(),
                'quantity' => $clientRequest->getQuantity() !== null ? (float) $clientRequest->getQuantity() : null,
                'unit' => $clientRequest->getUnit()?->getCode(),
                'city' => $clientRequest->getCity(),
                'desiredDate' => $clientRequest->getDesiredDate()?->format('Y-m-d'),
                'sentAt' => $clientRequest->getCreatedAt()->format(DATE_ATOM),
            ],
            'replies' => $rows,
        ]);
    }

    #[Route('/api/client/replies/{id}/accept', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function accept(string $id, #[CurrentUser] User $client, EntityManagerInterface $em, NotificationService $notifications): JsonResponse
    {
        return $this->decide($id, ReplyStatus::Accepted, $client, $em, $notifications);
    }

    #[Route('/api/client/replies/{id}/decline', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function decline(string $id, #[CurrentUser] User $client, EntityManagerInterface $em, NotificationService $notifications): JsonResponse
    {
        return $this->decide($id, ReplyStatus::Declined, $client, $em, $notifications);
    }

    private function decide(string $id, ReplyStatus $decision, User $client, EntityManagerInterface $em, NotificationService $notifications): JsonResponse
    {
        $reply = $em->find(ProducerReply::class, $id);
        // * brouillon et refus poli d'un producteur : le client ne les voit pas, donc pour lui ils n'existent pas
        if ($reply === null || $reply->getRequest()->getClient() !== $client || $reply->getStatus() === ReplyStatus::Draft
            || ($reply->getStatus() === ReplyStatus::Declined && $reply->getPriceAmount() === null && $reply->getReplyText() === null)) {
            return $this->json(['error' => 'Réponse introuvable.'], 404);
        }

        $clientRequest = $reply->getRequest();
        if (!\in_array($clientRequest->getStatus(), RequestMatchRepository::OPEN_REQUEST_STATUSES, true)) {
            return $this->json(['error' => "Cette demande n'est plus ouverte."], 409);
        }
        if (!\in_array($reply->getStatus(), [ReplyStatus::Sent, ReplyStatus::Seen], true)) {
            return $this->json(['error' => 'Vous avez déjà répondu à cette proposition.'], 409);
        }

        $reply->setStatus($decision);

        $owner = $reply->getProducer()->getOwner();
        if ($owner !== null) {
            $accepted = $decision === ReplyStatus::Accepted;
            $notifications->notify(
                $owner,
                'client_replied',
                'Réponse client',
                $accepted ? 'Un client souhaite poursuivre avec votre devis.' : "Un client n'a pas retenu votre devis.",
                ['requestId' => $clientRequest->getId()->toRfc4122(), 'replyId' => $reply->getId()->toRfc4122(), 'decision' => $decision->value],
            );
        }
        $em->flush();

        return $this->json(['id' => $reply->getId()->toRfc4122(), 'status' => $reply->getStatus()->value]);
    }

    /**
     * @return array<string, string> id producteur => id de la conversation de cette demande avec lui
     */
    private function conversationIdsByProducer(ClientRequest $clientRequest, EntityManagerInterface $em): array
    {
        $ids = [];
        foreach ($em->getRepository(Conversation::class)->findBy(['request' => $clientRequest]) as $conversation) {
            $ids[$conversation->getProducer()->getId()->toRfc4122()] = $conversation->getId()->toRfc4122();
        }

        return $ids;
    }

    /**
     * @return array<string, Review> id producteur => l'avis déjà laissé par ce client sur cette demande
     */
    private function reviewsByProducer(ClientRequest $clientRequest, User $client, EntityManagerInterface $em): array
    {
        $reviews = [];
        foreach ($em->getRepository(Review::class)->findBy(['client' => $client, 'request' => $clientRequest]) as $review) {
            $reviews[$review->getProducer()->getId()->toRfc4122()] = $review;
        }

        return $reviews;
    }

    /**
     * Distance à vol d'oiseau entre la demande et chaque producteur, au dixième de kilomètre ; absente si l'un des deux n'a pas de position.
     *
     * @param list<string> $producerIds
     *
     * @return array<string, float>
     */
    private function distancesInKm(ClientRequest $clientRequest, array $producerIds, EntityManagerInterface $em): array
    {
        if ($producerIds === []) {
            return [];
        }

        $rows = $em->getConnection()->fetchAllKeyValue(
            'SELECT pp.id, ROUND((ST_Distance(pp.location, cr.location) / 1000.0)::numeric, 1)
               FROM producer.producer_profiles pp
               JOIN matching.client_requests cr ON cr.id = :request
              WHERE pp.id IN (:ids) AND pp.location IS NOT NULL AND cr.location IS NOT NULL',
            ['request' => $clientRequest->getId()->toRfc4122(), 'ids' => $producerIds],
            ['request' => ParameterType::STRING, 'ids' => ArrayParameterType::STRING],
        );

        return array_map('floatval', $rows);
    }
}
