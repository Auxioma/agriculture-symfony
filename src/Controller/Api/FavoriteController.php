<?php

namespace App\Controller\Api;

use App\Dto\Engagement\AddFavoriteRequest;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Entity\Engagement\Favorite;
use App\Entity\Identity\User;
use App\Entity\Producer\ProducerProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Favoris (cahier fonctionnel, dashboard client : "Producteurs, produits, catégories" mis en
 * favori). Favorite::$targetType/$targetId sont une référence polymorphe (pas de FK Doctrine) --
 * resolveTarget() fait le lien manuellement selon le type.
 */
final class FavoriteController extends AbstractController
{
    #[Route('/api/favorites', methods: ['GET'])]
    public function listFavorites(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $favorites = $em->getRepository(Favorite::class)->findBy(['idUser' => $user], ['createdAt' => 'DESC']);

        return $this->json(array_map(
            fn (Favorite $f) => [
                'id' => $f->getId()->toRfc4122(),
                'targetType' => $f->getTargetType(),
                'targetId' => $f->getTargetId()?->toRfc4122(),
                'createdAt' => $f->getCreatedAt()->format(DATE_ATOM),
                // * null si la cible a été supprimée depuis (producteur/produit/catégorie retiré) --
                // * le favori reste en base (pas de FK, donc pas de suppression en cascade) mais
                // * n'a plus rien à afficher.
                'target' => $this->describeTarget($f->getTargetType(), $f->getTargetId(), $em),
            ],
            $favorites
        ));
    }

    #[Route('/api/favorites', methods: ['POST'])]
    public function addFavorite(
        #[MapRequestPayload] AddFavoriteRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
    ): JsonResponse {
        $target = $this->resolveTarget($request->targetType, $request->targetId, $em);
        if ($target === null) {
            return $this->json(['error' => 'Cible introuvable.'], 404);
        }

        $existing = $em->getRepository(Favorite::class)->findOneBy([
            'idUser' => $user,
            'targetType' => $request->targetType,
            'targetId' => $request->targetId,
        ]);
        if ($existing !== null) {
            return $this->json(['error' => 'Déjà dans vos favoris.'], 409);
        }

        $favorite = new Favorite();
        $favorite->setIdUser($user);
        $favorite->setTargetType($request->targetType);
        $favorite->setTargetId(Uuid::fromString($request->targetId));

        $em->persist($favorite);
        $em->flush();

        return $this->json(['id' => $favorite->getId()->toRfc4122()], 201);
    }

    #[Route('/api/favorites/{id}', methods: ['DELETE'])]
    public function removeFavorite(string $id, #[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $favorite = $em->find(Favorite::class, $id);
        if ($favorite === null || $favorite->getIdUser() !== $user) {
            return $this->json(['error' => 'Favori introuvable.'], 404);
        }

        $em->remove($favorite);
        $em->flush();

        return $this->json(null, 204);
    }

    private function resolveTarget(string $targetType, string $targetId, EntityManagerInterface $em): ?object
    {
        return match ($targetType) {
            'producer_profile' => $em->find(ProducerProfile::class, $targetId),
            'product' => $em->find(Product::class, $targetId),
            'category' => $em->find(Category::class, $targetId),
            default => null,
        };
    }

    /**
     * @return array<string, string|null>|null
     */
    private function describeTarget(?string $targetType, ?Uuid $targetId, EntityManagerInterface $em): ?array
    {
        if ($targetType === null || $targetId === null) {
            return null;
        }

        $target = $this->resolveTarget($targetType, $targetId->toRfc4122(), $em);

        return match (true) {
            $target instanceof ProducerProfile => ['farmName' => $target->getFarmName(), 'slug' => $target->getSlug()],
            $target instanceof Product => ['name' => $target->getName(), 'slug' => $target->getSlug()],
            $target instanceof Category => ['name' => $target->getName(), 'slug' => $target->getSlug()],
            default => null,
        };
    }
}
