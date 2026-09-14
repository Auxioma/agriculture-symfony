<?php

/**
 * Lecture publique des pages légales (CGU, confidentialité, mentions légales --
 * cahier_des_charges_fonctionnel_trouvemoi_agri.pdf, "Front office public" et "Contraintes
 * légales et RGPD"). Routes non authentifiées (voir security.yaml, access_control ^/api/legal),
 * même principe que CatalogController pour les catégories/produits/labels. Seule la version
 * $isActive d'un (code, locale) est jamais renvoyée -- LegalPageCrudController garantit qu'il y en
 * a au plus une à la fois.
 */

namespace App\Controller\Api;

use App\Entity\Content\LegalPage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class LegalController extends AbstractController
{
    #[Route('/api/legal', methods: ['GET'])]
    public function listLegalPages(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $locale = $request->query->get('locale', 'fr');
        $pages = $em->getRepository(LegalPage::class)->findBy(['isActive' => true, 'locale' => $locale], ['code' => 'ASC']);

        return $this->json(array_map(
            static fn (LegalPage $p) => [
                'code' => $p->getCode(),
                'title' => $p->getTitle(),
                'version' => $p->getVersion(),
                'publishedAt' => $p->getPublishedAt()?->format(DATE_ATOM),
            ],
            $pages
        ));
    }

    #[Route('/api/legal/{code}', methods: ['GET'])]
    public function getLegalPage(string $code, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $locale = $request->query->get('locale', 'fr');
        $page = $em->getRepository(LegalPage::class)->findOneBy(['code' => $code, 'isActive' => true, 'locale' => $locale]);
        if ($page === null) {
            return $this->json(['error' => 'Page légale introuvable.'], 404);
        }

        return $this->json([
            'code' => $page->getCode(),
            'locale' => $page->getLocale(),
            'title' => $page->getTitle(),
            'content' => $page->getContent(),
            'version' => $page->getVersion(),
            'publishedAt' => $page->getPublishedAt()?->format(DATE_ATOM),
        ]);
    }
}
