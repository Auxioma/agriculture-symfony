<?php

namespace App\Controller\Api;

use App\Entity\Catalog\Category;
use App\Entity\Catalog\CategoryTranslation;
use App\Entity\Catalog\Label;
use App\Entity\Catalog\LabelTranslation;
use App\Entity\Catalog\Product;
use App\Entity\Catalog\ProductTranslation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cahier fonctionnel -- "SEO avancé, multi-langue complet" : CategoryTranslation/
 * ProductTranslation/LabelTranslation existent et sont éditables en back-office depuis longtemps
 * (CategoryCrudController::editTranslations(), etc.) mais n'étaient encore jamais lues ici -- chaque route
 * ne renvoyait que $name/$description de base (français, voir les docblocks de Category/Product/Label).
 * ?locale= (défaut 'fr', même convention que LegalController) sélectionne la traduction ; à défaut de
 * traduction pour la locale demandée, on retombe sur les champs de base de l'entité plutôt qu'un 404 --
 * mieux vaut un contenu en français par défaut qu'une fiche vide pour une langue pas encore traduite.
 */
final class CatalogController extends AbstractController
{
    #[Route('/api/categories', methods: ['GET'])]
    public function listCategories(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $locale = $request->query->get('locale', 'fr');

        // * Seules les catégories actives sont visibles publiquement, une catégorie désactivée
        // * est un brouillon/masquage volontaire côté back-office, pas censée apparaître ici
        $categories = $em->getRepository(Category::class)->findBy(['isActive' => true], ['position' => 'ASC']);
        $translations = $this->indexTranslationsByParentId(
            $categories === [] ? [] : $em->getRepository(CategoryTranslation::class)->findBy(['category' => $categories, 'locale' => $locale]),
            static fn (CategoryTranslation $t) => $t->getCategory()->getId()->toRfc4122()
        );

        return $this->json(array_map(
            fn (Category $c) => $this->serializeCategory($c, $translations[$c->getId()->toRfc4122()] ?? null),
            $categories
        ));
    }

    /** @return array{id: string, name: string, slug: ?string, icon: ?string, imageUrl: ?string, parentId: ?string, description: ?string, seoTitle: ?string, seoDescription: ?string} */
    private function serializeCategory(Category $c, ?CategoryTranslation $t): array
    {
        return [
            'id' => $c->getId()->toRfc4122(),
            'name' => $t?->getName() ?? $c->getName(),
            'slug' => $c->getSlug(),
            'icon' => $c->getIcon(),
            'imageUrl' => $c->getImageUrl(),
            'parentId' => $c->getParent()?->getId()->toRfc4122(),
            'description' => $t?->getDescription(),
            'seoTitle' => $t?->getSeoTitle(),
            'seoDescription' => $t?->getSeoDescription(),
        ];
    }

    #[Route('/api/products', methods: ['GET'])]
    public function listProducts(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $locale = $request->query->get('locale', 'fr');

        $products = $em->getRepository(Product::class)->findBy(['isActive' => true], ['name' => 'ASC']);
        $translations = $this->indexTranslationsByParentId(
            $products === [] ? [] : $em->getRepository(ProductTranslation::class)->findBy(['product' => $products, 'locale' => $locale]),
            static fn (ProductTranslation $t) => $t->getProduct()->getId()->toRfc4122()
        );

        return $this->json(array_map(
            fn (Product $p) => $this->serializeProduct($p, $translations[$p->getId()->toRfc4122()] ?? null),
            $products
        ));
    }

    #[Route('/api/products/{id}', methods: ['GET'])]
    public function getProduct(string $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $product = $em->find(Product::class, $id);
        if ($product === null) {
            return $this->json(['error' => 'Produit introuvable.'], 404);
        }

        $locale = $request->query->get('locale', 'fr');
        $translation = $em->getRepository(ProductTranslation::class)->findOneBy(['product' => $product, 'locale' => $locale]);

        return $this->json([...$this->serializeProduct($product, $translation), 'isActive' => $product->isActive()]);
    }

    /** @return array{id: string, name: string, slug: ?string, categoryId: string, seasonStartMonth: ?int, seasonEndMonth: ?int, description: ?string, keywords: ?array<int, string>} */
    private function serializeProduct(Product $p, ?ProductTranslation $t): array
    {
        return [
            'id' => $p->getId()->toRfc4122(),
            'name' => $t?->getName() ?? $p->getName(),
            'slug' => $p->getSlug(),
            'categoryId' => $p->getCategory()->getId()->toRfc4122(),
            'seasonStartMonth' => $p->getSeasonStartMonth(),
            'seasonEndMonth' => $p->getSeasonEndMonth(),
            'description' => $t?->getDescription(),
            'keywords' => $t?->getKeywords(),
        ];
    }

    #[Route('/api/labels', methods: ['GET'])]
    public function listLabels(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $locale = $request->query->get('locale', 'fr');

        $labels = $em->getRepository(Label::class)->findBy([], ['name' => 'ASC']);
        $translations = $this->indexTranslationsByParentId(
            $labels === [] ? [] : $em->getRepository(LabelTranslation::class)->findBy(['label' => $labels, 'locale' => $locale]),
            static fn (LabelTranslation $t) => $t->getLabel()->getId()->toRfc4122()
        );

        return $this->json(array_map(
            static fn (Label $l) => [
                'id' => $l->getId()->toRfc4122(),
                'code' => $l->getCode(),
                'name' => ($translations[$l->getId()->toRfc4122()] ?? null)?->getName() ?? $l->getName(),
                'description' => ($translations[$l->getId()->toRfc4122()] ?? null)?->getDescription() ?? $l->getDescription(),
            ],
            $labels
        ));
    }

    /**
     * Indexe une liste de traductions (déjà filtrées sur une seule locale) par l'id RFC4122 de leur entité
     * parente -- une seule requête pour toute la liste plutôt qu'une par entité (même principe que
     * ConversationController::countUnreadMessages()), $keyOf isolant la seule différence entre
     * Category/Product/Label (le nom de l'accesseur vers le parent).
     *
     * @template T of CategoryTranslation|ProductTranslation|LabelTranslation
     *
     * @param T[]              $translations
     * @param callable(T):string $keyOf
     *
     * @return array<string, T>
     */
    private function indexTranslationsByParentId(array $translations, callable $keyOf): array
    {
        $indexed = [];
        foreach ($translations as $translation) {
            $indexed[$keyOf($translation)] = $translation;
        }

        return $indexed;
    }
}