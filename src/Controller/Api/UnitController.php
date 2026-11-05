<?php

namespace App\Controller\Api;

use App\Entity\Catalog\Unit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Unités de mesure (kg, litre, caisse, palette...) : l'étape "Détails" du tunnel de demande envoie unitId (UUID).
 * À vérifier : la règle d'accès dans security.yaml (comme /api/categories, l'endpoint peut être public).
 */
final class UnitController extends AbstractController
{
    #[Route('/api/units', methods: ['GET'])]
    public function listUnits(EntityManagerInterface $em): JsonResponse
    {
        $units = $em->getRepository(Unit::class)->findBy([], ['code' => 'ASC']);

        return $this->json(array_map(
            static fn (Unit $u) => [
                'id' => $u->getId()->toRfc4122(),
                'code' => $u->getCode(),
                'label' => $u->getLabel(),
                'unitType' => $u->getUnitType(),
            ],
            $units
        ));
    }
}