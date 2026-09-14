<?php

namespace App\Controller\Api;

use App\Dto\Messaging\ReportConversationRequest;
use App\Dto\Messaging\SendMessageRequest;
use App\Entity\Identity\User;
use App\Entity\Messaging\BlockedUser;
use App\Entity\Messaging\Conversation;
use App\Entity\Messaging\Message;
use App\Entity\Messaging\MessageAttachment;
use App\Entity\Messaging\MessageRead;
use App\Entity\Trust\Report;
use App\Enum\ConversationStatus;
use App\Enum\RequestStatus;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Lecture, messages et pièces jointes d'une conversation (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf).
 * Pas de route de création dédiée : une conversation s'ouvre implicitement dès la
 * première réponse d'un producteur (voir ProducerRequestController::replyToRequest()).
 */

final class ConversationController extends AbstractController
{
    private const ALLOWED_ATTACHMENT_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    private const MAX_ATTACHMENT_SIZE_BYTES = 10 * 1024 * 1024;

    #[Route('/api/conversations', methods: ['GET'])]
    public function listConversations(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $producer = $user->getProducerProfile();
        $conversations = $producer !== null
            ? $em->getRepository(Conversation::class)->findBy(['producer' => $producer], ['lastMessageAt' => 'DESC'])
            : $em->getRepository(Conversation::class)->findBy(['client' => $user], ['lastMessageAt' => 'DESC']);

        $unreadCounts = $this->countUnreadMessages($conversations, $user, $em);

        return $this->json(array_map(
            static fn (Conversation $c) => [
                'id' => $c->getId()->toRfc4122(),
                'requestId' => $c->getRequest()->getId()->toRfc4122(),
                'status' => $c->getStatus()->value,
                'lastMessageAt' => $c->getLastMessageAt()?->format(DATE_ATOM),
                'unreadCount' => $unreadCounts[$c->getId()->toRfc4122()] ?? 0,
            ],
            $conversations
        ));
    }

    /**
     * Nombre de messages non envoyés par $user et jamais marqués lus par lui, groupés par conversation --
     * une seule requête pour toutes les conversations de la liste plutôt qu'une par conversation.
     *
     * @param Conversation[] $conversations
     *
     * @return array<string, int> id de conversation (RFC4122) => nombre de messages non lus
     */
    private function countUnreadMessages(array $conversations, User $user, EntityManagerInterface $em): array
    {
        if ($conversations === []) {
            return [];
        }

        $rows = $em->createQueryBuilder()
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

    #[Route('/api/conversations/{id}', methods: ['GET'])]
    public function getConversation(
        string $id,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        #[Autowire(service: 'message_attachments.storage')] FilesystemOperator $attachmentStorage,
    ): JsonResponse {
        $result = $this->findAccessibleConversation($id, $user, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $conversation = $result;

        // * Triée explicitement par createdAt : Conversation::$messages n'a pas d'#[ORM\OrderBy], on ne
        // * peut pas se fier à l'ordre de la collection lazy-loadée.
        $messages = $em->getRepository(Message::class)->findBy(['conversation' => $conversation], ['createdAt' => 'ASC']);

        // * readMap précalculée avant le marquage ci-dessous : consulter une conversation marque les
        // * messages de l'autre partie comme lus par $user, mais readMap sert à afficher si CHAQUE
        // * message a été lu par son destinataire (l'autre participant, pas $user) -- deux choses
        // * différentes qui ne se marchent pas dessus.
        $readMap = $this->buildMessageReadMap($messages, $em);
        $this->markMessagesAsRead($messages, $user, $em, $readMap);
        $em->flush();

        return $this->json([
            'id' => $conversation->getId()->toRfc4122(),
            'requestId' => $conversation->getRequest()->getId()->toRfc4122(),
            'status' => $conversation->getStatus()->value,
            'messages' => array_map(
                function (Message $m) use ($conversation, $readMap, $attachmentStorage) {
                    $recipient = $this->otherParticipant($conversation, $m->getSender());
                    $readAt = $recipient !== null ? $readMap[$m->getId()->toRfc4122()][$recipient->getId()->toRfc4122()] ?? null : null;

                    return [
                        'id' => $m->getId()->toRfc4122(),
                        'senderId' => $m->getSender()?->getId()->toRfc4122(),
                        'content' => null !== $m->getModeratedAt() ? null : $m->getContent(),
                        'moderated' => null !== $m->getModeratedAt(),
                        'isSystem' => $m->isSystem(),
                        'createdAt' => $m->getCreatedAt()->format(DATE_ATOM),
                        // * Lu par le destinataire (l'autre participant, jamais l'expéditeur lui-même) --
                        // * null tant que non lu, ou pour un message système (pas de destinataire unique).
                        'readAt' => $readAt?->format(DATE_ATOM),
                        // ! fileUrl en base est une clé objet privée, pas une URL -- l'URL signée est générée
                        // ! à la demande ici, jamais persistée (elle expirerait), cf. message_attachments.storage.
                        'attachments' => array_map(
                            static fn (MessageAttachment $a) => [
                                'id' => $a->getId()->toRfc4122(),
                                'fileName' => $a->getFileName(),
                                'mimeType' => $a->getMimeType(),
                                'fileSize' => $a->getFileSize(),
                                'url' => $attachmentStorage->temporaryUrl($a->getFileUrl(), new \DateTimeImmutable('+1 hour')),
                            ],
                            $m->getAttachments()->toArray()
                        ),
                    ];
                },
                $messages
            ),
        ]);
    }

    /**
     * Accusés de lecture déjà enregistrés pour ces messages, avant tout marquage par l'appel en cours.
     *
     * @param Message[] $messages
     *
     * @return array<string, array<string, \DateTimeImmutable>> id message => [id utilisateur => readAt]
     */
    private function buildMessageReadMap(array $messages, EntityManagerInterface $em): array
    {
        if ($messages === []) {
            return [];
        }

        $existingReads = $em->getRepository(MessageRead::class)->createQueryBuilder('mr')
            ->where('mr.message IN (:messages)')
            ->setParameter('messages', $messages)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($existingReads as $read) {
            $map[$read->getMessage()->getId()->toRfc4122()][$read->getIdUser()->getId()->toRfc4122()] = $read->getReadAt();
        }

        return $map;
    }

    /**
     * Marque comme lus par $user tous les messages de la conversation qu'il n'a pas envoyés lui-même
     * (y compris les messages système, sender null) et qu'il n'avait pas encore lus. $readMap est passée
     * par référence pour rester cohérente si jamais elle est relue après cet appel dans le même contrôleur.
     *
     * @param Message[]                                              $messages
     * @param array<string, array<string, \DateTimeImmutable>>       &$readMap
     */
    private function markMessagesAsRead(array $messages, User $user, EntityManagerInterface $em, array &$readMap): void
    {
        $now = new \DateTimeImmutable();
        $userId = $user->getId()->toRfc4122();

        foreach ($messages as $message) {
            if ($message->getSender() === $user) {
                continue;
            }
            $messageId = $message->getId()->toRfc4122();
            if (isset($readMap[$messageId][$userId])) {
                continue;
            }

            $read = new MessageRead();
            $read->setMessage($message);
            $read->setIdUser($user);
            $read->setReadAt($now);
            $em->persist($read);

            $readMap[$messageId][$userId] = $now;
        }
    }

    /**
     * L'autre participant d'une conversation à deux, relativement à l'expéditeur d'un message précis --
     * null si $sender est lui-même null (message système, pas de destinataire unique pertinent).
     */
    private function otherParticipant(Conversation $conversation, ?User $sender): ?User
    {
        if ($sender === null) {
            return null;
        }

        return $sender === $conversation->getClient() ? $conversation->getProducer()->getOwner() : $conversation->getClient();
    }

    #[Route('/api/conversations/{id}/messages', methods: ['POST'])]
    public function sendMessage(
        string $id,
        #[MapRequestPayload] SendMessageRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        NotificationService $notificationService,
    ): JsonResponse {
        $result = $this->findAccessibleConversation($id, $user, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $conversation = $result;

        // ! Bloque l'envoi si le destinataire a bloqué l'expéditeur -- BlockedUser existait déjà mais
        // ! n'était encore branché à aucune route (même situation que producer_has_feature avant, cahier fonctionnel).
        $otherPartyUser = $conversation->getClient() === $user ? $conversation->getProducer()->getOwner() : $conversation->getClient();
        if ($otherPartyUser !== null) {
            $isBlocked = $em->getRepository(BlockedUser::class)->findOneBy(['blocker' => $otherPartyUser, 'blocked' => $user]) !== null;
            if ($isBlocked) {
                return $this->json(['error' => 'Vous ne pouvez pas contacter cet utilisateur.'], 403);
            }
        }

        $message = new Message();
        $message->setConversation($conversation);
        $message->setSender($user);
        $message->setContent($request->content);

        $conversation->setLastMessageAt(new \DateTimeImmutable());

        // * Cahier fonctionnel : la demande passe à "Conversation ouverte" dès le premier vrai
        // * message -- seulement depuis un statut "en cours", pour ne jamais faire régresser un statut
        // * terminal (accord trouvé, annulée, archivée, expirée, signalée).
        $clientRequest = $conversation->getRequest();
        if (in_array($clientRequest->getStatus(), [RequestStatus::Sent, RequestStatus::WaitingReplies, RequestStatus::RepliesReceived], true)) {
            $clientRequest->setStatus(RequestStatus::ConversationOpen);
        }

        if ($otherPartyUser !== null) {
            $notificationService->notify($otherPartyUser, 'new_message', 'Nouveau message', 'Vous avez reçu un nouveau message.');
        }

        $em->persist($message);
        $em->flush();

        return $this->json(['id' => $message->getId()->toRfc4122()], 201);
    }

    #[Route('/api/conversations/{id}/report', methods: ['POST'])]
    public function reportConversation(
        string $id,
        #[MapRequestPayload] ReportConversationRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
    ): JsonResponse {
        $result = $this->findAccessibleConversation($id, $user, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $conversation = $result;

        $report = new Report();
        $report->setReporter($user);
        $report->setTargetType('conversation');
        $report->setTargetId($conversation->getId());
        $report->setReason($request->reason);
        $report->setMessage($request->message);

        // ! Cahier fonctionnel : "les conversations privées sont consultées uniquement en cas de signalement..."
        $conversation->setStatus(ConversationStatus::Reported);

        $em->persist($report);
        $em->flush();

        return $this->json(['id' => $report->getId()->toRfc4122()], 201);
    }

    private function findAccessibleConversation(string $id, User $user, EntityManagerInterface $em): Conversation|JsonResponse
    {
        $conversation = $em->find(Conversation::class, $id);
        if ($conversation === null) {
            return $this->json(['error' => 'Conversation introuvable.'], 404);
        }

        $producer = $user->getProducerProfile();
        $isParticipant = $conversation->getClient() === $user || ($producer !== null && $conversation->getProducer() === $producer);
        if (!$isParticipant) {
            return $this->json(['error' => 'Accès refusé.'], 403);
        }

        return $conversation;
    }

    #[Route('/api/conversations/{id}/attachments', methods: ['POST'])]
    public function uploadAttachment(
        string $id,
        Request $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        #[Autowire(service: 'message_attachments.storage')] FilesystemOperator $storage,
        NotificationService $notificationService,
    ): JsonResponse {
        $result = $this->findAccessibleConversation($id, $user, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $conversation = $result;

        // * Même garde-fou que sendMessage() : envoyer une pièce jointe est aussi envoyer un message.
        $otherPartyUser = $conversation->getClient() === $user ? $conversation->getProducer()->getOwner() : $conversation->getClient();
        if ($otherPartyUser !== null) {
            $isBlocked = $em->getRepository(BlockedUser::class)->findOneBy(['blocker' => $otherPartyUser, 'blocked' => $user]) !== null;
            if ($isBlocked) {
                return $this->json(['error' => 'Vous ne pouvez pas contacter cet utilisateur.'], 403);
            }
        }

        $file = $request->files->get('file');
        if ($file === null || !$file->isValid()) {
            return $this->json(['error' => 'Aucun fichier "file" valide reçu.'], 422);
        }
        if (!in_array($file->getMimeType(), self::ALLOWED_ATTACHMENT_MIME_TYPES, true)) {
            return $this->json(['error' => 'Format non supporté (jpeg, png, webp ou pdf uniquement).'], 422);
        }
        if ($file->getSize() > self::MAX_ATTACHMENT_SIZE_BYTES) {
            return $this->json(['error' => 'Fichier trop volumineux (10 Mo maximum).'], 422);
        }

        $message = new Message();
        $message->setConversation($conversation);
        $message->setSender($user);
        // * Légende facultative envoyée avec le fichier (ex. champ "content" du multipart), comme la plupart
        // * des messageries -- pas dans le cahier explicitement mais cohérent avec le cahier fonctionnel "Messages texte, photos...".
        $message->setContent($request->request->get('content'));

        $attachment = new MessageAttachment();
        $attachment->setMessage($message);
        $attachment->setFileName($file->getClientOriginalName());
        $attachment->setMimeType($file->getMimeType());
        $attachment->setFileSize($file->getSize());

        $key = sprintf('%s/%s', $conversation->getId()->toRfc4122(), $attachment->getId()->toRfc4122());
        $storage->write($key, file_get_contents($file->getPathname()));
        // ! Clé objet stockée, pas une URL : voir le commentaire sur message_attachments.storage plus haut.
        $attachment->setFileUrl($key);

        $conversation->setLastMessageAt(new \DateTimeImmutable());
        $clientRequest = $conversation->getRequest();
        if (in_array($clientRequest->getStatus(), [RequestStatus::Sent, RequestStatus::WaitingReplies, RequestStatus::RepliesReceived], true)) {
            $clientRequest->setStatus(RequestStatus::ConversationOpen);
        }

        if ($otherPartyUser !== null) {
            $notificationService->notify($otherPartyUser, 'new_message', 'Nouveau message', 'Vous avez reçu un nouveau message.');
        }

        $em->persist($message);
        $em->persist($attachment);
        $em->flush();

        return $this->json(['id' => $message->getId()->toRfc4122(), 'attachmentId' => $attachment->getId()->toRfc4122()], 201);
    }
}
