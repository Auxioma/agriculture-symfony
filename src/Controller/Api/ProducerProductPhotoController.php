<?php

namespace App\Controller\Api;

use App\Entity\Identity\User;
use App\Entity\Producer\ProducerProduct;
use App\Service\Producer\ProductPhotoStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Photos d'un produit du producteur connecté (ProducerProductMedia), distinctes des photos de l'exploitation
 * (ProducerMediaController). Stockage et règles dans ProductPhotoStorage : jpeg, png ou webp, 5 Mo, 5 photos au plus.
 *
 * * Une photo s'envoie après la création du produit (il faut son identifiant) : le formulaire d'ajout enregistre le
 * * produit, puis envoie ses photos une par une.
 */
final class ProducerProductPhotoController extends AbstractController
{
    /**
     * @return JsonResponse identifiant et URL publique de la photo (201), ou 403 / 404 / 422
     */
    #[Route('/api/producer/products/{id}/photos', methods: ['POST'])]
    public function upload(
        string $id,
        Request $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        ProductPhotoStorage $photos,
    ): JsonResponse {
        $item = $this->findOwned($id, $user, $em);
        if ($item instanceof JsonResponse) {
            return $item;
        }

        $file = $request->files->get('photo');
        if ($file === null || !$file->isValid()) {
            return $this->json(['error' => 'Aucun fichier "photo" valide reçu.'], 422);
        }

        // ! comme ProducerMediaController : le type est lu dans le contenu du fichier, pas dans le Content-Type du client
        if (!\in_array($file->getMimeType(), ProductPhotoStorage::ALLOWED_MIME_TYPES, true)) {
            return $this->json(['error' => 'Format non supporté (jpeg, png ou webp uniquement).'], 422);
        }
        if ($file->getSize() > ProductPhotoStorage::MAX_SIZE_BYTES) {
            return $this->json(['error' => 'Fichier trop volumineux (5 Mo maximum).'], 422);
        }
        if ($item->getMedia()->count() >= ProductPhotoStorage::MAX_PER_PRODUCT) {
            return $this->json(['error' => 'Cinq photos au maximum par produit.'], 422);
        }

        $media = $photos->store($item, $file, $request->request->get('altText'));
        $em->persist($media);
        $em->flush();

        return $this->json(['id' => $media->getId()->toRfc4122(), 'url' => $media->getFileUrl()], 201);
    }

    #[Route('/api/producer/products/{id}/photos/{photoId}', methods: ['DELETE'])]
    public function remove(
        string $id,
        string $photoId,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        ProductPhotoStorage $photos,
    ): JsonResponse {
        $item = $this->findOwned($id, $user, $em);
        if ($item instanceof JsonResponse) {
            return $item;
        }

        foreach ($item->getMedia() as $media) {
            if ($media->getId()->toRfc4122() === $photoId) {
                $photos->remove($media);
                $item->removeMedium($media);
                $em->remove($media);
                $em->flush();

                return $this->json(null, 204);
            }
        }

        return $this->json(['error' => 'Photo introuvable.'], 404);
    }

    private function findOwned(string $id, User $user, EntityManagerInterface $em): ProducerProduct|JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $item = Uuid::isValid($id) ? $em->find(ProducerProduct::class, $id) : null;
        if ($item === null) {
            return $this->json(['error' => 'Produit introuvable.'], 404);
        }
        if ($item->getProducer() !== $producer) {
            return $this->json(['error' => 'Accès refusé.'], 403);
        }

        return $item;
    }
}
