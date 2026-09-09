<?php

namespace App\Controller\Api;

use App\Entity\Catalog\Category;
use App\Entity\Catalog\Label;
use App\Entity\Catalog\Product;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogController extends AbstractController
{
    #[Route('/api/categories', methods: ['GET'])]
    public function listCategories(EntityManagerInterface $em): JsonResponse
    {
        // * Seules les catégories actives sont visibles publiquement, une catégorie désactivée
        // * est un brouillon/masquage volontaire côté back-office, pas censée apparaître ici
        $categories = $em->getRepository(Category::class)->findBy(['isActive' => true], ['position' => 'ASC']);

        return $this->json(array_map(
            static fn (Category $c) => [
                'id' => $c->getId()->toRfc4122(),
                'name' => $c->getName(),
                'slug' => $c->getSlug(),
                'icon' => $c->getIcon(),
                'imageUrl' => $c->getImageUrl(),
                'parentId' => $c->getParent()?->getId()->toRfc4122(),
            ],
            $categories
        ));
    }

    #[Route('/api/products', methods: ['GET'])]
    public function listProducts(EntityManagerInterface $em): JsonResponse
    {
        $products = $em->getRepository(Product::class)->findBy(['isActive' => true], ['name' => 'ASC']);

        return $this->json(array_map(
            static fn (Product $p) => [
                'id' => $p->getId()->toRfc4122(),
                'name' => $p->getName(),
                'slug' => $p->getSlug(),
                'categoryId' => $p->getCategory()->getId()->toRfc4122(),
                'seasonStartMonth' => $p->getSeasonStartMonth(),
                'seasonEndMonth' => $p->getSeasonEndMonth(),
            ],
            $products
        ));
    }

    #[Route('/api/products/{id}', methods: ['GET'])]
    public function getProduct(string $id, EntityManagerInterface $em): JsonResponse
    {
        $product = $em->find(Product::class, $id);
        if ($product === null) {
            return $this->json(['error' => 'Produit introuvable.'], 404);
        }

        return $this->json([
            'id' => $product->getId()->toRfc4122(),
            'name' => $product->getName(),
            'slug' => $product->getSlug(),
            'categoryId' => $product->getCategory()->getId()->toRfc4122(),
            'seasonStartMonth' => $product->getSeasonStartMonth(),
            'seasonEndMonth' => $product->getSeasonEndMonth(),
            'isActive' => $product->isActive(),
        ]);
    }

    #[Route('/api/labels', methods: ['GET'])]
    public function listLabels(EntityManagerInterface $em): JsonResponse
    {
        $labels = $em->getRepository(Label::class)->findBy([], ['name' => 'ASC']);

        return $this->json(array_map(
            static fn (Label $l) => [
                'id' => $l->getId()->toRfc4122(),
                'code' => $l->getCode(),
                'name' => $l->getName(),
                'description' => $l->getDescription(),
            ],
            $labels
        ));
    }
}