<?php

namespace App\Controller\Api;

use App\Dto\Subscription\ChangePlanRequest;
use App\Dto\Subscription\CheckoutRequest;
use App\Entity\Billing\Invoice;
use App\Entity\Billing\PlanPrice;
use App\Entity\Billing\Subscription;
use App\Entity\Billing\SubscriptionPlan;
use App\Entity\Identity\User;
use App\Enum\SubscriptionStatus;
use App\Service\Payment\PaymentGatewayInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Gestion de la facturation et des abonnements producteurs.
 */
final class SubscriptionController extends AbstractController
{
    /**
     * Liste des offres d'abonnement actives et leurs tarifs.
     */
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

    /**
     * État de l'abonnement en cours du producteur.
     */
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

    /**
     * Historique des factures du producteur.
     */
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

    /**
     * Demande d'annulation d'abonnement en fin de période.
     */
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

        // Marque l'intention d'annulation ; le statut final sera mis à jour via webhook
        $subscription->setCancelAtPeriodEnd(true);
        $em->flush();

        return $this->json(null, 200);
    }

    /**
     * Initie une session de paiement Stripe/Checkout pour un nouvel abonnement.
     */
    #[Route('/api/subscription/checkout', methods: ['POST'])]
    public function checkout(
        #[MapRequestPayload] CheckoutRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        PaymentGatewayInterface $paymentGateway,
    ): JsonResponse {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $planPrice = $em->find(PlanPrice::class, $request->planPriceId);
        if ($planPrice === null || !$planPrice->isActive() || $planPrice->getProviderPriceId() === null) {
            return $this->json(['error' => 'Offre inconnue.'], 422);
        }

        $existing = $em->getRepository(Subscription::class)->findOneBy(['producer' => $producer, 'status' => SubscriptionStatus::Active]);
        if ($existing !== null) {
            return $this->json(['error' => 'Un abonnement actif existe déjà -- utilisez change-plan.'], 409);
        }

        $checkoutUrl = $paymentGateway->createCheckoutSession(
            priceId: $planPrice->getProviderPriceId(),
            customerEmail: $user->getEmail(),
            metadata: ['producer_id' => $producer->getId()->toRfc4122()],
            successUrl: 'https://app.trouvemoi.com/abonnement/succes?session_id={CHECKOUT_SESSION_ID}',
            cancelUrl: 'https://app.trouvemoi.com/abonnement/annule',
        );

        return $this->json(['checkoutUrl' => $checkoutUrl], 201);
    }

    /**
     * Demande le passage à une autre formule d'abonnement.
     */
    #[Route('/api/subscription/change-plan', methods: ['POST'])]
    public function changePlan(
        #[MapRequestPayload] ChangePlanRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        PaymentGatewayInterface $paymentGateway,
    ): JsonResponse {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $subscription = $em->getRepository(Subscription::class)->findOneBy(['producer' => $producer, 'status' => SubscriptionStatus::Active]);
        if ($subscription === null) {
            return $this->json(['error' => 'Aucun abonnement actif.'], 404);
        }
        if ($subscription->getProviderSubscriptionId() === null) {
            return $this->json(['error' => 'Abonnement non synchronisé avec Stripe.'], 409);
        }

        $newPlanPrice = $em->find(PlanPrice::class, $request->planPriceId);
        if ($newPlanPrice === null || !$newPlanPrice->isActive() || $newPlanPrice->getProviderPriceId() === null) {
            return $this->json(['error' => 'Offre inconnue.'], 422);
        }

        $paymentGateway->updateSubscriptionPrice($subscription->getProviderSubscriptionId(), $newPlanPrice->getProviderPriceId());

        // La MAJ de l'entité locale est déléguée au webhook Stripe (customer.subscription.updated)
        return $this->json(null, 202);
    }
}