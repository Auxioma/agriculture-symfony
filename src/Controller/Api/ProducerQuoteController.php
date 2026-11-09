<?php

namespace App\Controller\Api;

use App\Entity\Identity\User;
use App\Entity\Matching\ProducerReply;
use App\Enum\NeedType;
use App\Enum\ReplyStatus;
use App\Service\Matching\AvailableRequestPresenter;
use App\Service\Matching\ReplyAttachmentManager;
use App\Service\Pagination\Pagination;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * "Mes devis" / "Mes réponses" du producteur (cahier fonctionnel, dashboard producteur : "Mes réponses -- Historique,
 * brouillons, devis envoyés, réponses vues, relances, archives"). Toutes ses réponses, avec ou sans prix, brouillons
 * compris, la plus récente d'abord. Seul le refus poli (refus sans prix ni message, voir declineRequest()) n'en est pas une.
 *
 * Lecture seule, côté producteur uniquement : le statut (brouillon, envoyée, vue, acceptée, refusée, expirée,
 * archivée) est posé par d'autres flux, en grande partie côté client (cahier fonctionnel, statuts d'une réponse) --
 * cette route ne le modifie jamais.
 */

final class ProducerQuoteController extends AbstractController
{
    /** Par pages (?page=, ?pageSize= : 100 par défaut et au plus ; le total de toutes les pages dans X-Total-Count). */
    #[Route('/api/producer/quotes', methods: ['GET'])]
    public function listQuotes(Request $request, #[CurrentUser] User $user, EntityManagerInterface $em, AvailableRequestPresenter $presenter): JsonResponse
    {
        $pagination = Pagination::fromRequest($request, defaultPageSize: Pagination::MAX_PAGE_SIZE);
        if ($pagination === null) {
            return $this->json(['error' => Pagination::invalidMessage()], 400);
        }

        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        [$replies, $total] = $pagination->slice(
            $em->getRepository(ProducerReply::class)->createQueryBuilder('pr')
                ->select('pr', 'r', 'c', 'p', 'u', 'pu', 'cur')
                ->join('pr.request', 'r')
                ->join('r.client', 'c')
                ->leftJoin('r.product', 'p')
                ->leftJoin('r.unit', 'u')
                ->leftJoin('pr.priceUnit', 'pu')
                ->leftJoin('pr.currency', 'cur')
                ->where('pr.producer = :producer')
                ->andWhere('NOT (pr.status = :declined AND pr.priceAmount IS NULL AND pr.replyText IS NULL)')
                ->orderBy('pr.createdAt', 'DESC')
                // * pr.id en dernier tri : deux devis de la même seconde ne doivent pas changer de page d'un appel à l'autre
                ->addOrderBy('pr.id', 'ASC')
                ->setParameter('producer', $producer)
                ->setParameter('declined', ReplyStatus::Declined->value)
        );

        return $pagination->withTotal($this->json(array_map(fn (ProducerReply $reply) => $this->summary($reply, $presenter), $replies)), $total);
    }

    /**
     * Détail d'un devis (page "Détail du devis") : le résumé de la liste plus la quantité disponible, la date
     * disponible, les conditions de retrait et de livraison, le message du producteur et les pièces jointes (avec une
     * URL signée à durée limitée). Un brouillon, un refus poli ou la réponse d'un autre producteur donnent 404.
     */
    #[Route('/api/producer/quotes/{id}', methods: ['GET'])]
    public function getQuote(string $id, #[CurrentUser] User $user, EntityManagerInterface $em, AvailableRequestPresenter $presenter, ReplyAttachmentManager $attachments): JsonResponse
    {
        $reply = $this->findOwnQuote($id, $user, $em);
        if ($reply instanceof JsonResponse) {
            return $reply;
        }

        return $this->json($this->summary($reply, $presenter) + [
            'clientType' => $reply->getRequest()->getNeedType() === NeedType::Professional ? 'professional' : 'individual',
            'availableQuantity' => $reply->getAvailableQuantity() !== null ? (float) $reply->getAvailableQuantity() : null,
            'availabilityDate' => $reply->getAvailabilityDate()?->format(DATE_ATOM),
            'pickupConditions' => $reply->getPickupConditions(),
            'deliveryConditions' => $reply->getDeliveryConditions(),
            'replyText' => $reply->getReplyText(),
            'attachments' => array_map($attachments->present(...), $reply->getAttachments()->toArray()),
        ]);
    }

    /**
     * Archiver un devis (cahier fonctionnel, statuts d'une réponse : "Archivée -- réponse conservée dans l'historique").
     * Le producteur range sa propre réponse ; le statut précédent (même "acceptée") n'est pas gardé. Archiver deux fois
     * ne change rien.
     */
    #[Route('/api/producer/quotes/{id}/archive', methods: ['POST'])]
    public function archiveQuote(string $id, #[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $reply = $this->findOwnQuote($id, $user, $em);
        if ($reply instanceof JsonResponse) {
            return $reply;
        }

        $reply->setStatus(ReplyStatus::Archived);
        $em->flush();

        return $this->json(['id' => $reply->getId()->toRfc4122(), 'status' => $reply->getStatus()->value]);
    }

    // * 404 pour un brouillon (il se rouvre depuis la page de réponse), un refus poli ou la réponse d'un autre
    // * producteur : on ne dit pas qu'ils existent
    private function findOwnQuote(string $id, User $user, EntityManagerInterface $em): ProducerReply|JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $reply = Uuid::isValid($id) ? $em->find(ProducerReply::class, $id) : null;
        if ($reply === null || $reply->getProducer() !== $producer || $reply->getStatus() === ReplyStatus::Draft || $this->isPoliteRefusal($reply)) {
            return $this->json(['error' => 'Devis introuvable.'], 404);
        }

        return $reply;
    }

    // * même définition que le filtre de listQuotes()
    private function isPoliteRefusal(ProducerReply $reply): bool
    {
        return $reply->getStatus() === ReplyStatus::Declined && $reply->getPriceAmount() === null && $reply->getReplyText() === null;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(ProducerReply $reply, AvailableRequestPresenter $presenter): array
    {
        $request = $reply->getRequest();

        return [
            'replyId' => $reply->getId()->toRfc4122(),
            'requestId' => $request->getId()->toRfc4122(),
            'clientName' => $presenter->clientName($request->getClient()),
            'product' => $request->getProduct()?->getName() ?? $request->getCustomProduct(),
            'quantity' => $request->getQuantity() !== null ? (float) $request->getQuantity() : null,
            'unit' => $request->getUnit()?->getCode(),
            'priceAmount' => $reply->getPriceAmount() !== null ? (float) $reply->getPriceAmount() : null,
            'priceUnit' => $reply->getPriceUnit()?->getCode(),
            'currency' => $reply->getCurrency()?->getSymbol() ?? $reply->getCurrency()?->getCode(),
            'status' => $reply->getStatus()->value,
            'sentAt' => $reply->getCreatedAt()->format(DATE_ATOM),
            'validUntil' => $reply->getValidUntil()?->format(DATE_ATOM),
        ];
    }
}
