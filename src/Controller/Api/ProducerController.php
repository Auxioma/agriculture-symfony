<?php

namespace App\Controller\Api;

use App\Entity\Producer\ProducerProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lecture publique de l'annuaire des producteurs (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf).
 * Routes non authentifiées (voir security.yaml, access_control ^/api/producers) : liste et fiche détail.
 * Les routes réservées au producteur propriétaire (profil, produits, photos) suivront dans un contrôleur séparé.
 */
final class ProducerController extends AbstractController
{
    #[Route('/api/producers', methods: ['GET'])]
    public function listProducers(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $conditions = ['pp.is_active = true'];
        $params = [];

        if (($productId = $request->query->get('productId')) !== null) {
            $conditions[] = 'EXISTS (SELECT 1 FROM producer.producer_products prp WHERE prp.producer_id = pp.id AND prp.product_id = :productId AND prp.is_active = true)';
            $params['productId'] = $productId;
        }

        if (($categoryId = $request->query->get('categoryId')) !== null) {
            $conditions[] = 'EXISTS (SELECT 1 FROM producer.producer_products prp JOIN catalog.products prod ON prod.id = prp.product_id WHERE prp.producer_id = pp.id AND prod.category_id = :categoryId AND prp.is_active = true)';
            $params['categoryId'] = $categoryId;
        }

        if (($labelCode = $request->query->get('label')) !== null) {
            $conditions[] = 'EXISTS (SELECT 1 FROM producer.producer_labels pl JOIN catalog.labels l ON l.id = pl.label_id WHERE pl.producer_id = pp.id AND l.code = :labelCode)';
            $params['labelCode'] = $labelCode;
        }

        if (($pickup = $request->query->get('pickupAvailable')) !== null) {
            $conditions[] = 'EXISTS (SELECT 1 FROM producer.producer_settings ps WHERE ps.producer_id = pp.id AND ps.pickup_enabled = '.($this->toBoolLiteral($pickup)).')';
        }

        if (($delivery = $request->query->get('deliveryAvailable')) !== null) {
            $conditions[] = 'EXISTS (SELECT 1 FROM producer.producer_settings ps WHERE ps.producer_id = pp.id AND ps.delivery_enabled = '.($this->toBoolLiteral($delivery)).')';
        }

        if (filter_var($request->query->get('verifiedOnly'), FILTER_VALIDATE_BOOLEAN)) {
            $conditions[] = "pp.verification_status = 'verified'";
        }

        $latitude = $request->query->get('latitude');
        $longitude = $request->query->get('longitude');
        $hasLocation = $latitude !== null && $longitude !== null;
        if ($hasLocation) {
            $radiusKm = (float) ($request->query->get('radiusKm') ?? 50);
            $conditions[] = 'pp.location IS NOT NULL AND ST_DWithin(pp.location, ST_GeographyFromText(:point), :radiusMeters)';
            $params['point'] = sprintf('SRID=4326;POINT(%F %F)', $longitude, $latitude);
            $params['radiusMeters'] = $radiusKm * 1000;
        }

        // * Cahier fonctionnel : tri par distance (seulement si une position est fournie) ou par nom --
        // * "pertinence", "popularité" et "réactivité" demanderaient des métriques pas encore branchées à cette route.
        $sort = $request->query->get('sort');
        $orderBy = ($sort === 'distance' && $hasLocation) ? 'distance_km ASC NULLS LAST' : 'pp.farm_name ASC';
        $distanceSelect = $hasLocation ? 'ST_Distance(pp.location, ST_GeographyFromText(:point)) / 1000.0' : 'NULL';

        $sql = "
            SELECT pp.id, pp.farm_name, pp.slug, pp.city, pp.country_code, pp.verification_status,
                $distanceSelect AS distance_km
            FROM producer.producer_profiles pp
            WHERE ".implode(' AND ', $conditions)."
            ORDER BY $orderBy
        ";

        $rows = $em->getConnection()->fetchAllAssociative($sql, $params);

        return $this->json(array_map(
            static fn (array $row) => [
                'id' => $row['id'],
                'farmName' => $row['farm_name'],
                'slug' => $row['slug'],
                'city' => $row['city'],
                'countryCode' => $row['country_code'],
                'verificationStatus' => $row['verification_status'],
                'distanceKm' => $row['distance_km'] !== null ? round((float) $row['distance_km'], 2) : null,
            ],
            $rows
        ));
    }

    // * filter_var/FILTER_VALIDATE_BOOLEAN sur un paramètre de query string ("true"/"false"/"1"/"0") pour
    // * l'injecter comme littéral SQL sûr (true/false ne sont pas ambigus pour Postgres, contrairement au
    // * binding d'un bool PHP via PDO qui varie selon le driver).
    private function toBoolLiteral(string $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    }

    #[Route('/api/producers/{id}', methods: ['GET'])]
    public function getProducer(string $id, EntityManagerInterface $em): JsonResponse
    {
        $producer = $em->find(ProducerProfile::class, $id);
        // * Un producteur désactivé n'est pas listé, et son lien direct ne doit pas non plus être consultable.
        if ($producer === null || !$producer->isActive()) {
            return $this->json(['error' => 'Producteur introuvable.'], 404);
        }

        // ! Pas de coordonnées GPS précises exposées ici : ProducerProfile::$addressVisibility est prévu pour
        // ! contrôler la précision affichée publiquement (ville seule vs adresse complète), mais cette logique
        // ! n'est pas encore implémentée. Se limiter à city/countryCode évite d'exposer une position exacte
        // ! par défaut tant que la règle de confidentialité n'existe pas.
        return $this->json([
            'id' => $producer->getId()->toRfc4122(),
            'farmName' => $producer->getFarmName(),
            'slug' => $producer->getSlug(),
            'description' => $producer->getDescription(),
            'story' => $producer->getStory(),
            'city' => $producer->getCity(),
            'countryCode' => $producer->getCountry()?->getCode(),
            'verificationStatus' => $producer->getVerificationStatus()->value,
        ]);
    }
}