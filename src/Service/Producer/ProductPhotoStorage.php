<?php

namespace App\Service\Producer;

use App\Entity\Producer\ProducerProduct;
use App\Entity\Producer\ProducerProductMedia;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Photos d'un produit du producteur, sur le même stockage objet que les photos de l'exploitation (producer_media.storage,
 * MinIO en local, S3 en production). Fichier rangé sous "{producteur}/products/{photo}.{extension}".
 *
 * ! Les règles (types, taille) sont celles de ProducerMediaController::uploadPhoto : jpeg, png ou webp, 5 Mo.
 */
final class ProductPhotoStorage
{
    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    public const MAX_SIZE_BYTES = 5 * 1024 * 1024;
    // * assez pour montrer le produit sans transformer la fiche en galerie
    public const MAX_PER_PRODUCT = 5;

    public function __construct(
        #[Autowire(service: 'producer_media.storage')] private readonly FilesystemOperator $storage,
    ) {
    }

    /**
     * Écrit le fichier et crée la photo (pas encore enregistrée en base : à l'appelant de la persister).
     */
    public function store(ProducerProduct $product, UploadedFile $file, ?string $altText): ProducerProductMedia
    {
        $media = new ProducerProductMedia();
        $media->setAltText($altText);
        $media->setPosition($product->getMedia()->count());

        $key = $this->key($product, $media->getId()->toRfc4122(), $file->guessExtension() ?? 'bin');
        $this->storage->write($key, (string) file_get_contents($file->getPathname()));
        $media->setFileUrl($this->storage->publicUrl($key));
        $product->addMedium($media);

        return $media;
    }

    /**
     * Supprime le fichier (sans erreur s'il n'existe plus). La photo en base est retirée par l'appelant.
     */
    public function remove(ProducerProductMedia $media): void
    {
        $product = $media->getProducerProduct();
        $path = (string) parse_url((string) $media->getFileUrl(), PHP_URL_PATH);
        if (null === $product || '' === pathinfo($path, PATHINFO_EXTENSION)) {
            return;
        }

        $key = $this->key($product, $media->getId()->toRfc4122(), pathinfo($path, PATHINFO_EXTENSION));
        if ($this->storage->fileExists($key)) {
            $this->storage->delete($key);
        }
    }

    private function key(ProducerProduct $product, string $mediaId, string $extension): string
    {
        return \sprintf('%s/products/%s.%s', $product->getProducer()->getId()->toRfc4122(), $mediaId, $extension);
    }
}
