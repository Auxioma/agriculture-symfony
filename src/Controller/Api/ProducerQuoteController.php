<?php

namespace App\Controller\Api;

use App\Entity\Identity\User;
use App\Entity\Matching\ProducerReply;
use App\Enum\ReplyStatus;
use App\Service\Matching\AvailableRequestPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * "Mes devis" du producteur (cahier fonctionnel, dashboard producteur : "Mes réponses / devis -- Historique, devis
 * envoyés, réponses vues..."). Un devis = une réponse *avec un prix* : les brouillons (pas encore envoyés) et les
 * refus polis ou les réponses sans prix n'en sont pas. La plus récente d'abord.
 *
 * Lecture seule, côté producteur uniquement : le statut (envoyée, vue, acceptée, refusée, expirée, archivée) est
 * posé par d'autres flux, en grande partie côté client (cahier 8.2) -- cette route ne le modifie jamais.
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

        return $this->json(array_map(
            static fn (ProducerReply $reply) => [
                'replyId' => $reply->getId()->toRfc4122(),
                'requestId' => $reply->getRequest()->getId()->toRfc4122(),
                'clientName' => $presenter->clientName($reply->getRequest()->getClient()),
                'product' => $reply->getRequest()->getProduct()?->getName() ?? $reply->getRequest()->getCustomProduct(),
                'quantity' => $reply->getRequest()->getQuantity() !== null ? (float) $reply->getRequest()->getQuantity() : null,
                'unit' => $reply->getRequest()->getUnit()?->getCode(),
                'priceAmount' => (float) $reply->getPriceAmount(),
                'priceUnit' => $reply->getPriceUnit()?->getCode(),
                'currency' => $reply->getCurrency()?->getSymbol() ?? $reply->getCurrency()?->getCode(),
                'status' => $reply->getStatus()->value,
                'sentAt' => $reply->getCreatedAt()->format(DATE_ATOM),
                'validUntil' => $reply->getValidUntil()?->format(DATE_ATOM),
            ],
            $replies
        ));
    }
}
