<?php

namespace App\Controller\Api;

use App\Entity\Billing\Subscription;
use App\Entity\Identity\User;
use App\Enum\SubscriptionStatus;
use App\Service\Payment\PaymentGatewayInterface;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Exception\ExceptionInterface as StripeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Cartes bancaires de l'abonnement producteur (cahier fonctionnel, dashboard producteur : "moyen de paiement").
 * Rien n'est stocké chez nous (cahier devops : aucune donnée carte en local) : la liste est lue chez Stripe à chaque
 * appel, la saisie d'une nouvelle carte se fait sur une page Stripe (mode "setup") et les actions passent par
 * PaymentGatewayInterface. Une carte n'est accessible que si elle appartient au client Stripe de l'abonnement.
 */
final class PaymentMethodController extends AbstractController
{
    #[Route('/api/subscription/payment-methods', methods: ['GET'])]
    public function list(#[CurrentUser] User $user, EntityManagerInterface $em, PaymentGatewayInterface $gateway): JsonResponse
    {
        $providerId = $this->findProviderSubscriptionId($user, $em);
        if ($providerId instanceof JsonResponse) {
            return $providerId;
        }

        try {
            return $this->json($gateway->listPaymentMethods($providerId));
        } catch (StripeException) {
            return $this->unavailable();
        }
    }

    // * retour sur la page des cartes après la saisie (?card=added) ou l'abandon (?card=cancelled)
    #[Route('/api/subscription/payment-methods/setup', methods: ['POST'])]
    public function setup(
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        PaymentGatewayInterface $gateway,
        #[Autowire(env: 'FRONTEND_URL')] string $frontendUrl,
    ): JsonResponse {
        $providerId = $this->findProviderSubscriptionId($user, $em);
        if ($providerId instanceof JsonResponse) {
            return $providerId;
        }

        try {
            $url = $gateway->createCardSetupSession(
                $providerId,
                $frontendUrl.'/producer/subscription/payment-methods?card=added',
                $frontendUrl.'/producer/subscription/payment-methods?card=cancelled',
            );
        } catch (StripeException) {
            return $this->unavailable();
        }

        return $this->json(['setupUrl' => $url], 201);
    }

    #[Route('/api/subscription/payment-methods/{id}/default', methods: ['POST'])]
    public function makeDefault(string $id, #[CurrentUser] User $user, EntityManagerInterface $em, PaymentGatewayInterface $gateway): JsonResponse
    {
        return $this->act($id, $user, $em, $gateway, static function (string $providerId, array $card) use ($gateway): void {
            $gateway->setDefaultPaymentMethod($providerId, $card['id']);
        });
    }

    // * la carte par défaut ne se supprime pas (ce serait la seule en cas de carte unique) : on en choisit une autre d'abord
    #[Route('/api/subscription/payment-methods/{id}', methods: ['DELETE'])]
    public function delete(string $id, #[CurrentUser] User $user, EntityManagerInterface $em, PaymentGatewayInterface $gateway): JsonResponse
    {
        return $this->act($id, $user, $em, $gateway, static function (string $providerId, array $card) use ($gateway): void {
            $gateway->detachPaymentMethod($card['id']);
        }, refuseDefault: true);
    }

    /**
     * @param callable(string, array{id: string, isDefault: bool}): void $action
     */
    private function act(string $id, User $user, EntityManagerInterface $em, PaymentGatewayInterface $gateway, callable $action, bool $refuseDefault = false): JsonResponse
    {
        $providerId = $this->findProviderSubscriptionId($user, $em);
        if ($providerId instanceof JsonResponse) {
            return $providerId;
        }

        try {
            $cards = $gateway->listPaymentMethods($providerId);
            $card = current(array_filter($cards, static fn (array $c) => $c['id'] === $id)) ?: null;
            if ($card === null) {
                return $this->json(['error' => 'Carte introuvable.'], 404);
            }
            if ($refuseDefault && $card['isDefault']) {
                return $this->json(['error' => "Choisissez d'abord une autre carte par défaut."], 409);
            }
            $action($providerId, $card);
        } catch (StripeException) {
            return $this->unavailable();
        }

        return $this->json(null, 204);
    }

    // * identifiant Stripe de l'abonnement qui paie : en cours, d'essai ou en retard de paiement (c'est là qu'on change
    // * de carte), déjà relié à Stripe
    private function findProviderSubscriptionId(User $user, EntityManagerInterface $em): string|JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        foreach ($em->getRepository(Subscription::class)->findBy(['producer' => $producer], ['createdAt' => 'DESC']) as $subscription) {
            $providerId = $subscription->getProviderSubscriptionId();
            if ($providerId !== null && \in_array($subscription->getStatus(), [SubscriptionStatus::Active, SubscriptionStatus::Trialing, SubscriptionStatus::PastDue], true)) {
                return $providerId;
            }
        }

        return $this->json(['error' => 'Aucun abonnement à gérer.'], 404);
    }

    private function unavailable(): JsonResponse
    {
        return $this->json(['error' => 'Le service de paiement est momentanément indisponible.'], 502);
    }
}
