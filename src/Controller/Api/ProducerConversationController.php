<?php

namespace App\Controller\Api;

use App\Entity\Identity\User;
use App\Entity\Messaging\Conversation;
use App\Entity\Messaging\Message;
use App\Enum\NeedType;
use App\Service\Matching\AvailableRequestPresenter;
use App\Service\Matching\ProducerRequestAccess;
use App\Service\Messaging\LastMessageFinder;
use App\Service\Messaging\UnreadMessageCounter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Liste des conversations du producteur (cahier fonctionnel, messagerie : "Conversation liée à une demande précise",
 * "Indicateurs lu / non lu"). Une ligne par demande, avec de quoi la reconnaître sans ouvrir le fil : le client, le
 * produit demandé, le dernier message, le nombre de messages non lus.
 *
 * Lecture seule et côté producteur uniquement : lire un fil, écrire, joindre un fichier et signaler passent par les
 * routes de ConversationController, que le client utilise aussi et que cette route ne remplace pas.
 */

final class ProducerConversationController extends AbstractController
{
    private const PREVIEW_LENGTH = 120;

    #[Route('/api/producer/conversations', methods: ['GET'])]
    public function listConversations(
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        UnreadMessageCounter $unreadCounter,
        LastMessageFinder $lastMessages,
        AvailableRequestPresenter $presenter,
        ProducerRequestAccess $access,
    ): JsonResponse {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        // * Une conversation sans message (créée par une réponse sans texte) se classe à sa création.
        $conversations = $em->getRepository(Conversation::class)->createQueryBuilder('c')
            ->select('c', 'r', 'cl', 'p', 'u')
            ->addSelect('COALESCE(c.lastMessageAt, c.createdAt) AS HIDDEN activityAt')
            ->join('c.request', 'r')
            ->leftJoin('c.client', 'cl')
            ->leftJoin('r.product', 'p')
            ->leftJoin('r.unit', 'u')
            ->where('c.producer = :producer')
            ->orderBy('activityAt', 'DESC')
            ->setParameter('producer', $producer)
            ->getQuery()
            ->getResult();

        $unreadCounts = $unreadCounter->countByConversation($conversations, $user);
        $last = $lastMessages->byConversation($conversations);
        // * Sans abonnement valide le nom du client reste caché, comme dans la liste des demandes.
        $locked = !$access->hasFullAccess($producer);

        return $this->json(array_map(function (Conversation $c) use ($user, $unreadCounts, $last, $presenter, $locked) {
            $id = $c->getId()->toRfc4122();
            $request = $c->getRequest();
            $client = $c->getClient();

            return [
                'id' => $id,
                'requestId' => $request->getId()->toRfc4122(),
                'clientName' => $client !== null ? $presenter->clientName($client, $locked) : 'Client',
                'clientType' => $request->getNeedType() === NeedType::Professional ? 'professional' : 'individual',
                'product' => $request->getProduct()?->getName() ?? $request->getCustomProduct(),
                'quantity' => $request->getQuantity() !== null ? (float) $request->getQuantity() : null,
                'unit' => $request->getUnit()?->getCode(),
                'status' => $c->getStatus()->value,
                'lastMessageAt' => $c->getLastMessageAt()?->format(DATE_ATOM),
                'unreadCount' => $unreadCounts[$id] ?? 0,
                'lastMessage' => isset($last[$id]) ? $this->preview($last[$id], $user) : null,
            ];
        }, $conversations));
    }

    /**
     * @return array<string, mixed>
     */
    private function preview(Message $message, User $user): array
    {
        $moderated = null !== $message->getModeratedAt();

        return [
            // * Un message modéré ne montre plus son texte, ici comme dans le fil.
            'preview' => $moderated || null === $message->getContent() ? null : mb_strimwidth($message->getContent(), 0, self::PREVIEW_LENGTH, '…'),
            'moderated' => $moderated,
            'fromMe' => $message->getSender()?->getId()->equals($user->getId()) ?? false,
            'isSystem' => $message->isSystem(),
            'hasAttachment' => !$message->getAttachments()->isEmpty(),
            'createdAt' => $message->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
