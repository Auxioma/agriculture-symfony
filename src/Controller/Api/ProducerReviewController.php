<?php

namespace App\Controller\Api;

use App\Entity\Identity\User;
use App\Entity\Trust\Review;
use App\Enum\ReviewStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Avis clients du producteur connecté (cahier fonctionnel, dashboard producteur : "Avis clients" ; confiance : "Réponse du
 * producteur à un avis"). Même contenu que la lecture publique de sa fiche (ProducerController::listProducerReviews()),
 * sans passer par son identifiant : seuls les avis publiés par la modération, du plus récent au plus ancien, avec la
 * moyenne. Pour répondre à un avis, voir ReviewController::respondToReview().
 */

final class ProducerReviewController extends AbstractController
{
    #[Route('/api/producer/reviews', methods: ['GET'])]
    public function listReviews(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => 'Profil producteur introuvable.'], 404);
        }

        // * leftJoin + addSelect : les auteurs viennent avec les avis, sans une requête de plus par auteur.
        $reviews = $em->getRepository(Review::class)->createQueryBuilder('r')
            ->leftJoin('r.client', 'c')
            ->addSelect('c')
            ->where('r.producer = :producer')
            ->andWhere('r.status = :status')
            ->setParameter('producer', $producer)
            ->setParameter('status', ReviewStatus::Published)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $ratings = array_filter(array_map(static fn (Review $r) => $r->getRating(), $reviews), static fn ($r) => $r !== null);

        return $this->json([
            'averageRating' => $ratings !== [] ? round(array_sum($ratings) / count($ratings), 1) : null,
            'count' => count($reviews),
            'reviews' => array_map(
                fn (Review $r) => [
                    'id' => $r->getId()->toRfc4122(),
                    'authorName' => $this->authorName($r->getClient()),
                    'rating' => $r->getRating(),
                    'comment' => $r->getComment(),
                    'producerResponse' => $r->getProducerResponse(),
                    'createdAt' => $r->getCreatedAt()->format(DATE_ATOM),
                ],
                $reviews
            ),
        ]);
    }

    /**
     * Comme sous l'avis sur la fiche publique : prénom et initiale du nom ("Camille R."), jamais le nom complet ni l'e-mail.
     * null si l'auteur est inconnu ou sans prénom : le front écrit alors "Client".
     */
    private function authorName(?User $client): ?string
    {
        $firstName = trim((string) $client?->getFirstName());
        if ($firstName === '') {
            return null;
        }

        $lastName = trim((string) $client->getLastName());

        return $lastName !== '' ? sprintf('%s %s.', $firstName, mb_strtoupper(mb_substr($lastName, 0, 1))) : $firstName;
    }
}
