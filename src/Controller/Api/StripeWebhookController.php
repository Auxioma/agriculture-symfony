<?php

namespace App\Controller\Api;

use App\Entity\Billing\Invoice;
use App\Entity\Billing\PlanPrice;
use App\Entity\Billing\Subscription;
use App\Entity\Billing\WebhookEvent;
use App\Entity\Producer\ProducerProfile;
use App\Enum\SubscriptionStatus;
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
    public function handle(Request $request, EntityManagerInterface $em): JsonResponse
    {
        try {
            $event = Webhook::constructEvent($request->getContent(), $request->headers->get('Stripe-Signature', ''), $this->webhookSecret);
        } catch (\UnexpectedValueException|SignatureVerificationException) {
            return $this->json(['error' => 'Signature invalide.'], 400);
        }

        // ! Idempotence : providerEventId est UNIQUE en base -- Stripe peut renvoyer le même événement
        // ! plusieurs fois (retry sur timeout de notre côté), on ne le traite qu'une seule fois.
        if ($em->getRepository(WebhookEvent::class)->findOneBy(['providerEventId' => $event->id]) !== null) {
            return $this->json(null, 200);
        }

        match ($event->type) {
            'customer.subscription.created' => $this->handleSubscriptionCreated($event, $em),
            'customer.subscription.updated' => $this->handleSubscriptionUpdated($event, $em),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($event, $em),
            'invoice.paid' => $this->handleInvoicePaid($event, $em),
            default => null,
        };

        $webhookEvent = new WebhookEvent();
        $webhookEvent->setProviderEventId($event->id);
        $webhookEvent->setEventType($event->type);
        $webhookEvent->setPayload($event->toArray());
        $webhookEvent->setStatus('processed');
        $webhookEvent->setReceivedAt(new \DateTimeImmutable());
        $webhookEvent->setProcessedAt(new \DateTimeImmutable());
        $em->persist($webhookEvent);
        $em->flush();

        return $this->json(null, 200);
    }

    // * customer.subscription.created (pas checkout.session.completed) : l'objet Subscription Stripe
    // * porte déjà metadata.producer_id (cf. subscription_data.metadata dans StripeGateway) ET le prix
    // * souscrit -- pas besoin de recorréler via la session ou d'expand des line_items.
    private function handleSubscriptionCreated(Event $event, EntityManagerInterface $em): void
    {
        $stripeSubscription = $event->data->object;
        $producerId = $stripeSubscription->metadata['producer_id'] ?? null;
        if ($producerId === null) {
            return;
        }

        $producer = $em->find(ProducerProfile::class, $producerId);
        $priceId = $stripeSubscription->items->data[0]->price->id ?? null;
        $planPrice = $priceId !== null ? $em->getRepository(PlanPrice::class)->findOneBy(['providerPriceId' => $priceId]) : null;
        if ($producer === null || $planPrice === null) {
            return;
        }

        $subscription = new Subscription();
        $subscription->setProducer($producer);
        $subscription->setPlanPrice($planPrice);
        $subscription->setStatus(SubscriptionStatus::Active);
        $subscription->setCurrentPeriodStart((new \DateTimeImmutable())->setTimestamp($stripeSubscription->current_period_start));
        $subscription->setCurrentPeriodEnd((new \DateTimeImmutable())->setTimestamp($stripeSubscription->current_period_end));
        $subscription->setProviderSubscriptionId($stripeSubscription->id);
        $em->persist($subscription);
    }

    private function handleSubscriptionUpdated(Event $event, EntityManagerInterface $em): void
    {
        $stripeSubscription = $event->data->object;
        $subscription = $em->getRepository(Subscription::class)->findOneBy(['providerSubscriptionId' => $stripeSubscription->id]);
        if ($subscription === null) {
            return;
        }

        $subscription->setCurrentPeriodStart((new \DateTimeImmutable())->setTimestamp($stripeSubscription->current_period_start));
        $subscription->setCurrentPeriodEnd((new \DateTimeImmutable())->setTimestamp($stripeSubscription->current_period_end));
        $subscription->setCancelAtPeriodEnd((bool) $stripeSubscription->cancel_at_period_end);

        $priceId = $stripeSubscription->items->data[0]->price->id ?? null;
        $planPrice = $priceId !== null ? $em->getRepository(PlanPrice::class)->findOneBy(['providerPriceId' => $priceId]) : null;
        if ($planPrice !== null) {
            $subscription->setPlanPrice($planPrice);
        }
    }

    private function handleSubscriptionDeleted(Event $event, EntityManagerInterface $em): void
    {
        $stripeSubscription = $event->data->object;
        $subscription = $em->getRepository(Subscription::class)->findOneBy(['providerSubscriptionId' => $stripeSubscription->id]);
        $subscription?->setStatus(SubscriptionStatus::Cancelled);
    }

    // * Alimente réellement GET /api/subscription/invoices (round 1), qui sinon resterait toujours vide.
    private function handleInvoicePaid(Event $event, EntityManagerInterface $em): void
    {
        $stripeInvoice = $event->data->object;
        $subscription = $em->getRepository(Subscription::class)->findOneBy(['providerSubscriptionId' => $stripeInvoice->subscription]);
        if ($subscription === null) {
            return;
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
    }
    
}