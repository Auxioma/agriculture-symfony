<?php

namespace App\Controller\Api;

use App\Entity\Billing\Coupon;
use App\Entity\Billing\CouponRedemption;
use App\Entity\Billing\Invoice;
use App\Entity\Billing\Payment;
use App\Entity\Billing\PlanPrice;
use App\Entity\Billing\Subscription;
use App\Entity\Billing\WebhookEvent;
use App\Entity\Catalog\Currency;
use App\Entity\Producer\ProducerProfile;
use App\Enum\SubscriptionStatus;
use App\Service\Audit\AuditLogger;
use App\Service\Notification\NotificationService;
use App\Service\Payment\PaymentGatewayInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Event;
use Stripe\Exception\ExceptionInterface as StripeException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeObject;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class StripeWebhookController extends AbstractController
{
    public function __construct(
        #[Autowire(env: 'STRIPE_WEBHOOK_SECRET')]
        private readonly string $webhookSecret,
        private readonly PaymentGatewayInterface $paymentGateway,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/webhooks/stripe', methods: ['POST'])]
    public function handle(Request $request, EntityManagerInterface $em, NotificationService $notificationService): JsonResponse
    {
        try {
            $event = Webhook::constructEvent($request->getContent(), $request->headers->get('Stripe-Signature', ''), $this->webhookSecret);
        } catch (\UnexpectedValueException|SignatureVerificationException) {
            return $this->json(['error' => 'Signature invalide.'], 400);
        }

        // ! Idempotence : providerEventId est UNIQUE en base. Un événement déjà traité avec succès n'est
        // ! jamais rejoué (Stripe peut renvoyer le même événement plusieurs fois). Un événement resté en
        // ! échec, en revanche, est retenté -- voir plus bas pourquoi.
        $webhookEvent = $em->getRepository(WebhookEvent::class)->findOneBy(['providerEventId' => $event->id]);
        if ($webhookEvent !== null && $webhookEvent->getStatus() === 'processed') {
            return $this->json(null, 200);
        }

        $handled = match ($event->type) {
            'customer.subscription.created' => $this->handleSubscriptionCreated($event, $em, $notificationService),
            'customer.subscription.updated' => $this->handleSubscriptionUpdated($event, $em),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($event, $em),
            'invoice.payment_failed' => $this->handleInvoicePaymentFailed($event, $em, $notificationService),
            'invoice.paid' => $this->handleInvoicePaid($event, $em),
            default => true,
        };

        $webhookEvent ??= new WebhookEvent();
        $webhookEvent->setProviderEventId($event->id);
        $webhookEvent->setEventType($event->type);
        $webhookEvent->setPayload($event->toArray());
        $webhookEvent->setStatus($handled ? 'processed' : 'failed');
        $webhookEvent->setReceivedAt($webhookEvent->getReceivedAt() ?? new \DateTimeImmutable());
        if ($handled) {
            $webhookEvent->setProcessedAt(new \DateTimeImmutable());
        }
        $em->persist($webhookEvent);
        try {
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            // * Deux paiements du même producteur traités en même temps : le contrôle "déjà abonné" a laissé passer les deux
            // * et l'index unique (un seul abonnement en vigueur) a refusé le second. Rien n'est enregistré, Stripe réessaie et
            // * le réessai tombe sur le cas du doublon (cancelDuplicate).
            return $this->json(['error' => 'Conflit à l\'enregistrement, nouvelle tentative attendue.'], 409);
        }

        // ! Cahier devops : "Webhooks sécurisés, idempotence, logs, alertes échec paiement" et "Webhooks
        // ! paiement -- échecs répétés -- Haute" supposent que Stripe puisse effectivement réessayer. On ne
        // ! renvoie donc pas 200 quand la cible (Subscription) n'existe pas encore -- typiquement quand
        // ! invoice.paid arrive avant customer.subscription.created, Stripe ne garantissant pas l'ordre de
        // ! livraison des webhooks (confirmé en test manuel). Stripe réessaiera avec un backoff croissant.
        return $handled ? $this->json(null, 200) : $this->json(['error' => 'Cible introuvable, nouvelle tentative attendue.'], 409);
    }

    // * customer.subscription.created (pas checkout.session.completed) : l'objet Subscription Stripe
    // * porte déjà metadata.producer_id (cf. subscription_data.metadata dans StripeGateway) ET le prix
    // * souscrit -- pas besoin de recorréler via la session ou d'expand des line_items.
    // * Retourne toujours true : producer_id absent ou planPrice inconnu sont des problèmes permanents
    // * (mauvaise config, prix jamais synchronisé) -- retenter ne les résoudra jamais, contrairement au cas
    // * "cible pas encore créée" des trois autres handlers.
    private function handleSubscriptionCreated(Event $event, EntityManagerInterface $em, NotificationService $notificationService): bool
    {
        $stripeSubscription = $event->data->object;
        $producerId = $stripeSubscription->metadata['producer_id'] ?? null;
        if ($producerId === null) {
            return true;
        }

        $producer = $em->find(ProducerProfile::class, $producerId);
        $item = $stripeSubscription->items->data[0] ?? null;
        $priceId = $item->price->id ?? null;
        $planPrice = $priceId !== null ? $em->getRepository(PlanPrice::class)->findOneBy(['providerPriceId' => $priceId]) : null;
        if ($producer === null || $planPrice === null || $item === null) {
            return true;
        }

        // * Stripe peut renvoyer la création sous un autre identifiant d'événement : déjà enregistrée, rien à refaire.
        if ($em->getRepository(Subscription::class)->findOneBy(['providerSubscriptionId' => $stripeSubscription->id]) !== null) {
            return true;
        }

        // * Le producteur est déjà abonné : ce paiement est un doublon (deux sessions de paiement ouvertes, payées toutes les deux).
        $kept = $em->getRepository(Subscription::class)->findOneBy([
            'producer' => $producer,
            'status' => [SubscriptionStatus::Active, SubscriptionStatus::Trialing, SubscriptionStatus::PastDue],
        ]);
        if ($kept !== null) {
            return $this->cancelDuplicate($stripeSubscription, $item->current_period_start, $item->current_period_end, $producer, $planPrice, $kept, $em, $notificationService);
        }

        $subscription = new Subscription();
        $subscription->setProducer($producer);
        $subscription->setPlanPrice($planPrice);
        $subscription->setStatus(self::LOCAL_STATUSES[$stripeSubscription->status ?? ''] ?? SubscriptionStatus::Active);
        // ! Depuis l'API version 2026-02-25.clover, current_period_start/end n'existent plus sur l'objet
        // ! Subscription lui-même mais sur chaque subscription_item (confirmé via un vrai payload webhook
        // ! reçu en test manuel -- une souscription peut avoir plusieurs lignes facturées séparément).
        $subscription->setCurrentPeriodStart((new \DateTimeImmutable())->setTimestamp($item->current_period_start));
        $subscription->setCurrentPeriodEnd((new \DateTimeImmutable())->setTimestamp($item->current_period_end));
        $subscription->setProviderSubscriptionId($stripeSubscription->id);
        $em->persist($subscription);

        // * coupon_id (posé par SubscriptionController::checkout() dans subscription_data.metadata) : n'existe
        // * que si un coupon valide a été appliqué à cette session de paiement. Une CouponRedemption n'est donc
        // * créée que pour un coupon réellement utilisé sur un abonnement effectivement payé, jamais pour une
        // * session de checkout abandonnée avant paiement (Stripe n'émet alors jamais cet événement).
        $couponId = $stripeSubscription->metadata['coupon_id'] ?? null;
        $coupon = $couponId !== null ? $em->find(Coupon::class, $couponId) : null;
        if ($coupon !== null) {
            $redemption = new CouponRedemption();
            $redemption->setCoupon($coupon);
            $redemption->setProducer($producer);
            $redemption->setSubscription($subscription);
            $redemption->setRedeemedAt(new \DateTimeImmutable());
            $em->persist($redemption);
        }

        return true;
    }

    // * Le premier abonnement reste. Le second est résilié tout de suite chez Stripe (plus aucun prélèvement) et gardé ici en
    // * "cancelled" : les événements suivants de ce doublon (invoice.paid, deleted) retrouvent leur cible au lieu d'être
    // * réessayés pendant des jours, et sa facture payée apparaît dans l'admin pour le rembourser. Le remboursement reste manuel
    // * (décision financière) : l'audit et le log critique servent d'alerte. Pas de CouponRedemption, ce coupon n'a servi à rien.
    // * Retourne false (Stripe réessaie) si Stripe est injoignable : rien n'est enregistré avant la résiliation.
    private function cancelDuplicate(StripeObject $stripeSubscription, int $periodStart, int $periodEnd, ProducerProfile $producer, PlanPrice $planPrice, Subscription $kept, EntityManagerInterface $em, NotificationService $notificationService): bool
    {
        try {
            $this->paymentGateway->cancelSubscriptionNow($stripeSubscription->id);
        } catch (InvalidRequestException) {
            // * déjà résilié chez Stripe (réessai après un échec d'enregistrement) : on garde quand même la trace du doublon
        } catch (StripeException $e) {
            $this->logger->error('Abonnement Stripe en double non résilié, nouvelle tentative attendue.', [
                'stripe_subscription' => $stripeSubscription->id,
                'producer' => $producer->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $duplicate = new Subscription();
        $duplicate->setProducer($producer);
        $duplicate->setPlanPrice($planPrice);
        $duplicate->setStatus(SubscriptionStatus::Cancelled);
        $duplicate->setCurrentPeriodStart((new \DateTimeImmutable())->setTimestamp($periodStart));
        $duplicate->setCurrentPeriodEnd((new \DateTimeImmutable())->setTimestamp($periodEnd));
        $duplicate->setProviderSubscriptionId($stripeSubscription->id);
        $em->persist($duplicate);

        $details = [
            'producer' => $producer->getId()->toRfc4122(),
            'kept_subscription' => $kept->getId()->toRfc4122(),
            'cancelled_stripe_subscription' => $stripeSubscription->id,
        ];
        $this->auditLogger->log('subscription_duplicate_cancelled', 'billing', 'subscriptions', $duplicate->getId()->toRfc4122(), null, $details);
        $this->logger->critical('Abonnement Stripe en double résilié : le paiement est à rembourser à la main.', $details);
        $notificationService->notify(
            $producer->getOwner(),
            'subscription_duplicate_cancelled',
            'Abonnement en double annulé',
            'Vous avez souscrit deux fois : le second abonnement a été annulé. Notre équipe va vérifier le paiement en double et vous le rembourser.'
        );

        return true;
    }

    // * Statut Stripe -> statut local (MLD : trialing, active, past_due, cancelled, expired). past_due coupe l'accès aux réponses
    // * (billing.producer_has_feature ne garde que trialing et active) : c'est le "statut limité" du cahier fonctionnel après
    // * un échec de paiement, levé dès que Stripe repasse l'abonnement en active. "unpaid" (relances épuisées, abonnement
    // * gardé) reste limité aussi. "incomplete" (premier paiement pas encore fait) et "paused" ne changent rien.
    private const LOCAL_STATUSES = [
        'active' => SubscriptionStatus::Active,
        'trialing' => SubscriptionStatus::Trialing,
        'past_due' => SubscriptionStatus::PastDue,
        'unpaid' => SubscriptionStatus::PastDue,
        'canceled' => SubscriptionStatus::Cancelled,
        'incomplete_expired' => SubscriptionStatus::Expired,
    ];

    // * Retourne false (retry) si la Subscription locale n'existe pas encore : customer.subscription.created
    // * n'a peut-être pas fini d'être traité, Stripe ne garantissant pas l'ordre de livraison des webhooks.
    private function handleSubscriptionUpdated(Event $event, EntityManagerInterface $em): bool
    {
        $stripeSubscription = $event->data->object;
        $subscription = $em->getRepository(Subscription::class)->findOneBy(['providerSubscriptionId' => $stripeSubscription->id]);
        if ($subscription === null) {
            return false;
        }

        $item = $stripeSubscription->items->data[0] ?? null;
        if ($item !== null) {
            $subscription->setCurrentPeriodStart((new \DateTimeImmutable())->setTimestamp($item->current_period_start));
            $subscription->setCurrentPeriodEnd((new \DateTimeImmutable())->setTimestamp($item->current_period_end));
        }
        $subscription->setCancelAtPeriodEnd((bool) $stripeSubscription->cancel_at_period_end);
        $status = self::LOCAL_STATUSES[$stripeSubscription->status ?? ''] ?? null;
        // * annulé ou expiré est définitif chez Stripe : un événement en retard ne doit pas le remettre en vigueur (la base
        // * refuserait d'ailleurs un second abonnement en vigueur pour ce producteur)
        $final = [SubscriptionStatus::Cancelled, SubscriptionStatus::Expired];
        if ($status !== null && !\in_array($subscription->getStatus(), $final, true)) {
            $subscription->setStatus($status);
        }

        $priceId = $item->price->id ?? null;
        $planPrice = $priceId !== null ? $em->getRepository(PlanPrice::class)->findOneBy(['providerPriceId' => $priceId]) : null;
        if ($planPrice !== null) {
            $subscription->setPlanPrice($planPrice);
        }

        return true;
    }

    private function handleSubscriptionDeleted(Event $event, EntityManagerInterface $em): bool
    {
        $stripeSubscription = $event->data->object;
        $subscription = $em->getRepository(Subscription::class)->findOneBy(['providerSubscriptionId' => $stripeSubscription->id]);
        if ($subscription === null) {
            return false;
        }
        $subscription->setStatus(SubscriptionStatus::Cancelled);

        return true;
    }

    // * Alimente réellement GET /api/subscription/invoices (round 1), qui sinon resterait toujours vide.
    // * Retourne false (retry) uniquement si providerSubscriptionId est présent mais pas encore trouvable en
    // * local -- confirmé en test manuel : invoice.paid est arrivé une seconde avant customer.subscription.created.
    // * Si providerSubscriptionId est absent (facture hors abonnement), pas d'erreur transitoire à corriger.
    private function handleInvoicePaid(Event $event, EntityManagerInterface $em): bool
    {
        $stripeInvoice = $event->data->object;
        // ! Depuis l'API version 2026-02-25.clover, invoice.subscription n'existe plus au niveau racine :
        // ! la référence est nichée sous parent.subscription_details.subscription (confirmé via un vrai
        // ! payload webhook reçu en test manuel).
        $providerSubscriptionId = $stripeInvoice->parent->subscription_details->subscription ?? null;
        if ($providerSubscriptionId === null) {
            return true;
        }

        $subscription = $em->getRepository(Subscription::class)->findOneBy(['providerSubscriptionId' => $providerSubscriptionId]);
        if ($subscription === null) {
            return false;
        }

        // * une facture dont un paiement avait échoué existe déjà (handleInvoicePaymentFailed) : on la passe à "paid"
        $invoice = $this->findOrCreateInvoice($stripeInvoice, $subscription, $em);
        $invoice->setAmount($this->money($stripeInvoice->amount_paid ?? null));
        $invoice->setCurrency($this->currency($stripeInvoice, $em));
        $invoice->setStatus('paid');
        $invoice->setInvoiceUrl($stripeInvoice->hosted_invoice_url ?? null);
        $invoice->setPaidAt(new \DateTimeImmutable());
        $this->recordPayment($invoice, 'succeeded', null, $em);

        return true;
    }

    // ! Stripe exprime les montants dans la plus petite unité monétaire (centimes pour EUR/USD).
    private function money(?int $minorUnits): ?string
    {
        return $minorUnits === null ? null : number_format($minorUnits / 100, 2, '.', '');
    }

    private function currency(\Stripe\StripeObject $stripeInvoice, EntityManagerInterface $em): ?Currency
    {
        $code = $stripeInvoice->currency ?? null;

        return $code === null ? null : $em->find(Currency::class, strtoupper($code));
    }

    private function findOrCreateInvoice(\Stripe\StripeObject $stripeInvoice, Subscription $subscription, EntityManagerInterface $em): Invoice
    {
        $invoice = $em->getRepository(Invoice::class)->findOneBy(['providerInvoiceId' => $stripeInvoice->id]);
        if ($invoice === null) {
            $invoice = new Invoice();
            $invoice->setSubscription($subscription);
            $invoice->setProviderInvoiceId($stripeInvoice->id);
            $em->persist($invoice);
        }

        return $invoice;
    }

    // * Une ligne par tentative de paiement (MLD : billing.payments, une facture peut en avoir plusieurs), c'est ce que lit
    // * l'écran admin "Paiements". L'identifiant de paiement Stripe n'est pas dans l'événement facture (provider_payment_id
    // * reste vide), les doublons sont déjà évités par l'idempotence des événements (WebhookEvent).
    private function recordPayment(Invoice $invoice, string $status, ?string $failureReason, EntityManagerInterface $em): void
    {
        $payment = new Payment();
        $payment->setInvoice($invoice);
        $payment->setAmount($invoice->getAmount());
        $payment->setCurrency($invoice->getCurrency());
        $payment->setStatus($status);
        $payment->setFailureReason($failureReason);
        $em->persist($payment);
    }

    private function handleInvoicePaymentFailed(Event $event, EntityManagerInterface $em, NotificationService $notificationService): bool
    {
        $stripeInvoice = $event->data->object;
        $providerSubscriptionId = $stripeInvoice->parent->subscription_details->subscription ?? null;
        if ($providerSubscriptionId === null) {
            return true;
        }

        $subscription = $em->getRepository(Subscription::class)->findOneBy(['providerSubscriptionId' => $providerSubscriptionId]);
        if ($subscription === null) {
            return false;
        }

        // * Stripe garde la facture ouverte et réessaie ; ici elle passe à "failed" (Échec, admin Factures) avec une ligne de
        // * paiement refusé. Le statut "past_due" de l'abonnement vient, lui, de customer.subscription.updated.
        $invoice = $this->findOrCreateInvoice($stripeInvoice, $subscription, $em);
        $invoice->setAmount($this->money($stripeInvoice->amount_due ?? null));
        $invoice->setCurrency($this->currency($stripeInvoice, $em));
        $invoice->setStatus('failed');
        $invoice->setInvoiceUrl($stripeInvoice->hosted_invoice_url ?? null);
        $this->recordPayment($invoice, 'failed', sprintf('Paiement refusé (tentative %d).', $stripeInvoice->attempt_count ?? 1), $em);

        $notificationService->notify(
            $subscription->getProducer()->getOwner(),
            'payment_failed',
            'Paiement échoué',
            'Le paiement de votre abonnement a échoué. Merci de vérifier votre moyen de paiement.'
        );

        return true;
    }
}