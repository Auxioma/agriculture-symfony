<?php

namespace App\Controller\Api;

use App\Entity\Billing\Subscription;
use App\Entity\Identity\User;
use App\Entity\Matching\RequestMatch;
use App\Entity\Messaging\Conversation;
use App\Entity\Producer\ProducerProfile;
use App\Service\Messaging\UnreadMessageCounter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Vue d'ensemble du dashboard producteur (cahier fonctionnel : "Demandes disponibles, demandes urgentes,
 * messages non lus, abonnement, quota, profil complété"). Un seul appel pour toute la page d'accueil
 * producteur plutôt que cinq (demandes, conversations, abonnement, profil, statistiques). "requests" liste les
 * demandes disponibles, urgentes d'abord, avec un indicateur "urgent" : le front filtre ensuite lui-même.
 *
 * Règles :
 *  - "disponible" = correspondance encore proposée ou débloquée, sur une demande encore ouverte ;
 *  - "urgente" = urgencyLevel > 0 (0 = pas d'urgence) ;
 *  - quota = SubscriptionPlan::$limits['requests_per_month'] (absent = illimité) ;
 *  - profil complété = part des critères PROFILE_CRITERIA remplis.
 */
final class ProducerDashboardController extends AbstractController
{
    private const REQUESTS_LIST_SIZE = 20;

    // * Ordre = ordre d'affichage du texte "Ajoutez vos ... pour être plus visible" côté front.
    private const PROFILE_CRITERIA = ['description', 'photos', 'labels', 'products', 'availability', 'zones'];

    #[Route('/api/producer/dashboard', methods: ['GET'])]
    public function getDashboard(
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        UnreadMessageCounter $unreadCounter,
    ): JsonResponse {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $available = $this->findAvailableMatches($producer, $em);
        $urgent = array_values(array_filter(
            $available,
            static fn (RequestMatch $m) => $m->getRequest()->getUrgencyLevel() > 0
        ));

        $conversations = $em->getRepository(Conversation::class)->findBy(['producer' => $producer]);
        $unreadMessages = array_sum($unreadCounter->countByConversation($conversations, $user));

        $profile = $this->profileCompletion($producer);

        return $this->json([
            'farmName' => $producer->getFarmName(),
            'availableRequests' => \count($available),
            'urgentRequests' => \count($urgent),
            'unreadMessages' => $unreadMessages,
            'requests' => array_map($this->requestItem(...), \array_slice($available, 0, self::REQUESTS_LIST_SIZE)),
            'subscription' => $this->subscriptionSummary($producer, $em),
            'profile' => $profile,
        ]);
    }

    /**
     * @return RequestMatch[] les plus urgentes d'abord, puis les plus récentes
     */
    private function findAvailableMatches(ProducerProfile $producer, EntityManagerInterface $em): array
    {
        return $em->getRepository(RequestMatch::class)->createAvailableQueryBuilder($producer)
            ->orderBy('r.urgencyLevel', 'DESC')
            ->addOrderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<string, mixed>
     */
    private function requestItem(RequestMatch $match): array
    {
        $request = $match->getRequest();

        return [
            'requestId' => $request->getId()->toRfc4122(),
            'product' => $request->getProduct()?->getName() ?? $request->getCustomProduct(),
            'quantity' => $request->getQuantity() !== null ? (float) $request->getQuantity() : null,
            'unit' => $request->getUnit()?->getCode(),
            'budgetMax' => $request->getBudgetMax() !== null ? (float) $request->getBudgetMax() : null,
            'currency' => $request->getCurrency()?->getSymbol() ?? $request->getCurrency()?->getCode(),
            'city' => $request->getCity(),
            'distanceKm' => $match->getDistanceKm() !== null ? (float) $match->getDistanceKm() : null,
            'message' => $request->getMessage(),
            'urgent' => $request->getUrgencyLevel() > 0,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function subscriptionSummary(ProducerProfile $producer, EntityManagerInterface $em): ?array
    {
        $subscription = $em->getRepository(Subscription::class)->findOneBy(['producer' => $producer], ['createdAt' => 'DESC']);
        if ($subscription === null) {
            return null;
        }

        $plan = $subscription->getPlanPrice()->getPlan();
        $monthStart = new \DateTimeImmutable('first day of this month 00:00:00');

        $requestsThisMonth = (int) $em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(RequestMatch::class, 'm')
            ->where('m.producer = :producer')
            ->andWhere('m.createdAt >= :monthStart')
            ->setParameter('producer', $producer)
            ->setParameter('monthStart', $monthStart)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'planName' => $plan->getName(),
            'status' => $subscription->getStatus()->value,
            'requestsThisMonth' => $requestsThisMonth,
            'requestsQuota' => $plan->getLimits()['requests_per_month'] ?? null,
        ];
    }

    /**
     * @return array{completion: int, missing: string[]}
     */
    private function profileCompletion(ProducerProfile $producer): array
    {
        $hasAvailability = false;
        foreach ($producer->getProducts() as $producerProduct) {
            if (!$producerProduct->getProductAvailabilities()->isEmpty()) {
                $hasAvailability = true;
                break;
            }
        }

        $done = [
            'description' => trim((string) $producer->getDescription()) !== '',
            'photos' => !$producer->getProducerMedia()->isEmpty(),
            'labels' => !$producer->getLabels()->isEmpty(),
            'products' => !$producer->getProducts()->isEmpty(),
            'availability' => $hasAvailability,
            'zones' => !$producer->getDeliveryZones()->isEmpty(),
        ];

        $missing = array_values(array_filter(self::PROFILE_CRITERIA, static fn (string $c) => !$done[$c]));

        return [
            'completion' => (int) round(100 * (\count(self::PROFILE_CRITERIA) - \count($missing)) / \count(self::PROFILE_CRITERIA)),
            'missing' => $missing,
        ];
    }
}
