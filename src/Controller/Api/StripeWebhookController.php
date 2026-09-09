<?php

namespace App\Controller\Api;

use App\Entity\Billing\Invoice;
use App\Entity\Billing\PlanPrice;
use App\Entity\Billing\Subscription;
use App\Entity\Billing\WebhookEvent;
use App\Entity\Producer\ProducerProfile;
use App\Enum\SubscriptionStatus;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
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
            'customer.subscription.created' => $this->handleSubscriptionCreated($event, $em),
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
        $em->flush();

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
    private function handleSubscriptionCreated(Event $event, EntityManagerInterface $em): bool
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

        $subscription = new Subscription();
        $subscription->setProducer($producer);
        $subscription->setPlanPrice($planPrice);
        $subscription->setStatus(SubscriptionStatus::Active);
        // ! Depuis l'API version 2026-02-25.clover, current_period_start/end n'existent plus sur l'objet
        // ! Subscription lui-même mais sur chaque subscription_item (confirmé via un vrai payload webhook
        // ! reçu en test manuel -- une souscription peut avoir plusieurs lignes facturées séparément).
        $subscription->setCurrentPeriodStart((new \DateTimeImmutable())->setTimestamp($item->current_period_start));
        $subscription->setCurrentPeriodEnd((new \DateTimeImmutable())->setTimestamp($item->current_period_end));
        $subscription->setProviderSubscriptionId($stripeSubscription->id);
        $em->persist($subscription);

        return true;
    }

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

        $invoice = new Invoice();
        $invoice->setSubscription($subscription);
        // ! Stripe exprime les montants dans la plus petite unité monétaire (centimes pour EUR/USD).
        $invoice->setAmount(number_format($stripeInvoice->amount_paid / 100, 2, '.', ''));
        $invoice->setStatus('paid');
        $invoice->setInvoiceUrl($stripeInvoice->hosted_invoice_url);
        $invoice->setProviderInvoiceId($stripeInvoice->id);
        $invoice->setPaidAt(new \DateTimeImmutable());
        $em->persist($invoice);

        return true;
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

        $notificationService->notify(
            $subscription->getProducer()->getOwner(),
            'payment_failed',
            'Paiement échoué',
            'Le paiement de votre abonnement a échoué. Merci de vérifier votre moyen de paiement.'
        );

        return true;
    }
}