<?php

namespace App\Controller\Api;

use App\Entity\Billing\Subscription;
use App\Entity\Identity\User;
use App\Enum\SubscriptionStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Statistiques producteur (cahier fonctionnel, dashboard producteur : "Vues profil, demandes
 * reçues, réponses, conversations, taux de réponse, ROI estimé"). Tout est calculé à la volée en
 * SQL, comme DashboardController/le reporting admin -- analytics.producer_daily_metrics
 * (profileViews) est la seule donnée réellement stockée (incrémentée par
 * ProducerController::getProducer()), le reste vient directement des tables transactionnelles.
 *
 * Réservé aux offres qui comprennent les statistiques (cahier fonctionnel, abonnements : "statistiques de
 * base" pour Pro, "statistiques avancées" pour Premium). Le cahier ne dit pas ce que les avancées ajoutent :
 * les deux niveaux reçoivent pour l'instant les mêmes chiffres.
 */

final class ProducerStatisticsController extends AbstractController
{
    // * Le graphique des demandes reçues couvre ce mois-ci et les 5 précédents.
    private const MONTHS = 6;

    #[Route('/api/producer/statistics', methods: ['GET'])]
    public function getStatistics(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => 'Profil producteur introuvable.'], 404);
        }

        $connection = $em->getConnection();
        $producerId = $producer->getId()->toRfc4122();

        if (!$this->planIncludesStatistics($connection, $producerId)) {
            return $this->json(['error' => 'Les statistiques ne sont pas comprises dans votre abonnement.'], 403);
        }

        $profileViews = (int) $connection->fetchOne(
            'SELECT COALESCE(SUM(profile_views), 0) FROM analytics.producer_daily_metrics WHERE producer_id = :id',
            ['id' => $producerId]
        );

        $row = $connection->fetchAssociative(
            "SELECT
                (SELECT COUNT(*) FROM matching.request_matches WHERE producer_id = :id) AS matches,
                (SELECT COUNT(*) FROM matching.producer_replies WHERE producer_id = :id AND status != 'draft') AS replies,
                (SELECT COUNT(*) FROM messaging.conversations WHERE producer_id = :id) AS conversations",
            ['id' => $producerId]
        );

        $requestsReceived = (int) $row['matches'];
        $replies = (int) $row['replies'];
        $conversations = (int) $row['conversations'];
        $responseRate = $requestsReceived > 0 ? round(100 * $replies / $requestsReceived, 1) : null;

        // ! "ROI estimé" du cahier ne peut pas être un vrai retour financier : la plateforme ne
        // ! connaît jamais le montant d'une vente ("la transaction reste organisée directement entre
        // ! le client et le producteur", cahier fonctionnel) et DealOutcome (schéma présent mais non
        // ! branché) n'a même pas de champ montant. On calcule à la place un indicateur de coût
        // ! d'opportunité honnête, basé sur le coût réel de l'abonnement actif -- cohérent avec le
        // ! principe fondateur du cahier ("payer un abonnement pour accéder à des opportunités
        // ! commerciales qualifiées").
        $subscription = $em->getRepository(Subscription::class)->findOneBy([
            'producer' => $producer,
            'status' => SubscriptionStatus::Active,
        ]);
        $subscriptionCost = $subscription !== null ? (float) $subscription->getPlanPrice()->getAmount() : null;

        return $this->json([
            'profileViews' => $profileViews,
            'requestsReceived' => $requestsReceived,
            'replies' => $replies,
            'conversations' => $conversations,
            'responseRate' => $responseRate,
            'requestsByMonth' => $this->requestsByMonth($connection, $producerId),
            'estimatedRoi' => [
                'currency' => $subscription?->getPlanPrice()->getCurrency()?->getCode(),
                'subscriptionCost' => $subscriptionCost,
                'costPerRequestReceived' => ($subscriptionCost !== null && $requestsReceived > 0)
                    ? round($subscriptionCost / $requestsReceived, 2) : null,
                'costPerConversationOpened' => ($subscriptionCost !== null && $conversations > 0)
                    ? round($subscriptionCost / $conversations, 2) : null,
            ],
        ]);
    }

    /**
     * Même règle d'abonnement que billing.producer_has_feature (essai ou actif, dans sa période), mais "stats" vaut
     * "basic" ou "advanced" dans les offres et non un booléen : la fonction SQL ne peut pas le lire.
     */
    private function planIncludesStatistics(Connection $connection, string $producerId): bool
    {
        return (bool) $connection->fetchOne(
            "SELECT EXISTS (
                SELECT 1
                FROM billing.subscriptions s
                JOIN billing.plan_prices pp ON pp.id = s.plan_price_id
                JOIN billing.subscription_plans sp ON sp.id = pp.plan_id
                WHERE s.producer_id = :id
                AND s.status IN ('trialing', 'active')
                AND now() BETWEEN s.current_period_start AND s.current_period_end
                AND COALESCE(sp.features ->> 'stats', 'false') NOT IN ('false', '')
            )",
            ['id' => $producerId]
        );
    }

    /**
     * Demandes mises en relation avec ce producteur, par mois, du plus ancien au mois en cours. Un mois sans demande
     * compte 0 : le graphique a toujours ses 6 colonnes.
     *
     * @return list<array{month: string, count: int}>
     */
    private function requestsByMonth(Connection $connection, string $producerId): array
    {
        $first = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('first day of this month')->setTime(0, 0)->modify('-'.(self::MONTHS - 1).' months');

        $counts = $connection->fetchAllKeyValue(
            "SELECT to_char(date_trunc('month', created_at AT TIME ZONE 'UTC'), 'YYYY-MM') AS month, COUNT(*)
             FROM matching.request_matches
             WHERE producer_id = :id AND created_at >= :since
             GROUP BY 1",
            ['id' => $producerId, 'since' => $first->format('Y-m-d H:i:sP')]
        );

        $months = [];
        for ($i = 0; $i < self::MONTHS; ++$i) {
            $month = $first->modify("+$i months")->format('Y-m');
            $months[] = ['month' => $month, 'count' => (int) ($counts[$month] ?? 0)];
        }

        return $months;
    }
}
