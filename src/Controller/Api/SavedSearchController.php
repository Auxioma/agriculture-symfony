<?php

namespace App\Controller\Api;

use App\Dto\Engagement\SaveSearchRequest;
use App\Entity\Engagement\SavedSearch;
use App\Entity\Identity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
    #[Route('/api/saved-searches', methods: ['GET'])]
    public function listSavedSearches(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $searches = $em->getRepository(SavedSearch::class)->findBy(['idUser' => $user], ['createdAt' => 'DESC']);

        return $this->json(array_map($this->describe(...), $searches));
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
