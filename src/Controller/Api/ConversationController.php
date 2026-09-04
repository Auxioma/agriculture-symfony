<?php

namespace App\Controller\Api;

use App\Dto\Messaging\ReportConversationRequest;
use App\Dto\Messaging\SendMessageRequest;
use App\Entity\Identity\User;
use App\Entity\Messaging\BlockedUser;
use App\Entity\Messaging\Conversation;
use App\Entity\Messaging\Message;
use App\Entity\Trust\Report;
use App\Enum\ConversationStatus;
use App\Enum\RequestStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Lecture et messages d'une conversation (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf §20.6, round 1).
 * Pas de route de création dédiée : une conversation s'ouvre implicitement dès la première réponse d'un
 * producteur (voir ProducerRequestController::replyToRequest()). POST .../attachments est hors scope de ce
 * round -- il attend l'architecture de stockage fichiers (S3/MinIO, cahier devops).
 */
final class ConversationController extends AbstractController
{
    #[Route('/api/conversations', methods: ['GET'])]
    public function listConversations(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $producer = $user->getProducerProfile();
        $conversations = $producer !== null
            ? $em->getRepository(Conversation::class)->findBy(['producer' => $producer], ['lastMessageAt' => 'DESC'])
            : $em->getRepository(Conversation::class)->findBy(['client' => $user], ['lastMessageAt' => 'DESC']);

        return $this->json(array_map(
            static fn (Conversation $c) => [
                'id' => $c->getId()->toRfc4122(),
                'requestId' => $c->getRequest()->getId()->toRfc4122(),
                'status' => $c->getStatus()->value,
                'lastMessageAt' => $c->getLastMessageAt()?->format(DATE_ATOM),
            ],
            $conversations
        ));
    }

    #[Route('/api/conversations/{id}', methods: ['GET'])]
    public function getConversation(string $id, #[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $result = $this->findAccessibleConversation($id, $user, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $conversation = $result;

        // * Triée explicitement par createdAt : Conversation::$messages n'a pas d'#[ORM\OrderBy], on ne
        // * peut pas se fier à l'ordre de la collection lazy-loadée.
        $messages = $em->getRepository(Message::class)->findBy(['conversation' => $conversation], ['createdAt' => 'ASC']);

        return $this->json([
            'id' => $conversation->getId()->toRfc4122(),
            'requestId' => $conversation->getRequest()->getId()->toRfc4122(),
            'status' => $conversation->getStatus()->value,
            'messages' => array_map(
                static fn (Message $m) => [
                    'id' => $m->getId()->toRfc4122(),
                    'senderId' => $m->getSender()?->getId()->toRfc4122(),
                    'content' => $m->getContent(),
                    'isSystem' => $m->isSystem(),
                    'createdAt' => $m->getCreatedAt()->format(DATE_ATOM),
                ],
                $messages
            ),
        ]);
    }

    #[Route('/api/conversations/{id}/messages', methods: ['POST'])]
    public function sendMessage(
        string $id,
        #[MapRequestPayload] SendMessageRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
    ): JsonResponse {
        $result = $this->findAccessibleConversation($id, $user, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $conversation = $result;

        // ! Bloque l'envoi si le destinataire a bloqué l'expéditeur -- BlockedUser existait déjà mais
        // ! n'était encore branché à aucune route (même situation que producer_has_feature avant §20.5).
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

        // * §6.2 du cahier fonctionnel : la demande passe à "Conversation ouverte" dès le premier vrai
        // * message -- seulement depuis un statut "en cours", pour ne jamais faire régresser un statut
        // * terminal (accord trouvé, annulée, archivée, expirée, signalée).
        $clientRequest = $conversation->getRequest();
        if (in_array($clientRequest->getStatus(), [RequestStatus::Sent, RequestStatus::WaitingReplies, RequestStatus::RepliesReceived], true)) {
            $clientRequest->setStatus(RequestStatus::ConversationOpen);
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

        // ! §22.2 : "les conversations privées sont consultées uniquement en cas de signalement..."
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
}
