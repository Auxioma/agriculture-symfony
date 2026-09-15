<?php

namespace App\Controller\Api;

use App\Dto\Producer\SaveDeliveryZoneRequest;
use App\Entity\Identity\User;
use App\Entity\Producer\DeliveryZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Zones de livraison du producteur connecté (cahier fonctionnel, profil producteur enrichi).
 * Lecture publique : voir ProducerController::getProducer() (fiche producteur, section "Modes de
 * retrait, livraison... zones couvertes").
 */
final class ProducerDeliveryZoneController extends AbstractController
{
    #[Route('/api/producer/delivery-zones', methods: ['GET'])]
    public function listMyDeliveryZones(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $zones = $em->getRepository(DeliveryZone::class)->findBy(['producer' => $producer]);

        return $this->json(array_map($this->describe(...), $zones));
    }

    #[Route('/api/producer/delivery-zones', methods: ['POST'])]
    public function createDeliveryZone(
        #[MapRequestPayload] SaveDeliveryZoneRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
    ): JsonResponse {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        if ($request->radiusKm === null && $request->polygon === null) {
            return $this->json(['error' => 'Fournissez un rayon (radiusKm) ou un polygone (polygon).'], 422);
        }

        $zone = new DeliveryZone();
        $zone->setProducer($producer);
        $this->applyRequest($zone, $request);

        $em->persist($zone);
        $em->flush();

        return $this->json($this->describe($zone), 201);
    }

    #[Route('/api/producer/delivery-zones/{id}', methods: ['PUT'])]
    public function updateDeliveryZone(
        string $id,
        #[MapRequestPayload] SaveDeliveryZoneRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
    ): JsonResponse {
        $zone = $em->find(DeliveryZone::class, $id);
        if ($zone === null || $zone->getProducer() !== $user->getProducerProfile()) {
            return $this->json(['error' => 'Zone de livraison introuvable.'], 404);
        }

        if ($request->radiusKm === null && $request->polygon === null) {
            return $this->json(['error' => 'Fournissez un rayon (radiusKm) ou un polygone (polygon).'], 422);
        }

        $this->applyRequest($zone, $request);
        $em->flush();

        return $this->json($this->describe($zone));
    }

    #[Route('/api/producer/delivery-zones/{id}', methods: ['DELETE'])]
    public function deleteDeliveryZone(string $id, #[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $zone = $em->find(DeliveryZone::class, $id);
        if ($zone === null || $zone->getProducer() !== $user->getProducerProfile()) {
            return $this->json(['error' => 'Zone de livraison introuvable.'], 404);
        }

        $em->remove($zone);
        $em->flush();

        return $this->json(null, 204);
    }

    private function applyRequest(DeliveryZone $zone, SaveDeliveryZoneRequest $request): void
    {
        $zone->setRadiusKm($request->radiusKm !== null ? (string) $request->radiusKm : null);
        $zone->setRules($request->rules);

        if ($request->polygon !== null) {
            $zone->setZone($this->toPolygonEwkt($request->polygon));
        }
    }

    /**
     * @param array<int, array{0: float, 1: float}> $polygon
     */
    private function toPolygonEwkt(array $polygon): string
    {
        $coords = array_map(static fn (array $point) => sprintf('%F %F', $point[0], $point[1]), $polygon);
        // * PostGIS exige un anneau fermé (premier point = dernier) -- on referme automatiquement
        // * plutôt que d'exiger que l'appelant y pense (l'API accepte un simple contour).
        if ($coords[0] !== $coords[array_key_last($coords)]) {
            $coords[] = $coords[0];
        }

        return sprintf('SRID=4326;POLYGON((%s))', implode(', ', $coords));
    }

    /**
     * @return array{id: string, radiusKm: string|null, rules: array<string, mixed>|null}
     */
    private function describe(DeliveryZone $zone): array
    {
        return [
            'id' => $zone->getId()->toRfc4122(),
            'radiusKm' => $zone->getRadiusKm(),
            'rules' => $zone->getRules(),
        ];
    }
}
