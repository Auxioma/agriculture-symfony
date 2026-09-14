<?php

/**
 * Healthcheck public (cahier des charges DevOps, "Alertes minimales production"). Couvre volontairement
 * plusieurs lignes du tableau du cahier en un seul endpoint plutôt qu'un job séparé par alerte -- pas de
 * scheduler applicatif sur ce projet (ni worker Messenger actif, ni accès garanti aux secrets/cron
 * GitHub Actions pour tout le monde), donc tout ce qui peut être vérifié "à la demande" au moment du ping
 * externe (Healthchecks.io) l'est ici plutôt que via un job dédié :
 *
 * - "Site indisponible" / "DB connexions" -- la base répond, et son taux d'utilisation des connexions.
 * - "Disque DB" -- espace disque libre. Approximation : ce projet n'a qu'un seul serveur (PostgreSQL et
 *   l'appli dessus), donc le disque de l'appli EST le disque de la base -- pas vrai sur une base managée
 *   séparée, à revoir si l'hébergement change. Vérifié uniquement en prod (voir plus bas).
 * - "Webhooks paiement" -- échecs Stripe récents (WebhookEvent::$status = 'failed').
 * Route publique (voir security.yaml, access_control ^/api/health) : un outil de supervision externe
 * doit pouvoir l'appeler sans authentification. Renvoie 503 dès qu'un des points est en défaut, pour que
 * n'importe quel client HTTP strict (ex. curl -f dans .github/workflows/healthcheck.yml) le détecte.
 */

namespace App\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractController
{
    // * Seuils repris tels quels du tableau "Alertes minimales production" du cahier des charges DevOps.
    private const MAX_DB_CONNECTIONS_RATIO = 0.8;
    private const MIN_DISK_FREE_RATIO = 0.2;
    private const MAX_RECENT_FAILED_WEBHOOKS = 3;

    #[Route('/api/health', methods: ['GET'])]
    public function health(
        EntityManagerInterface $em,
        #[Autowire(param: 'kernel.environment')] string $environment,
    ): JsonResponse {
        $checks = [];
        $healthy = true;

        try {
            $connection = $em->getConnection();
            $connection->executeQuery('SELECT 1');
            $checks['database'] = 'ok';

            $maxConnections = (int) $connection->fetchOne('SHOW max_connections');
            $currentConnections = (int) $connection->fetchOne('SELECT count(*) FROM pg_stat_activity');
            $connectionsRatio = $maxConnections > 0 ? $currentConnections / $maxConnections : 0;
            $checks['db_connections'] = $connectionsRatio < self::MAX_DB_CONNECTIONS_RATIO ? 'ok' : 'critical';
            $healthy = $healthy && $connectionsRatio < self::MAX_DB_CONNECTIONS_RATIO;

            $recentFailedWebhooks = (int) $connection->fetchOne(
                "SELECT count(*) FROM billing.webhook_events WHERE status = 'failed' AND received_at >= now() - interval '1 hour'"
            );
            $checks['payment_webhooks'] = $recentFailedWebhooks < self::MAX_RECENT_FAILED_WEBHOOKS ? 'ok' : 'critical';
            $healthy = $healthy && $recentFailedWebhooks < self::MAX_RECENT_FAILED_WEBHOOKS;
        } catch (\Throwable) {
            $checks['database'] = 'error';
            $healthy = false;
        }

        // ! Uniquement en prod : le disque local d'un poste de dev ou d'un runner CI n'a aucun rapport
        // ! avec le disque du serveur réel -- vérifié en le laissant actif partout, ça fait échouer le
        // ! healthcheck en test/dev dès que la machine locale a moins de 20 % d'espace libre, sans rapport
        // ! avec la santé de l'application.
        if ($environment === 'prod') {
            // * @-suppression volontaire : disk_free_space() peut échouer selon la configuration du
            // * serveur (open_basedir, permissions) -- une mesure de disque indisponible ne doit pas faire
            // * planter tout le healthcheck, juste ne pas remonter ce point précis.
            $freeBytes = @disk_free_space(__DIR__);
            $totalBytes = @disk_total_space(__DIR__);
            if ($freeBytes !== false && $totalBytes !== false && $totalBytes > 0) {
                $freeRatio = $freeBytes / $totalBytes;
                $checks['disk'] = $freeRatio > self::MIN_DISK_FREE_RATIO ? 'ok' : 'critical';
                $healthy = $healthy && $freeRatio > self::MIN_DISK_FREE_RATIO;
            }
        }

        return $this->json(['status' => $healthy ? 'ok' : 'error', 'checks' => $checks], $healthy ? 200 : 503);
    }
}