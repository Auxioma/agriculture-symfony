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
 * Les règles de fichier (types, taille) sont celles de toutes les photos : voir PhotoUploadRules.
 */
final class ProductPhotoStorage
{
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
        // * la suivante, pas le nombre de photos : après la suppression de la 1re (positions 1, 2) le nombre donnerait 2, déjà pris
        $media->setPosition(1 + max([-1, ...array_map(static fn (ProducerProductMedia $photo) => $photo->getPosition() ?? -1, $product->getMedia()->toArray())]));

        $key = $this->key($product, $media->getId()->toRfc4122(), $file->guessExtension() ?? 'bin');
        $this->storage->write($key, (string) file_get_contents($file->getPathname()));
        $media->setFileUrl($this->storage->publicUrl($key));
        $product->addMedium($media);

        return $media;
    }

    /**
     * L'emplacement du fichier d'une photo, à relever AVANT de la retirer du produit (il en dépend). null si on ne le retrouve pas.
     */
    public function keyOf(ProducerProductMedia $media): ?string
    {
        $product = $media->getProducerProduct();
        $extension = pathinfo((string) parse_url((string) $media->getFileUrl(), PHP_URL_PATH), PATHINFO_EXTENSION);

        return null === $product || '' === $extension ? null : $this->key($product, $media->getId()->toRfc4122(), $extension);
    }

    /**
     * Supprime un fichier (sans erreur s'il n'existe plus). À appeler APRÈS l'enregistrement en base : si celui-ci échoue, aucune
     * photo n'a perdu son fichier ; dans l'autre sens, un fichier orphelin ne coûte que de la place.
     */
    public function delete(?string $key): void
    {
        if (null !== $key) {
            $this->storage->delete($key);
        }
    }

    private function key(ProducerProduct $product, string $mediaId, string $extension): string
    {
        return \sprintf('%s/products/%s.%s', $product->getProducer()->getId()->toRfc4122(), $mediaId, $extension);
    }
}
