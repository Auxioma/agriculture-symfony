<?php

namespace App\Controller\Api;

use App\Entity\Identity\User;
use App\Entity\Messaging\Conversation;
use App\Service\Messaging\LastMessageFinder;
use App\Service\Messaging\UnreadMessageCounter;
use App\Service\Pagination\Pagination;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Liste des conversations du client (cahier fonctionnel, dashboard client : "Messages -- inbox par demande, recherche, non lus").
 * Une ligne par demande et par producteur, avec de quoi la reconnaître sans ouvrir le fil : le producteur, le produit demandé, le
 * dernier message, le nombre de messages non lus. C'est le pendant de ProducerConversationController.
 *
 * Lecture seule : lire un fil, écrire, joindre un fichier et signaler passent par les routes de ConversationController, communes aux deux
 * rôles. GET /api/conversations reste tel quel (il sert aussi au producteur) ; cette route-ci donne en plus de quoi afficher la liste.
 */
final class ClientConversationController extends AbstractController
{
    #[Route('/api/client/conversations', methods: ['GET'])]
    public function listConversations(
        Request $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        UnreadMessageCounter $unreadCounter,
        LastMessageFinder $lastMessages,
    ): JsonResponse {
        $pagination = Pagination::fromRequest($request);
        if ($pagination === null) {
            return $this->json(['error' => Pagination::invalidMessage()], 400);
        }

        // * Une conversation sans message (créée par une réponse sans texte) se classe à sa création ; c.id en dernier tri :
        // * deux conversations de la même seconde ne doivent pas changer de page d'un appel à l'autre.
        [$conversations, $total] = $pagination->slice(
            $em->getRepository(Conversation::class)->createQueryBuilder('c')
                ->select('c', 'r', 'pr', 'p', 'u')
                ->addSelect('COALESCE(c.lastMessageAt, c.createdAt) AS HIDDEN activityAt')
                ->join('c.request', 'r')
                ->join('c.producer', 'pr')
                ->leftJoin('r.product', 'p')
                ->leftJoin('r.unit', 'u')
                ->where('c.client = :client')
                ->orderBy('activityAt', 'DESC')
                ->addOrderBy('c.id', 'ASC')
                ->setParameter('client', $user)
        );

        $unreadCounts = $unreadCounter->countByConversation($conversations, $user);
        $last = $lastMessages->byConversation($conversations);

        return $pagination->withTotal($this->json(array_map(function (Conversation $c) use ($user, $unreadCounts, $last, $lastMessages) {
            $id = $c->getId()->toRfc4122();
            $request = $c->getRequest();
            $producer = $c->getProducer();

            return [
                'id' => $id,
                'requestId' => $request->getId()->toRfc4122(),
                'producerId' => $producer->getId()->toRfc4122(),
                'producerName' => $producer->getFarmName(),
                'producerSlug' => $producer->getSlug(),
                'product' => $request->getProduct()?->getName() ?? $request->getCustomProduct(),
                'quantity' => $request->getQuantity() !== null ? (float) $request->getQuantity() : null,
                'unit' => $request->getUnit()?->getCode(),
                'status' => $c->getStatus()->value,
                'lastMessageAt' => $c->getLastMessageAt()?->format(DATE_ATOM),
                'unreadCount' => $unreadCounts[$id] ?? 0,
                'lastMessage' => isset($last[$id]) ? $lastMessages->preview($last[$id], $user) : null,
            ];
        }, $conversations)), $total);
    }
}
