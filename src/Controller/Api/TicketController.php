<?php

namespace App\Controller\Api;

use App\Dto\Support\CreateTicketRequest;
use App\Dto\Support\SendTicketMessageRequest;
use App\Entity\Identity\User;
use App\Entity\Support\Ticket;
use App\Entity\Support\TicketAttachment;
use App\Entity\Support\TicketMessage;
use App\Service\Support\TicketAttachmentUploader;
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
 * Cahier fonctionnel -- "Support avancé" : permet à un client ou un producteur d'ouvrir et de suivre
 * lui-même un ticket, jusqu'ici uniquement gérable côté back-office (TicketCrudController, qui reste la
 * seule façon de changer status/priority/assignedTo -- ces trois champs restent des leviers internes à
 * l'équipe support, jamais posés par l'utilisateur lui-même). Ticket::$status reste une simple chaîne, pas
 * un enum (voir TicketCrudController) : les constantes ci-dessous reflètent exactement les mêmes valeurs.
 */

final class TicketController extends AbstractController
{
    private const STATUS_OPEN = 'open';
    private const STATUS_RESOLVED = 'resolved';
    private const STATUS_CLOSED = 'closed';

    #[Route('/api/support/tickets', methods: ['POST'])]
    public function createTicket(
        #[MapRequestPayload] CreateTicketRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
    ): JsonResponse {
        $ticket = new Ticket();
        $ticket->setIdUser($user);
        $ticket->setSubject($request->subject);
        $ticket->setStatus(self::STATUS_OPEN);

        $message = new TicketMessage();
        $message->setTicket($ticket);
        $message->setSender($user);
        $message->setContent($request->content);

        $em->persist($ticket);
        $em->persist($message);
        $em->flush();

        return $this->json(['id' => $ticket->getId()->toRfc4122()], 201);
    }

    #[Route('/api/support/tickets', methods: ['GET'])]
    public function listTickets(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $tickets = $em->getRepository(Ticket::class)->findBy(['idUser' => $user], ['createdAt' => 'DESC']);

        return $this->json(array_map(
            static fn (Ticket $t) => [
                'id' => $t->getId()->toRfc4122(),
                'subject' => $t->getSubject(),
                'status' => $t->getStatus(),
                'priority' => $t->getPriority(),
                'createdAt' => $t->getCreatedAt()->format(DATE_ATOM),
                'closedAt' => $t->getClosedAt()?->format(DATE_ATOM),
            ],
            $tickets
        ));
    }

    #[Route('/api/support/tickets/{id}', methods: ['GET'])]
    public function getTicket(
        string $id,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        #[Autowire(service: 'ticket_attachments.storage')] FilesystemOperator $storage,
    ): JsonResponse {
        $result = $this->findOwnedTicket($id, $user, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $ticket = $result;

        // * Triés explicitement par createdAt : Ticket::$messages n'a pas d'#[ORM\OrderBy] (même remarque
        // * que Conversation::$messages dans ConversationController::getConversation()).
        $messages = $em->getRepository(TicketMessage::class)->findBy(['ticket' => $ticket], ['createdAt' => 'ASC']);

        return $this->json([
            'id' => $ticket->getId()->toRfc4122(),
            'subject' => $ticket->getSubject(),
            'status' => $ticket->getStatus(),
            'priority' => $ticket->getPriority(),
            'createdAt' => $ticket->getCreatedAt()->format(DATE_ATOM),
            'closedAt' => $ticket->getClosedAt()?->format(DATE_ATOM),
            'messages' => array_map(
                static fn (TicketMessage $m) => [
                    'id' => $m->getId()->toRfc4122(),
                    'senderId' => $m->getSender()?->getId()->toRfc4122(),
                    'content' => $m->getContent(),
                    'createdAt' => $m->getCreatedAt()->format(DATE_ATOM),
                    // ! fileUrl en base est une clé objet privée, pas une URL -- même raisonnement que
                    // ! MessageAttachment dans ConversationController::getConversation().
                    'attachments' => array_map(
                        static fn (TicketAttachment $a) => [
                            'id' => $a->getId()->toRfc4122(),
                            'fileName' => $a->getFileName(),
                            'mimeType' => $a->getMimeType(),
                            'fileSize' => $a->getFileSize(),
                            'url' => $storage->temporaryUrl($a->getFileUrl(), new \DateTimeImmutable('+1 hour')),
                        ],
                        $m->getAttachments()->toArray()
                    ),
                ],
                $messages
            ),
        ]);
    }

    #[Route('/api/support/tickets/{id}/messages', methods: ['POST'])]
    public function sendMessage(
        string $id,
        #[MapRequestPayload] SendTicketMessageRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
    ): JsonResponse {
        $result = $this->findOwnedTicket($id, $user, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $ticket = $result;

        $error = $this->reopenOrRejectIfClosed($ticket);
        if ($error !== null) {
            return $error;
        }

        $message = new TicketMessage();
        $message->setTicket($ticket);
        $message->setSender($user);
        $message->setContent($request->content);

        $em->persist($message);
        $em->flush();

        return $this->json(['id' => $message->getId()->toRfc4122()], 201);
    }

    #[Route('/api/support/tickets/{id}/attachments', methods: ['POST'])]
    public function uploadAttachment(
        string $id,
        Request $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        TicketAttachmentUploader $uploader,
    ): JsonResponse {
        $result = $this->findOwnedTicket($id, $user, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $ticket = $result;

        $error = $this->reopenOrRejectIfClosed($ticket);
        if ($error !== null) {
            return $error;
        }

        $file = $request->files->get('file');
        if ($file === null || !$file->isValid()) {
            return $this->json(['error' => 'Aucun fichier "file" valide reçu.'], 422);
        }
        $fileError = $uploader->validationError($file);
        if (null !== $fileError) {
            return $this->json(['error' => $fileError], 422);
        }

        $message = new TicketMessage();
        $message->setTicket($ticket);
        $message->setSender($user);
        $message->setContent($request->request->get('content'));

        $attachment = $uploader->store($message, $file);

        $em->persist($message);
        $em->persist($attachment);
        $em->flush();

        return $this->json(['id' => $message->getId()->toRfc4122(), 'attachmentId' => $attachment->getId()->toRfc4122()], 201);
    }

    private function findOwnedTicket(string $id, User $user, EntityManagerInterface $em): Ticket|JsonResponse
    {
        $ticket = $em->find(Ticket::class, $id);
        if ($ticket === null) {
            return $this->json(['error' => 'Ticket introuvable.'], 404);
        }
        if ($ticket->getIdUser() !== $user) {
            return $this->json(['error' => 'Accès refusé.'], 403);
        }

        return $ticket;
    }

    // * Un ticket "resolved" se rouvre automatiquement dès qu'un nouveau message arrive -- typiquement le
    // * client qui répond "en fait ce n'est toujours pas réglé". Un ticket "closed" (fermeture définitive,
    // * levier explicite de l'équipe support) ne se rouvre jamais tout seul : il faut en ouvrir un nouveau.
    private function reopenOrRejectIfClosed(Ticket $ticket): ?JsonResponse
    {
        if ($ticket->getStatus() === self::STATUS_CLOSED) {
            return $this->json(['error' => 'Ce ticket est fermé -- ouvrez-en un nouveau si besoin.'], 409);
        }
        if ($ticket->getStatus() === self::STATUS_RESOLVED) {
            $ticket->setStatus(self::STATUS_OPEN);
        }

        return null;
    }
}
