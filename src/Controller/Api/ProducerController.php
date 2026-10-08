<?php

namespace App\Controller\Api;

use App\Entity\Producer\DeliveryZone;
use App\Entity\Producer\OpeningHour;
use App\Entity\Producer\ProducerLabel;
use App\Entity\Producer\ProducerProfile;
use App\Entity\Trust\Review;
use App\Enum\ReviewStatus;
use App\Service\Validation\UuidFormat;
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
    // * Bornes des paramètres publics : évitent des requêtes SQL démesurées (trop de sous-requêtes EXISTS,
    // * rayon couvrant la planète, LIMIT énorme).
    private const MAX_LABELS_FILTER = 10;
    private const MAX_RADIUS_KM = 500;
    private const MAX_FEATURED = 50;
    // * Fenêtre des tris « popularité » et « réactivité » : métriques des 30 derniers jours.
    private const METRICS_WINDOW_DAYS = 30;

    #[Route('/api/producers', methods: ['GET'])]
    public function listProducers(Request $request, EntityManagerInterface $em): JsonResponse
    {
        if (($error = $this->validateListQuery($request)) !== null) {
            return $this->badRequest($error);
        }

        $conditions = ['pp.is_active = true'];
        $params = [];

        // * Les filtres qui portent sur UN MÊME produit du producteur (produit demandé, catégorie, de saison) sont
        // * réunis dans un seul EXISTS. Séparés, « Fruits » + « de saison » accepterait un producteur qui a un
        // * fruit hors saison ET un légume de saison, ce qui serait faux.
        $productConditions = [];

        if (($productId = $request->query->get('productId')) !== null) {
            $productConditions[] = 'prp.product_id = :productId';
            $params['productId'] = $productId;
        }

        if (($categoryId = $request->query->get('categoryId')) !== null) {
            // * Inclut les sous-catégories, à n'importe quelle profondeur.
            $productConditions[] = <<<'SQL'
                prod.category_id IN (
                    WITH RECURSIVE category_tree (id) AS (
                        SELECT id FROM catalog.categories WHERE id = :categoryId
                        UNION
                        SELECT c.id
                        FROM catalog.categories c
                        JOIN category_tree t ON c.parent_id = t.id
                        WHERE c.is_active = true
                    )
                    SELECT id FROM category_tree
                )
            SQL;
            $params['categoryId'] = $categoryId;
        }

        if (filter_var($request->query->get('seasonal'), FILTER_VALIDATE_BOOLEAN)) {
            // * Produit de saison = le mois en cours est compris entre le mois de début et de fin de saison.
            // * Une saison peut chevaucher deux années (début 11, fin 2 : novembre à février) : dans ce cas le mois
            // * doit être >= début OU <= fin. Produit sans dates de saison : jamais « de saison » (comme le front).
            $productConditions[] = <<<'SQL'
                prod.season_start_month IS NOT NULL
                AND prod.season_end_month IS NOT NULL
                AND (
                    (prod.season_start_month <= prod.season_end_month
                        AND CAST(:month AS integer) BETWEEN prod.season_start_month AND prod.season_end_month)
                    OR (prod.season_start_month > prod.season_end_month
                        AND (CAST(:month AS integer) >= prod.season_start_month OR CAST(:month AS integer) <= prod.season_end_month))
                )
            SQL;
            // * Fuseau explicite : sinon le mois dépend de la configuration du serveur (UTC la nuit du 31 au 1er).
            $params['month'] = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('n');
        }

        if ($productConditions !== []) {
            $conditions[] = 'EXISTS (SELECT 1 FROM producer.producer_products prp'
                .' JOIN catalog.products prod ON prod.id = prp.product_id'
                .' WHERE prp.producer_id = pp.id AND prp.is_active = true AND '.implode(' AND ', $productConditions).')';
        }

        $labelCodes = array_values(array_filter(explode(',', (string) $request->query->get('labels', ''))));
        foreach ($labelCodes as $i => $code) {
            $conditions[] = "
                EXISTS (SELECT 1 FROM producer.producer_labels pl
                    JOIN catalog.labels l ON l.id = pl.label_id
                    WHERE pl.producer_id = pp.id AND l.code = :label$i
                    AND pl.verified_at IS NOT NULL AND (pl.expires_at IS NULL OR pl.expires_at > now()))

            ";
            $params["label$i"] = $code;
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

        if (($minRating = $request->query->get('minRating')) !== null) {
            $conditions[] = 'reviews.average_rating >= :minRating';
            $params['minRating'] = (float) $minRating;
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

        // * Cahier fonctionnel : tris de la liste. La valeur reçue sert uniquement de CLÉ dans cette liste blanche,
        // * jamais de morceau de SQL ; une valeur inconnue retombe sur "relevance".
        // *  - relevance      : producteurs vérifiés d'abord, puis mieux notés, puis plus d'avis, puis nom.
        // *  - distance       : seulement si une position est fournie (sinon "relevance").
        // *  - popularity     : vues du profil sur les 30 derniers jours (analytics.producer_daily_metrics).
        // *  - responsiveness : taux de réponse moyen sur 30 jours (même table).
        // *  - newest         : compte propriétaire le plus récent (identity.users.created_at : producer_profiles
        // *                     n'a pas de date de création).
        $sort = $request->query->get('sort');
        $orderByBySort = [
            'relevance' => "(pp.verification_status = 'verified') DESC, reviews.average_rating DESC NULLS LAST, reviews.review_count DESC NULLS LAST, pp.farm_name ASC",
            'distance' => 'distance_km ASC NULLS LAST, pp.farm_name ASC',
            'popularity' => 'metrics.profile_views DESC NULLS LAST, pp.farm_name ASC',
            'responsiveness' => 'metrics.response_rate DESC NULLS LAST, pp.farm_name ASC',
            'newest' => 'owner.created_at DESC, pp.farm_name ASC',
        ];
        if (!is_string($sort) || !isset($orderByBySort[$sort]) || ($sort === 'distance' && !$hasLocation)) {
            $sort = 'relevance';
        }
        $orderBy = $orderByBySort[$sort];

        // * Jointure sur les métriques seulement pour les tris qui en ont besoin (inutile sinon).
        $metricsJoin = in_array($sort, ['popularity', 'responsiveness'], true)
            ? 'LEFT JOIN (
                SELECT producer_id, SUM(profile_views) AS profile_views, AVG(response_rate) AS response_rate
                FROM analytics.producer_daily_metrics
                WHERE metric_date >= CURRENT_DATE - '.self::METRICS_WINDOW_DAYS.'
                GROUP BY producer_id
            ) metrics ON metrics.producer_id = pp.id'
            : '';
        // * Jointure sur le compte propriétaire seulement pour le tri « newest » : c'est lui qui porte created_at.
        $ownerJoin = $sort === 'newest' ? 'JOIN identity.users owner ON owner.id = pp.owner_user_id' : '';
        $distanceSelect = $hasLocation ? 'ST_Distance(pp.location, ST_GeographyFromText(:point)) / 1000.0' : 'NULL';

        $sql = "
            SELECT pp.id, pp.farm_name, pp.slug, pp.city, pp.country_code, pp.verification_status,
                $distanceSelect AS distance_km,
                reviews.average_rating, reviews.review_count,
                (
                    SELECT pm.file_url FROM producer.producer_media pm
                    WHERE pm.producer_id = pp.id AND pm.is_public = true
                    ORDER BY pm.position ASC NULLS LAST LIMIT 1
                ) AS photo_url
            FROM producer.producer_profiles pp
            LEFT JOIN (
                SELECT producer_id, AVG(rating)::numeric(10,2) AS average_rating, COUNT(*) AS review_count
                FROM trust.reviews
                WHERE status = 'published' AND rating IS NOT NULL
                GROUP BY producer_id
            ) reviews ON reviews.producer_id = pp.id
            $metricsJoin
            $ownerJoin
            WHERE ".implode(' AND ', $conditions)."
            ORDER BY $orderBy
        ";

        $rows = $em->getConnection()->fetchAllAssociative($sql, $params);
        $labelsByProducerId = $this->findVerifiedLabelsByProducerId($em, array_column($rows, 'id'));

        return $this->json(array_map(
            static fn (array $row) => [
                'id' => $row['id'],
                'farmName' => $row['farm_name'],
                'slug' => $row['slug'],
                'city' => $row['city'],
                'countryCode' => $row['country_code'],
                'verificationStatus' => $row['verification_status'],
                'distanceKm' => $row['distance_km'] !== null ? round((float) $row['distance_km'], 2) : null,
                'photoUrl' => $row['photo_url'],
                'averageRating' => $row['average_rating'] !== null ? round((float) $row['average_rating'], 1) : null,
                'reviewCount' => (int) ($row['review_count'] ?? 0),
                'labels' => $labelsByProducerId[$row['id']] ?? [],
            ],
            $rows
        ));
    }

    /** Message d'erreur (HTTP 400) si un paramètre de listProducers est invalide, sinon null. */
    private function validateListQuery(Request $request): ?string
    {
        foreach (['productId', 'categoryId'] as $name) {
            $value = $request->query->get($name);
            if ($value !== null && !UuidFormat::isValid($value)) {
                return sprintf('Paramètre "%s" invalide : un UUID est attendu.', $name);
            }
        }

        $minRating = $request->query->get('minRating');
        if ($minRating !== null && (!is_numeric($minRating) || (float) $minRating < 0 || (float) $minRating > 5)) {
            return 'Paramètre "minRating" invalide : un nombre entre 0 et 5 est attendu.';
        }

        $labelCodes = array_filter(explode(',', (string) $request->query->get('labels', '')));
        if (count($labelCodes) > self::MAX_LABELS_FILTER) {
            return sprintf('Trop de labels : %d maximum.', self::MAX_LABELS_FILTER);
        }

        return $this->validateLocation($request);
    }

    /** Valide latitude/longitude (fournies ensemble) et radiusKm ; sans position, le rayon est ignoré. */
    private function validateLocation(Request $request): ?string
    {
        $latitude = $request->query->get('latitude');
        $longitude = $request->query->get('longitude');

        if (($latitude === null) !== ($longitude === null)) {
            return 'Les paramètres "latitude" et "longitude" doivent être fournis ensemble.';
        }

        if ($latitude !== null) {
            if (!is_numeric($latitude) || (float) $latitude < -90 || (float) $latitude > 90) {
                return 'Paramètre "latitude" invalide : un nombre entre -90 et 90 est attendu.';
            }
            if (!is_numeric($longitude) || (float) $longitude < -180 || (float) $longitude > 180) {
                return 'Paramètre "longitude" invalide : un nombre entre -180 et 180 est attendu.';
            }
        }

        $radiusKm = $request->query->get('radiusKm');
        if ($radiusKm !== null && (!is_numeric($radiusKm) || (float) $radiusKm <= 0 || (float) $radiusKm > self::MAX_RADIUS_KM)) {
            return sprintf('Paramètre "radiusKm" invalide : un nombre entre 0 et %d est attendu.', self::MAX_RADIUS_KM);
        }

        return null;
    }

    private function badRequest(string $message): JsonResponse
    {
        return $this->json(['error' => $message], 400);
    }

    // * filter_var/FILTER_VALIDATE_BOOLEAN sur un paramètre de query string ("true"/"false"/"1"/"0") pour
    // * l'injecter comme littéral SQL sûr (true/false ne sont pas ambigus pour Postgres, contrairement au
    // * binding d'un bool PHP via PDO qui varie selon le driver).
    private function toBoolLiteral(string $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    }

    /**
     * Cahier fonctionnel, accueil : "Producteurs a la une avec badges" -- les N producteurs vérifiés avec la
     * meilleure moyenne d'avis publiés (pas de sélection manuelle/admin pour l'instant, cf. échange avec le
     * client : calcul automatique plutôt qu'un flag "featured"). Un producteur sans aucun avis publié
     * n'apparaît jamais ici (INNER JOIN), ce qui est volontaire : une moyenne sur 0 avis n'a pas de sens.
     */
    #[Route('/api/producers/featured', methods: ['GET'])]
    public function listFeaturedProducers(Request $request, EntityManagerInterface $em): JsonResponse
    {
        // * (int) cast avant interpolation dans le SQL plus bas : LIMIT ne peut pas être un paramètre lié
        // * nommé classique (Postgres n'infère pas son type via le protocole étendu), donc on caste nous-même
        // * plutôt que de risquer une valeur non numérique dans la requête.
        $limit = min(self::MAX_FEATURED, max(1, (int) ($request->query->get('limit') ?? 3)));

        if (($error = $this->validateLocation($request)) !== null) {
            return $this->badRequest($error);
        }

        $latitude = $request->query->get('latitude');
        $longitude = $request->query->get('longitude');
        $hasLocation = $latitude !== null && $longitude !== null;
        $distanceSelect = $hasLocation ? 'ST_Distance(pp.location, ST_GeographyFromText(:point)) / 1000.0' : 'NULL';
        $params = [];
        if ($hasLocation) {
            $params['point'] = sprintf('SRID=4326;POINT(%F %F)', $longitude, $latitude);
        }

        $sql = "
            SELECT pp.id, pp.farm_name, pp.slug, pp.city, pp.country_code,
                $distanceSelect AS distance_km,
                reviews.average_rating, reviews.review_count,
                (
                    SELECT pm.file_url FROM producer.producer_media pm
                    WHERE pm.producer_id = pp.id AND pm.is_public = true
                    ORDER BY pm.position ASC NULLS LAST LIMIT 1
                ) AS photo_url
            FROM producer.producer_profiles pp
            INNER JOIN (
                SELECT producer_id, AVG(rating)::numeric(10,2) AS average_rating, COUNT(*) AS review_count
                FROM trust.reviews
                WHERE status = 'published' AND rating IS NOT NULL
                GROUP BY producer_id
            ) reviews ON reviews.producer_id = pp.id
            WHERE pp.is_active = true AND pp.verification_status = 'verified'
            ORDER BY reviews.average_rating DESC, reviews.review_count DESC
            LIMIT $limit
        ";

        $rows = $em->getConnection()->fetchAllAssociative($sql, $params);

        $producerIds = array_column($rows, 'id');
        $labelsByProducerId = $this->findVerifiedLabelsByProducerId($em, $producerIds);

        return $this->json(array_map(
            static fn (array $row) => [
                'id' => $row['id'],
                'farmName' => $row['farm_name'],
                'slug' => $row['slug'],
                'city' => $row['city'],
                'countryCode' => $row['country_code'],
                'distanceKm' => $row['distance_km'] !== null ? round((float) $row['distance_km'], 1) : null,
                'averageRating' => round((float) $row['average_rating'], 1),
                'reviewCount' => (int) $row['review_count'],
                'photoUrl' => $row['photo_url'],
                'labels' => $labelsByProducerId[$row['id']] ?? [],
            ],
            $rows
        ));
    }

    /**
     * @param list<string> $producerIds
     *
     * @return array<string, list<array{code: string, name: string}>>
     */
    private function findVerifiedLabelsByProducerId(EntityManagerInterface $em, array $producerIds): array
    {
        if ($producerIds === []) {
            return [];
        }

        // * Même filtre verifiedAt/expiresAt que getProducer() : un label revendiqué mais pas encore
        // * validé ne doit pas apparaître comme badge sur une carte "producteur à la une".
        $producerLabels = $em->createQueryBuilder()
            ->select('pl')
            ->from(ProducerLabel::class, 'pl')
            ->where('IDENTITY(pl.producer) IN (:producerIds)')
            ->setParameter('producerIds', $producerIds)
            ->getQuery()
            ->getResult();

        $labelsByProducerId = [];
        foreach ($producerLabels as $producerLabel) {
            if ($producerLabel->getVerifiedAt() === null) {
                continue;
            }
            if ($producerLabel->getExpiresAt() !== null && $producerLabel->getExpiresAt() < new \DateTimeImmutable()) {
                continue;
            }

            $producerId = $producerLabel->getProducer()->getId()->toRfc4122();
            $labelsByProducerId[$producerId][] = [
                'code' => $producerLabel->getLabel()->getCode(),
                'name' => $producerLabel->getLabel()->getName(),
            ];
        }

        return $labelsByProducerId;
    }

    #[Route('/api/producers/{id}', methods: ['GET'])]
    public function getProducer(string $id, EntityManagerInterface $em): JsonResponse
    {
        $producer = UuidFormat::isValid($id) ? $em->find(ProducerProfile::class, $id) : null;
        // * Un producteur désactivé n'est pas listé, et son lien direct ne doit pas non plus être consultable.
        if ($producer === null || !$producer->isActive()) {
            return $this->json(['error' => 'Producteur introuvable.'], 404);
        }

        // * Incrémente le compteur du jour dans analytics.producer_daily_metrics (module "Statistiques"
        // * du dashboard producteur, voir ProducerStatisticsController) -- upsert atomique plutôt qu'un
        // * find()+persist() Doctrine, pour éviter une condition de course si deux visites arrivent en
        // * même temps (deux requêtes concurrentes verraient sinon la même valeur de départ et une
        // * incrémentation se perdrait).
        $em->getConnection()->executeStatement(
            'INSERT INTO analytics.producer_daily_metrics (producer_id, metric_date, profile_views)
             VALUES (:producerId, CURRENT_DATE, 1)
             ON CONFLICT (producer_id, metric_date)
             DO UPDATE SET profile_views = COALESCE(analytics.producer_daily_metrics.profile_views, 0) + 1',
            ['producerId' => $producer->getId()->toRfc4122()]
        );

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
            // * Cahier fonctionnel, fiche producteur publique : "Modes de retrait, livraison, horaires,
            // * zones couvertes". Le tracé du polygone lui-même n'est pas renvoyé (pas de conversion
            // * GeoJSON ici) -- seul le rayon simple, suffisant pour l'affichage "Livraison possible
            // * dans un rayon de Xkm" du cahier ; une carte du polygone resterait à faire séparément.
            'deliveryZones' => array_map(
                static fn (DeliveryZone $z) => ['radiusKm' => $z->getRadiusKm(), 'rules' => $z->getRules()],
                $producer->getDeliveryZones()->toArray()
            ),
            'openingHours' => array_map(
                static fn (OpeningHour $h) => [
                    'weekday' => $h->getWeekday(),
                    'opensAt' => $h->getOpensAt()?->format('H:i'),
                    'closesAt' => $h->getClosesAt()?->format('H:i'),
                    'isClosed' => $h->isClosed(),
                ],
                $producer->getOpeningHours()->toArray()
            ),
            // * Cahier fonctionnel, fiche producteur publique : "Badges : vérifié, bio, local, HVE,
            // * AOP/AOC ou labels locaux". Seuls les labels réellement vérifiés (verifiedAt posé par
            // * VerificationDocumentCrudController) et pas expirés sont affichés -- un label
            // * simplement revendiqué mais pas encore validé ne doit pas apparaître comme un badge.
            'labels' => array_values(array_filter(array_map(
                static function (ProducerLabel $l) {
                    if ($l->getVerifiedAt() === null) {
                        return null;
                    }
                    if ($l->getExpiresAt() !== null && $l->getExpiresAt() < new \DateTimeImmutable()) {
                        return null;
                    }

                    return ['code' => $l->getLabel()->getCode(), 'name' => $l->getLabel()->getName()];
                },
                $producer->getLabels()->toArray()
            ))),
        ]);
    }

    #[Route('/api/producers/{id}/reviews', methods: ['GET'])]
    public function listProducerReviews(string $id, EntityManagerInterface $em): JsonResponse
    {
        $producer = UuidFormat::isValid($id) ? $em->find(ProducerProfile::class, $id) : null;
        if ($producer === null || !$producer->isActive()) {
            return $this->json(['error' => 'Producteur introuvable.'], 404);
        }

        // * Seuls les avis Published sont exposés publiquement -- voir ReviewStatus et
        // * ReviewCrudController (modération back-office).
        $reviews = $em->getRepository(Review::class)->findBy(
            ['producer' => $producer, 'status' => ReviewStatus::Published],
            ['createdAt' => 'DESC']
        );

        $ratings = array_filter(array_map(static fn (Review $r) => $r->getRating(), $reviews), static fn ($r) => $r !== null);

        return $this->json([
            'averageRating' => $ratings !== [] ? round(array_sum($ratings) / count($ratings), 1) : null,
            'count' => count($reviews),
            'reviews' => array_map(
                static fn (Review $r) => [
                    'id' => $r->getId()->toRfc4122(),
                    'rating' => $r->getRating(),
                    'comment' => $r->getComment(),
                    'producerResponse' => $r->getProducerResponse(),
                    'createdAt' => $r->getCreatedAt()->format(DATE_ATOM),
                ],
                $reviews
            ),
        ]);
    }
}