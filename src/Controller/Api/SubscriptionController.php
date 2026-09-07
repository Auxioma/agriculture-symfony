<?php

namespace App\Controller\Api;

use App\Entity\Billing\Invoice;
use App\Entity\Billing\PlanPrice;
use App\Entity\Billing\Subscription;
use App\Entity\Billing\SubscriptionPlan;
use App\Entity\Identity\User;
use App\Enum\SubscriptionStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class SubscriptionController extends AbstractController
{
    #[Route('/api/subscription/plans', methods: ['GET'])]
    public function listPlans(EntityManagerInterface $em): JsonResponse
    {
        $plans = $em->getRepository(SubscriptionPlan::class)->findBy(['isActive' => true], ['position' => 'ASC']);

        return $this->json(array_map(
            static fn (SubscriptionPlan $p) => [
                'id' => $p->getId()->toRfc4122(),
                'code' => $p->getCode(),
                'name' => $p->getName(),
                'description' => $p->getDescription(),
                'features' => $p->getFeatures(),
                'prices' => array_values(array_map(
                    static fn (PlanPrice $price) => [
                        'id' => $price->getId()->toRfc4122(),
                        'billingCycle' => $price->getBillingCycle()->value,
                        'amount' => $price->getAmount(),
                        'currencyCode' => $price->getCurrency()?->getCode(),
                    ],
                    array_filter($p->getPrices()->toArray(), static fn (PlanPrice $pr) => $pr->isActive())
                )),
            ],
            $plans
        ));
    }

    #[Route('/api/subscription/current', methods: ['GET'])]
    public function getCurrentSubscription(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $subscription = $em->getRepository(Subscription::class)->findOneBy(
            ['producer' => $producer],
            ['createdAt' => 'DESC']
        );
        if ($subscription === null) {
            return $this->json(null, 204);
        }

        return $this->json([
            'id' => $subscription->getId()->toRfc4122(),
            'planCode' => $subscription->getPlanPrice()->getPlan()->getCode(),
            'planName' => $subscription->getPlanPrice()->getPlan()->getName(),
            'billingCycle' => $subscription->getPlanPrice()->getBillingCycle()->value,
            'status' => $subscription->getStatus()->value,
            'currentPeriodStart' => $subscription->getCurrentPeriodStart()->format(DATE_ATOM),
            'currentPeriodEnd' => $subscription->getCurrentPeriodEnd()->format(DATE_ATOM),
            'cancelAtPeriodEnd' => $subscription->isCancelAtPeriodEnd(),
        ]);
    }

    #[Route('/api/subscription/invoices', methods: ['GET'])]
    public function listInvoices(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $invoices = $em->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->join('i.subscription', 's')
            ->where('s.producer = :producer')
            ->setParameter('producer', $producer)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->json(array_map(
            static fn (Invoice $i) => [
                'id' => $i->getId()->toRfc4122(),
                'amount' => $i->getAmount(),
                'currencyCode' => $i->getCurrency()?->getCode(),
                'status' => $i->getStatus(),
                'invoiceUrl' => $i->getInvoiceUrl(),
                'paidAt' => $i->getPaidAt()?->format(DATE_ATOM),
                'createdAt' => $i->getCreatedAt()->format(DATE_ATOM),
            ],
            $invoices
        ));
    }

    #[Route('/api/subscription/cancel', methods: ['POST'])]
    public function cancelSubscription(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $subscription = $em->getRepository(Subscription::class)->findOneBy([
            'producer' => $producer,
            'status' => SubscriptionStatus::Active,
        ]);
        if ($subscription === null) {
            return $this->json(['error' => 'Aucun abonnement actif à annuler.'], 404);
        }

        // ! Résiliation "douce" uniquement : on marque l'intention, l'abonnement reste actif jusqu'à la fin
        // ! de la période déjà payée. Le passage réel à "cancelled" (et l'appel au prestataire de paiement)
        // ! sera déclenché par un webhook une fois l'intégration paiement décidée -- checkout/change-plan
        // ! sont hors scope de ce round pour la même raison.
        $subscription->setCancelAtPeriodEnd(true);
        $em->flush();

        return $this->json(null, 200);
    }
}