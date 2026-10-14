<?php

namespace App\Service\Payment;

use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\ExceptionInterface as StripeException;

/**
 * Trace un appel à Stripe qui a échoué. Les contrôleurs répondaient 502 sans rien écrire : impossible de savoir si c'était
 * une clé invalide, un prix inconnu ou une vraie panne. On garde l'action, le message de Stripe et l'identifiant de sa
 * requête (à donner à leur support), sans clé ni donnée personnelle.
 */
final class StripeFailureLogger
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function log(string $action, StripeException $e): void
    {
        $status = $e instanceof ApiErrorException ? $e->getHttpStatus() : null;

        // * 402 : paiement refusé, un cas normal côté client ; le reste est une panne ou une erreur de configuration
        $this->logger->log(402 === $status ? 'warning' : 'error', 'Appel Stripe échoué : '.$action, [
            'action' => $action,
            'error' => $e->getMessage(),
            'http_status' => $status,
            'stripe_request_id' => $e instanceof ApiErrorException ? $e->getRequestId() : null,
        ]);
    }
}
