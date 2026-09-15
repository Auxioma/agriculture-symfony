<?php

namespace App\Controller\Api;

use App\Entity\Billing\Subscription;
use App\Entity\Identity\User;
use App\Enum\SubscriptionStatus;
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
 */

final class ProducerStatisticsController extends AbstractController
{
    #[Route('/api/producer/statistics', methods: ['GET'])]
    public function getStatistics(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => 'Profil producteur introuvable.'], 404);
        }

        $connection = $em->getConnection();
        $producerId = $producer->getId()->toRfc4122();

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
}
