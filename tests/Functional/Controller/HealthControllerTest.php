<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Billing\WebhookEvent;
use App\Tests\ApiTestCase;

/**
 * Teste GET /api/health (cahier des charges DevOps, "Alertes minimales production" -- site
 * indisponible, DB connexions, disque, webhooks paiement). Route publique, appelée sans
 * authentification par un outil de supervision externe.
 */
final class HealthControllerTest extends ApiTestCase
{
    public function testHealthReturnsOkWithoutAuthentication(): void
    {
        // *Aucun header Authorization : c'est justement ce qu'un outil de supervision externe fera.
        $this->client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('ok', $data['status']);
        self::assertSame('ok', $data['checks']['database']);
        self::assertSame('ok', $data['checks']['db_connections']);
        self::assertSame('ok', $data['checks']['payment_webhooks']);
        // *Pas de clé "disk" en environnement de test -- HealthController::MIN_DISK_FREE_RATIO ne
        // *s'applique qu'en prod (le disque d'un runner CI n'a aucun rapport avec celui du vrai serveur).
        self::assertArrayNotHasKey('disk', $data['checks']);
    }

    public function testHealthReturns503WhenTooManyRecentFailedWebhooks(): void
    {
        // *3 webhooks échoués dans la dernière heure = seuil HealthController::MAX_RECENT_FAILED_WEBHOOKS.
        for ($i = 0; $i < 3; ++$i) {
            $webhookEvent = new WebhookEvent();
            $webhookEvent->setStatus('failed');
            $webhookEvent->setReceivedAt(new \DateTimeImmutable());
            $this->em->persist($webhookEvent);
        }
        $this->em->flush();

        $this->client->request('GET', '/api/health');

        self::assertResponseStatusCodeSame(503);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('error', $data['status']);
        self::assertSame('critical', $data['checks']['payment_webhooks']);
    }
}
