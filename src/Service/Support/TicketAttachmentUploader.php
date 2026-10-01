<?php

namespace App\Service\Support;

use App\Entity\Support\TicketAttachment;
use App\Entity\Support\TicketMessage;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Règles et écriture des pièces jointes de ticket de support (cahier fonctionnel, Support : "pièces jointes"),
 * partagées par l'API (TicketController, côté demandeur) et le back-office (TicketCrudController, côté agent) :
 * un fichier doit être accepté ou refusé pareil quel que soit son auteur. Ne persiste rien -- l'appelant
 * persiste le message et la pièce jointe puis flush, comme partout ailleurs.
 */
final class TicketAttachmentUploader
{
    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    public const MAX_SIZE_BYTES = 10 * 1024 * 1024;

    public function __construct(
        #[Autowire(service: 'ticket_attachments.storage')]
        private readonly FilesystemOperator $storage,
    ) {
    }

    /**
     * @return string|null le message d'erreur à montrer à l'utilisateur, ou null si le fichier est acceptable
     */
    public function validationError(UploadedFile $file): ?string
    {
        if (!\in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            return 'Format non supporté (jpeg, png, webp ou pdf uniquement).';
        }
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            return 'Fichier trop volumineux (10 Mo maximum).';
        }

        return null;
    }

    // * Le mime type et la taille sont ceux lus sur le fichier reçu (pas ceux déclarés par le navigateur), déjà
    // * validés par validationError() : à appeler seulement après elle.
    public function store(TicketMessage $message, UploadedFile $file): TicketAttachment
    {
        $attachment = new TicketAttachment();
        $attachment->setTicketMessage($message);
        $attachment->setFileName($file->getClientOriginalName());
        $attachment->setMimeType($file->getMimeType());
        $attachment->setFileSize($file->getSize());

        $key = sprintf('%s/%s', $message->getTicket()->getId()->toRfc4122(), $attachment->getId()->toRfc4122());
        $this->storage->write($key, (string) file_get_contents($file->getPathname()));
        $attachment->setFileUrl($key);

        return $attachment;
    }
}
