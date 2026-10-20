<?php

namespace App\Service\Matching;

use App\Entity\Matching\ProducerReply;
use App\Entity\Matching\ReplyAttachment;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Pièces jointes d'une réponse de producteur (cahier fonctionnel : "Joindre un PDF, une photo ou un devis"). Le
 * fichier va dans le stockage privé reply_attachments.storage ; $fileUrl garde la clé de l'objet, jamais une URL
 * (elle expirerait) : l'URL signée est générée à la demande par present(), comme pour la messagerie.
 */
final class ReplyAttachmentManager
{
    public function __construct(
        #[Autowire(service: 'reply_attachments.storage')] private readonly FilesystemOperator $storage,
    ) {
    }

    // * Écrit le fichier et rattache la pièce jointe à la réponse ; l'appelant la persiste.
    public function add(ProducerReply $reply, UploadedFile $file): ReplyAttachment
    {
        $attachment = new ReplyAttachment();
        $attachment->setFileName($file->getClientOriginalName());
        $attachment->setMimeType($file->getMimeType());
        $attachment->setUploadedAt(new \DateTimeImmutable());

        $key = sprintf('%s/%s', $reply->getId()->toRfc4122(), $attachment->getId()->toRfc4122());
        $this->storage->write($key, file_get_contents($file->getPathname()));
        $attachment->setFileUrl($key);
        $reply->addAttachment($attachment);

        return $attachment;
    }

    // * Efface le fichier du stockage ; l'appelant supprime la ligne.
    public function delete(ReplyAttachment $attachment): void
    {
        $key = $attachment->getFileUrl();
        if (null !== $key && $this->storage->fileExists($key)) {
            $this->storage->delete($key);
        }
    }

    /**
     * @return array{id: string, fileName: string|null, mimeType: string|null, fileUrl: string|null}
     */
    public function present(ReplyAttachment $attachment): array
    {
        $key = $attachment->getFileUrl();

        return [
            'id' => $attachment->getId()->toRfc4122(),
            'fileName' => $attachment->getFileName(),
            'mimeType' => $attachment->getMimeType(),
            'fileUrl' => null !== $key ? $this->storage->temporaryUrl($key, new \DateTimeImmutable('+1 hour')) : null,
        ];
    }
}
