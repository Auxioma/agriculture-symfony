<?php

namespace App\Controller\Api;

use App\Entity\Identity\User;
use App\Entity\Trust\Review;
use App\Enum\ReviewStatus;
use App\Service\Pagination\Pagination;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
    /** Par pages (?page=, ?pageSize= : 100 par défaut et au plus ; le total de toutes les pages dans X-Total-Count et "count"). */
    #[Route('/api/producer/reviews', methods: ['GET'])]
    public function listReviews(Request $request, #[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $pagination = Pagination::fromRequest($request, defaultPageSize: Pagination::MAX_PAGE_SIZE);
        if ($pagination === null) {
            return $this->json(['error' => Pagination::invalidMessage()], 400);
        }

        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => 'Profil producteur introuvable.'], 404);
        }

        // * leftJoin + addSelect : les auteurs viennent avec les avis, sans une requête de plus par auteur.
        // * la page ne porte que les avis demandés ; la moyenne et le nombre sont ceux de TOUS les avis publiés (AVG ignore les
        // * notes absentes, comme avant), pas de la page
        [$reviews, $count] = $pagination->slice(
            $em->getRepository(Review::class)->createQueryBuilder('r')
                ->leftJoin('r.client', 'c')
                ->addSelect('c')
                ->where('r.producer = :producer')
                ->andWhere('r.status = :status')
                ->setParameter('producer', $producer)
                ->setParameter('status', ReviewStatus::Published)
                ->orderBy('r.createdAt', 'DESC')
                // * r.id en dernier tri : deux avis de la même seconde ne doivent pas changer de page d'un appel à l'autre
                ->addOrderBy('r.id', 'ASC')
        );
        $average = $em->createQueryBuilder()
            ->select('AVG(r.rating)')
            ->from(Review::class, 'r')
            ->where('r.producer = :producer')
            ->andWhere('r.status = :status')
            ->setParameter('producer', $producer)
            ->setParameter('status', ReviewStatus::Published)
            ->getQuery()
            ->getSingleScalarResult();

        return $pagination->withTotal($this->json([
            'averageRating' => $average !== null ? round((float) $average, 1) : null,
            'count' => $count,
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
        ]), $count);
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
