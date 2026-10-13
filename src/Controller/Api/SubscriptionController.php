<?php

namespace App\Controller\Api;

use App\Dto\Subscription\ChangePlanRequest;
use App\Dto\Subscription\CheckoutRequest;
use App\Entity\Billing\Coupon;
use App\Entity\Billing\CouponRedemption;
use App\Entity\Billing\Invoice;
use App\Entity\Billing\PlanPrice;
use App\Entity\Billing\Subscription;
use App\Entity\Billing\SubscriptionPlan;
use App\Entity\Identity\User;
use App\Entity\Producer\ProducerProfile;
use App\Enum\SubscriptionStatus;
use App\Service\Matching\MonthlyRequestCounter;
use App\Service\Payment\PaymentGatewayInterface;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Exception\ExceptionInterface as StripeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
    public function getCurrentSubscription(#[CurrentUser] User $user, EntityManagerInterface $em, MonthlyRequestCounter $monthlyRequests): JsonResponse
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
            'planPriceId' => $subscription->getPlanPrice()->getId()->toRfc4122(),
            'planCode' => $subscription->getPlanPrice()->getPlan()->getCode(),
            'planName' => $subscription->getPlanPrice()->getPlan()->getName(),
            'billingCycle' => $subscription->getPlanPrice()->getBillingCycle()->value,
            'amount' => $subscription->getPlanPrice()->getAmount(),
            'currencyCode' => $subscription->getPlanPrice()->getCurrency()?->getCode(),
            'status' => $subscription->getStatus()->value,
            'currentPeriodStart' => $subscription->getCurrentPeriodStart()->format(DATE_ATOM),
            'currentPeriodEnd' => $subscription->getCurrentPeriodEnd()->format(DATE_ATOM),
            'cancelAtPeriodEnd' => $subscription->isCancelAtPeriodEnd(),
            // * quota du plan (null = illimité) et demandes reçues ce mois-ci : "24/30 demandes utilisées"
            'requestsThisMonth' => $monthlyRequests->count($producer),
            'requestsQuota' => $subscription->getPlanPrice()->getPlan()->getLimits()['requests_per_month'] ?? null,
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
    public function cancelSubscription(#[CurrentUser] User $user, EntityManagerInterface $em, PaymentGatewayInterface $paymentGateway): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $subscription = $this->findInForce($producer, $em);
        if ($subscription === null) {
            return $this->json(['error' => 'Aucun abonnement actif à annuler.'], 404);
        }

        // * Stripe doit arrêter de facturer à la fin de la période (un abonnement jamais synchronisé avec Stripe,
        // * sans identifiant, n'a rien à y annuler). Le statut final arrive ensuite par webhook.
        if ($subscription->getProviderSubscriptionId() !== null) {
            try {
                $paymentGateway->cancelSubscriptionAtPeriodEnd($subscription->getProviderSubscriptionId());
            } catch (StripeException) {
                return $this->paymentUnavailable();
            }
        }
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
        #[Autowire(env: 'FRONTEND_URL')] string $frontendUrl,
    ): JsonResponse {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $planPrice = $em->find(PlanPrice::class, $request->planPriceId);
        if ($planPrice === null || !$planPrice->isActive() || $planPrice->getProviderPriceId() === null) {
            return $this->json(['error' => 'Offre inconnue.'], 422);
        }

        $existing = $this->findInForce($producer, $em);
        if ($existing !== null) {
            return $this->json(['error' => $this->existingSubscriptionMessage($existing)], 409);
        }

        $metadata = ['producer_id' => $producer->getId()->toRfc4122()];
        $couponId = null;
        if ($request->couponCode !== null) {
            $coupon = $this->resolveValidCoupon($request->couponCode, $em);
            if ($coupon instanceof JsonResponse) {
                return $coupon;
            }
            // ! coupon_id (l'id local, pas providerCouponId) : c'est ce que
            // ! StripeWebhookController::handleSubscriptionCreated() relit pour créer la CouponRedemption --
            // ! il n'a lui-même aucune raison d'appeler Stripe pour retrouver le Coupon local.
            $metadata['coupon_id'] = $coupon->getId()->toRfc4122();
            $couponId = $coupon->getProviderCouponId();
        }

        try {
            $checkoutUrl = $paymentGateway->createCheckoutSession(
                priceId: $planPrice->getProviderPriceId(),
                customerEmail: $user->getEmail(),
                metadata: $metadata,
                successUrl: $frontendUrl.'/producer/subscription?checkout=success',
                cancelUrl: $frontendUrl.'/producer/subscription?checkout=cancelled',
                couponId: $couponId,
            );
        } catch (StripeException) {
            return $this->paymentUnavailable();
        }

        return $this->json(['checkoutUrl' => $checkoutUrl], 201);
    }

    // * un abonnement qui donne encore des droits : en cours, d'essai ou en retard de paiement (statut limité)
    private function findInForce(ProducerProfile $producer, EntityManagerInterface $em): ?Subscription
    {
        return $em->getRepository(Subscription::class)->findOneBy(
            ['producer' => $producer, 'status' => [SubscriptionStatus::Active, SubscriptionStatus::Trialing, SubscriptionStatus::PastDue]],
            ['createdAt' => 'DESC']
        );
    }

    private function existingSubscriptionMessage(Subscription $subscription): string
    {
        return $subscription->getStatus() === SubscriptionStatus::PastDue
            ? 'Votre dernier paiement a échoué : mettez à jour votre moyen de paiement.'
            : 'Un abonnement actif existe déjà -- utilisez change-plan.';
    }

    private function paymentUnavailable(): JsonResponse
    {
        return $this->json(['error' => 'Le service de paiement est momentanément indisponible.'], 502);
    }

    // * discountPercent n'est jamais lu ici : c'est purement informatif côté Symfony (voir Coupon), la
    // * réduction réelle vient du Coupon Stripe référencé par providerCouponId, appliqué par StripeGateway.
    private function resolveValidCoupon(string $code, EntityManagerInterface $em): Coupon|JsonResponse
    {
        $coupon = $em->getRepository(Coupon::class)->findOneBy(['code' => $code]);
        if ($coupon === null) {
            return $this->json(['error' => 'Coupon inconnu.'], 422);
        }

        $now = new \DateTimeImmutable();
        if (($coupon->getValidFrom() !== null && $coupon->getValidFrom() > $now)
            || ($coupon->getValidUntil() !== null && $coupon->getValidUntil() < $now)) {
            return $this->json(['error' => 'Ce coupon n\'est plus valide.'], 422);
        }

        if ($coupon->getMaxRedemptions() !== null) {
            $redemptions = $em->getRepository(CouponRedemption::class)->count(['coupon' => $coupon]);
            if ($redemptions >= $coupon->getMaxRedemptions()) {
                return $this->json(['error' => 'Ce coupon a atteint son nombre maximal d\'utilisations.'], 422);
            }
        }

        if ($coupon->getProviderCouponId() === null) {
            return $this->json(['error' => 'Ce coupon n\'est pas synchronisé avec Stripe.'], 422);
        }

        return $coupon;
    }

    /**
     * Demande le passage à une autre formule d'abonnement (cahier fonctionnel : "l'upgrade prend effet immédiatement,
     * le downgrade à la prochaine échéance"). Un plan plus bas dans la liste (position) est un downgrade ; changer
     * seulement de cycle (mensuel / annuel) ou monter de plan est immédiat. La réponse dit quand ça prend effet.
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

        $subscription = $this->findInForce($producer, $em);
        if ($subscription === null) {
            return $this->json(['error' => 'Aucun abonnement actif.'], 404);
        }
        if ($subscription->getStatus() === SubscriptionStatus::PastDue) {
            return $this->json(['error' => $this->existingSubscriptionMessage($subscription)], 409);
        }
        if ($subscription->getProviderSubscriptionId() === null) {
            return $this->json(['error' => 'Abonnement non synchronisé avec Stripe.'], 409);
        }

        $newPlanPrice = $em->find(PlanPrice::class, $request->planPriceId);
        if ($newPlanPrice === null || !$newPlanPrice->isActive() || $newPlanPrice->getProviderPriceId() === null) {
            return $this->json(['error' => 'Offre inconnue.'], 422);
        }

        if ($newPlanPrice === $subscription->getPlanPrice()) {
            return $this->json(['error' => 'Vous avez déjà cette offre.'], 422);
        }

        $atPeriodEnd = ($newPlanPrice->getPlan()->getPosition() ?? 0) < ($subscription->getPlanPrice()->getPlan()->getPosition() ?? 0);
        try {
            $paymentGateway->updateSubscriptionPrice($subscription->getProviderSubscriptionId(), $newPlanPrice->getProviderPriceId(), $atPeriodEnd);
        } catch (StripeException) {
            return $this->paymentUnavailable();
        }

        // La MAJ de l'entité locale est déléguée au webhook Stripe (customer.subscription.updated)
        return $this->json([
            'effect' => $atPeriodEnd ? 'next_renewal' : 'immediate',
            'effectiveAt' => $atPeriodEnd ? $subscription->getCurrentPeriodEnd()->format(DATE_ATOM) : null,
        ], 202);
    }
}