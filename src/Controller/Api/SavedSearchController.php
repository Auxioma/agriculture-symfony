<?php

namespace App\Controller\Api;

use App\Dto\Engagement\SaveSearchRequest;
use App\Entity\Engagement\SavedSearch;
use App\Entity\Identity\User;
use App\Service\Pagination\Pagination;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Recherches sauvegardées (cahier fonctionnel, dashboard client : "recherches et zones
 * sauvegardées"). $notificationsEnabled n'est qu'un indicateur stocké ici -- aucune tâche planifiée
 * ne s'en sert encore pour alerter sur de nouveaux résultats (cahier V2, "Relances automatiques").
 */
final class SavedSearchController extends AbstractController
{
    /** Par pages (?page=, ?pageSize= : 100 par défaut et au plus ; le total de toutes les pages dans X-Total-Count). */
    #[Route('/api/saved-searches', methods: ['GET'])]
    public function listSavedSearches(Request $request, #[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $pagination = Pagination::fromRequest($request, defaultPageSize: Pagination::MAX_PAGE_SIZE);
        if ($pagination === null) {
            return $this->json(['error' => Pagination::invalidMessage()], 400);
        }

        [$searches, $total] = $pagination->slice(
            $em->getRepository(SavedSearch::class)->createQueryBuilder('s')
                ->where('s.idUser = :user')
                ->setParameter('user', $user)
                ->orderBy('s.createdAt', 'DESC')
                ->addOrderBy('s.id', 'ASC')
        );

        return $pagination->withTotal($this->json(array_map($this->describe(...), $searches)), $total);
    }

    #[Route('/api/saved-searches', methods: ['POST'])]
    public function createSavedSearch(
        #[MapRequestPayload] SaveSearchRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
    ): JsonResponse {
        $search = new SavedSearch();
        $search->setIdUser($user);
        $search->setName($request->name);
        $search->setCriteria($request->criteria);
        $search->setNotificationsEnabled($request->notificationsEnabled);

        $em->persist($search);
        $em->flush();

        return $this->json($this->describe($search), 201);
    }

    #[Route('/api/saved-searches/{id}', methods: ['PUT'])]
    public function updateSavedSearch(
        string $id,
        #[MapRequestPayload] SaveSearchRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
    ): JsonResponse {
        $search = $em->find(SavedSearch::class, $id);
        if ($search === null || $search->getIdUser() !== $user) {
            return $this->json(['error' => 'Recherche sauvegardée introuvable.'], 404);
        }

        $search->setName($request->name);
        $search->setCriteria($request->criteria);
        $search->setNotificationsEnabled($request->notificationsEnabled);
        $em->flush();

        return $this->json($this->describe($search));
    }

    #[Route('/api/saved-searches/{id}', methods: ['DELETE'])]
    public function deleteSavedSearch(string $id, #[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $search = $em->find(SavedSearch::class, $id);
        if ($search === null || $search->getIdUser() !== $user) {
            return $this->json(['error' => 'Recherche sauvegardée introuvable.'], 404);
        }

        $em->remove($search);
        $em->flush();

        return $this->json(null, 204);
    }

    /**
     * @return array{id: string, name: string, criteria: array<string, mixed>|null, notificationsEnabled: bool, createdAt: string}
     */
    private function describe(SavedSearch $search): array
    {
        return [
            'id' => $search->getId()->toRfc4122(),
            'name' => $search->getName(),
            'criteria' => $search->getCriteria(),
            'notificationsEnabled' => $search->isNotificationsEnabled(),
            'createdAt' => $search->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
