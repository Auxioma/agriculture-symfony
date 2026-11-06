<?php

namespace App\Service\Messaging;

use App\Entity\Identity\User;
use App\Entity\Messaging\Conversation;
use App\Entity\Messaging\Message;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Dernier message de chaque conversation d'une liste (aperçu sous le nom de l'interlocuteur), en une seule
 * requête plutôt qu'une par conversation, pièces jointes comprises.
 */
final class LastMessageFinder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    private const PREVIEW_LENGTH = 120;

    /**
     * La ligne d'aperçu d'une conversation dans une liste (celle du producteur comme celle du client) : texte coupé, qui a écrit, pièce jointe.
     *
     * @return array{preview: ?string, moderated: bool, fromMe: bool, isSystem: bool, hasAttachment: bool, createdAt: string}
     */
    public function preview(Message $message, User $user): array
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

    /**
     * Deux messages envoyés dans la même seconde ont le même createdAt (la colonne n'a pas de fraction de seconde) :
     * on garde alors le premier rencontré.
     *
     * @param Conversation[] $conversations
     *
     * @return array<string, Message> id de conversation (RFC4122) => son dernier message ; absente si elle n'en a aucun
     */
    public function byConversation(array $conversations): array
    {
        if ($conversations === []) {
            return [];
        }

        $messages = $this->em->createQueryBuilder()
            ->select('m', 'a')
            ->from(Message::class, 'm')
            ->leftJoin('m.attachments', 'a')
            ->where('m.conversation IN (:conversations)')
            ->andWhere('m.createdAt = (SELECT MAX(m2.createdAt) FROM '.Message::class.' m2 WHERE m2.conversation = m.conversation)')
            ->setParameter('conversations', $conversations)
            ->getQuery()
            ->getResult();

        $last = [];
        foreach ($messages as $message) {
            $last[$message->getConversation()->getId()->toRfc4122()] ??= $message;
        }

        return $last;
    }
}
