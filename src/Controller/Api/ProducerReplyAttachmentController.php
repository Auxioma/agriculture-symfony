<?php

namespace App\Controller\Api;

use App\Entity\Identity\User;
use App\Entity\Matching\ProducerReply;
use App\Entity\Matching\ReplyAttachment;
use App\Enum\ReplyStatus;
use App\Repository\Matching\RequestMatchRepository;
use App\Service\Matching\ReplyAttachmentManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Pièces jointes du brouillon de réponse d'un producteur (cahier fonctionnel : "Joindre un PDF, une photo ou un
 * devis"). Seul un brouillon en reçoit : une réponse envoyée ne se modifie plus. En envoyant, le brouillon passe le
 * relais à la vraie réponse avec ses pièces jointes (voir ProducerRequestController::replyToRequest()).
 */
final class ProducerReplyAttachmentController extends AbstractController
{
    // * mêmes règles que les pièces jointes de la messagerie (cahier devops : type et taille contrôlés) ; le plafond de
    // * pièces par réponse est de notre choix, à faire valider
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    private const MAX_SIZE_BYTES = 10 * 1024 * 1024;
    private const MAX_PER_REPLY = 5;

    #[Route('/api/producer/replies/{id}/attachments', methods: ['POST'])]
    public function upload(
        string $id,
        Request $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        ReplyAttachmentManager $manager,
    ): JsonResponse {
        $draft = $this->findOwnDraft($id, $user, $em);
        if ($draft instanceof JsonResponse) {
            return $draft;
        }

        $file = $request->files->get('file');
        if ($file === null || !$file->isValid()) {
            return $this->json(['error' => 'Aucun fichier "file" valide reçu.'], 422);
        }
        if (!\in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            return $this->json(['error' => 'Format non supporté (jpeg, png, webp ou pdf uniquement).'], 422);
        }
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            return $this->json(['error' => 'Fichier trop volumineux (10 Mo maximum).'], 422);
        }
        if ($draft->getAttachments()->count() >= self::MAX_PER_REPLY) {
            return $this->json(['error' => sprintf('%d pièces jointes maximum par réponse.', self::MAX_PER_REPLY)], 422);
        }

        $attachment = $manager->add($draft, $file);
        $em->persist($attachment);
        $em->flush();

        return $this->json($manager->present($attachment), 201);
    }

    #[Route('/api/producer/replies/{id}/attachments/{attachmentId}', methods: ['DELETE'])]
    public function delete(
        string $id,
        string $attachmentId,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        ReplyAttachmentManager $manager,
    ): JsonResponse {
        $draft = $this->findOwnDraft($id, $user, $em);
        if ($draft instanceof JsonResponse) {
            return $draft;
        }

        $attachment = Uuid::isValid($attachmentId) ? $em->find(ReplyAttachment::class, $attachmentId) : null;
        if ($attachment === null || $attachment->getReply() !== $draft) {
            return $this->json(['error' => 'Pièce jointe introuvable.'], 404);
        }

        $manager->delete($attachment);
        $draft->removeAttachment($attachment);
        $em->remove($attachment);
        $em->flush();

        return $this->json(null, 204);
    }

    // * 404 pour un brouillon inconnu ou d'un autre producteur (on ne dit pas qu'il existe), 409 une fois la réponse
    // * envoyée ou la demande plus ouverte
    private function findOwnDraft(string $id, User $user, EntityManagerInterface $em): ProducerReply|JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $reply = Uuid::isValid($id) ? $em->find(ProducerReply::class, $id) : null;
        if ($reply === null || $reply->getProducer() !== $producer) {
            return $this->json(['error' => 'Brouillon introuvable.'], 404);
        }
        if ($reply->getStatus() !== ReplyStatus::Draft) {
            return $this->json(['error' => 'Seul un brouillon peut recevoir des pièces jointes.'], 409);
        }
        if (!\in_array($reply->getRequest()->getStatus(), RequestMatchRepository::OPEN_REQUEST_STATUSES, true)) {
            return $this->json(['error' => "Cette demande n'est plus ouverte."], 409);
        }

        return $reply;
    }
}
