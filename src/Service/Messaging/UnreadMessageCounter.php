<?php

namespace App\Service\Messaging;

use App\Entity\Identity\User;
use App\Entity\Messaging\Conversation;
use App\Entity\Messaging\Message;
use App\Entity\Messaging\MessageRead;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Nombre de messages non lus par utilisateur. Une seule règle pour la liste des conversations
 * (ConversationController) et le dashboard producteur (ProducerDashboardController).
 */
final class UnreadMessageCounter
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Nombre de messages non envoyés par $user et jamais marqués lus par lui, groupés par conversation --
     * une seule requête pour toutes les conversations de la liste plutôt qu'une par conversation.
     *
     * @param Conversation[] $conversations
     *
     * @return array<string, int> id de conversation (RFC4122) => nombre de messages non lus
     */
    public function countByConversation(array $conversations, User $user): array
    {
        if ($conversations === []) {
            return [];
        }

        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(m.conversation) AS conversationId', 'COUNT(m.id) AS unreadCount')
            ->from(Message::class, 'm')
            ->leftJoin(MessageRead::class, 'mr', 'WITH', 'mr.message = m AND mr.idUser = :user')
            ->where('m.conversation IN (:conversations)')
            // * Un message système (sender null) reste "non lu" tant que personne ne l'a consulté, comme
            // * un message humain -- d'où le OR IS NULL plutôt qu'un simple != qui l'exclurait (NULL != x
            // * n'est jamais vrai en SQL).
            ->andWhere('m.sender IS NULL OR m.sender != :user')
            ->andWhere('mr.message IS NULL')
            ->groupBy('m.conversation')
            ->setParameter('user', $user)
            ->setParameter('conversations', $conversations)
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['conversationId']] = (int) $row['unreadCount'];
        }

        return $counts;
    }
}
