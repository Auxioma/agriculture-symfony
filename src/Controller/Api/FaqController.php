<?php

/**
 * Lecture publique de la FAQ (cahier fonctionnel, "FAQ rassurante" de l'accueil). Routes non
 * authentifiées (voir security.yaml, access_control ^/api/faq), meme principe que LegalController
 * pour les pages legales. Seuls les articles $isActive sont renvoyes, tries par position.
 */

namespace App\Controller\Api;

use App\Entity\Content\FaqArticle;
use App\Service\Platform\PlatformSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class FaqController extends AbstractController
{
    public function __construct(private readonly PlatformSettings $settings)
    {
    }

    #[Route('/api/faq', methods: ['GET'])]
    public function listFaqArticles(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $locale = $request->query->get('locale', $this->settings->defaultLocale());
        $articles = $em->getRepository(FaqArticle::class)->findBy(
            ['isActive' => true, 'locale' => $locale],
            ['position' => 'ASC']
        );

        return $this->json(array_map(
            static fn (FaqArticle $a) => [
                'id' => $a->getId()->toRfc4122(),
                'category' => $a->getCategory(),
                'question' => $a->getQuestion(),
                'answer' => $a->getAnswer(),
            ],
            $articles
        ));
    }
}
