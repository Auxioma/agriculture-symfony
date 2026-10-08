<?php

namespace App\Controller\Api;

use App\Entity\Catalog\Category;
use App\Entity\Catalog\CategoryTranslation;
use App\Entity\Catalog\Label;
use App\Entity\Catalog\LabelTranslation;
use App\Entity\Catalog\Product;
use App\Entity\Catalog\ProductTranslation;
use App\Repository\Producer\ProducerProductRepository;
use App\Service\Platform\PlatformSettings;
use App\Service\Validation\UuidFormat;
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
 * ?locale= (défaut : langue par défaut des paramètres de la plateforme, 'fr' tant que rien n'est enregistré ; même convention que LegalController) sélectionne la traduction ; à défaut de
 * traduction pour la locale demandée, on retombe sur les champs de base de l'entité plutôt qu'un 404 --
 * mieux vaut un contenu en français par défaut qu'une fiche vide pour une langue pas encore traduite.
 */
final class CatalogController extends AbstractController
{
    private const LOCALE_PATTERN = '/^[a-z]{2,3}(?:-[A-Za-z]{2})?$/';

    public function __construct(private readonly PlatformSettings $settings, private readonly ProducerProductRepository $producerProducts,)
    {
    }

    private function resolveLocale(Request $request): string
    {
        $locale = $request->query->get('locale');

        return is_string($locale) && preg_match(self::LOCALE_PATTERN, $locale) === 1 ? $locale : $this->settings->defaultLocale();
    }

    #[Route('/api/categories', methods: ['GET'])]
    public function listCategories(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $locale = $this->resolveLocale($request);

        // * Seules les catégories actives sont visibles publiquement, une catégorie désactivée
        // * est un brouillon/masquage volontaire côté back-office, pas censée apparaître ici
        $categories = $em->getRepository(Category::class)->findBy(['isActive' => true], ['position' => 'ASC']);
        $translations = $this->indexTranslationsByParentId(
            $categories === [] ? [] : $em->getRepository(CategoryTranslation::class)->findBy(['category' => $categories, 'locale' => $locale]),
            static fn (CategoryTranslation $t) => $t->getCategory()->getId()->toRfc4122()
        );
        $producerCounts = $this->producerProducts->countProducersByCategory();

        return $this->json(array_map(
            fn (Category $c) => $this->serializeCategory($c, $translations[$c->getId()->toRfc4122()] ?? null, $producerCounts[$c->getId()->toRfc4122()] ?? 0),
            $categories
        ));
    }

    /** @return array{id: string, name: string, slug: ?string, icon: ?string, imageUrl: ?string, parentId: ?string, description: ?string, seoTitle: ?string, seoDescription: ?string, producerCount: int} */
    private function serializeCategory(Category $c, ?CategoryTranslation $t, int $producerCount): array
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
            'producerCount' => $producerCount,
        ];
    }

    #[Route('/api/products', methods: ['GET'])]
    public function listProducts(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $locale = $this->resolveLocale($request);

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
        // * Une colonne uuid refuse toute autre valeur : sans ce contrôle, "/api/products/abc" lève une erreur
        // * de conversion côté base (HTTP 500). Un produit désactivé reste introuvable, comme dans la liste.
        $product = UuidFormat::isValid($id) ? $em->find(Product::class, $id) : null;
        if ($product === null || !$product->isActive()) {
            return $this->json(['error' => 'Produit introuvable.'], 404);
        }

        $locale = $this->resolveLocale($request);
        $translation = $em->getRepository(ProductTranslation::class)->findOneBy(['product' => $product, 'locale' => $locale]);

        return $this->json($this->serializeProduct($product, $translation));
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
        $locale = $this->resolveLocale($request);

        $labels = $em->getRepository(Label::class)->findBy(['isActive' => true], ['name' => 'ASC']);
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