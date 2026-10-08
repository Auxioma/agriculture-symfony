<?php

namespace App\Controller\Api;

use App\Entity\Identity\User;
use App\Entity\Matching\ProducerReply;
use App\Entity\Matching\ReplyAttachment;
use App\Enum\NeedType;
use App\Enum\ReplyStatus;
use App\Service\Matching\AvailableRequestPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * "Mes devis" du producteur (cahier fonctionnel, dashboard producteur : "Mes réponses / devis -- Historique, devis
 * envoyés, réponses vues..."). Un devis = une réponse *avec un prix* : les brouillons (pas encore envoyés) et les
 * refus polis ou les réponses sans prix n'en sont pas. La plus récente d'abord.
 *
 * Lecture seule, côté producteur uniquement : le statut (envoyée, vue, acceptée, refusée, expirée, archivée) est
 * posé par d'autres flux, en grande partie côté client (cahier fonctionnel, statuts d'une réponse) -- cette route ne le modifie jamais.
 */

final class ProducerQuoteController extends AbstractController
{
    #[Route('/api/producer/quotes', methods: ['GET'])]
    public function listQuotes(#[CurrentUser] User $user, EntityManagerInterface $em, AvailableRequestPresenter $presenter): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $replies = $em->getRepository(ProducerReply::class)->createQueryBuilder('pr')
            ->select('pr', 'r', 'c', 'p', 'u', 'pu', 'cur')
            ->join('pr.request', 'r')
            ->join('r.client', 'c')
            ->leftJoin('r.product', 'p')
            ->leftJoin('r.unit', 'u')
            ->leftJoin('pr.priceUnit', 'pu')
            ->leftJoin('pr.currency', 'cur')
            ->where('pr.producer = :producer')
            ->andWhere('pr.priceAmount IS NOT NULL')
            ->andWhere('pr.status <> :draft')
            ->orderBy('pr.createdAt', 'DESC')
            ->setParameter('producer', $producer)
            ->setParameter('draft', ReplyStatus::Draft->value)
            ->getQuery()
            ->getResult();

        return $this->json(array_map(fn (ProducerReply $reply) => $this->summary($reply, $presenter), $replies));
    }

    /**
     * Détail d'un devis (page "Détail du devis") : le résumé de la liste plus la quantité disponible, la date
     * disponible, les conditions de retrait et de livraison, le message du producteur et les pièces jointes. Un
     * brouillon, une réponse sans prix ou le devis d'un autre producteur donnent 404 (on ne dit pas qu'ils existent).
     */
    #[Route('/api/producer/quotes/{id}', methods: ['GET'])]
    public function getQuote(string $id, #[CurrentUser] User $user, EntityManagerInterface $em, AvailableRequestPresenter $presenter): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $reply = Uuid::isValid($id) ? $em->find(ProducerReply::class, $id) : null;
        if ($reply === null || $reply->getProducer() !== $producer || $reply->getPriceAmount() === null || $reply->getStatus() === ReplyStatus::Draft) {
            return $this->json(['error' => 'Devis introuvable.'], 404);
        }

        return $this->json($this->summary($reply, $presenter) + [
            'clientType' => $reply->getRequest()->getNeedType() === NeedType::Professional ? 'professional' : 'individual',
            'availableQuantity' => $reply->getAvailableQuantity() !== null ? (float) $reply->getAvailableQuantity() : null,
            'availabilityDate' => $reply->getAvailabilityDate()?->format(DATE_ATOM),
            'pickupConditions' => $reply->getPickupConditions(),
            'deliveryConditions' => $reply->getDeliveryConditions(),
            'replyText' => $reply->getReplyText(),
            'attachments' => array_map(
                static fn (ReplyAttachment $a) => ['fileName' => $a->getFileName(), 'fileUrl' => $a->getFileUrl()],
                $reply->getAttachments()->toArray()
            ),
        ]);
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
            'priceAmount' => (float) $reply->getPriceAmount(),
            'priceUnit' => $reply->getPriceUnit()?->getCode(),
            'currency' => $reply->getCurrency()?->getSymbol() ?? $reply->getCurrency()?->getCode(),
            'status' => $reply->getStatus()->value,
            'sentAt' => $reply->getCreatedAt()->format(DATE_ATOM),
            'validUntil' => $reply->getValidUntil()?->format(DATE_ATOM),
        ];
    }
}
