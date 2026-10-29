<?php

namespace App\Controller\Api;

use App\Entity\Identity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/client')]
final class ClientDashboardController extends AbstractController
{
    public function __construct(private readonly Connection $db)
    {
    }

    #[Route('/dashboard', methods: ['GET'])]
    public function __invoke(#[CurrentUser] User $client, Request $request): JsonResponse
    {
        $uid = $client->getId()->toRfc4122();
        $locale = $request->query->get('locale', 'fr');

        return $this->json([
            ...$this->counters($uid),
            'requests'  => $this->latestRequests($uid, $locale),
            'messages'  => $this->latestMessages($uid),
            'favorites' => $this->favorites($uid),
        ]);
    }

    #[Route('/requests', methods: ['GET'])]
    public function listMyRequests(
        #[CurrentUser] User $client,
        Request $request,
        EntityManagerInterface $em,
    ): JsonResponse {
        $rows = $em->getConnection()->fetchAllAssociative(<<<'SQL'
            SELECT cr.id, cr.status, cr.created_at, cr.quantity, u.code AS unit,
                COALESCE(pt.name, cr.custom_product) AS product_name
            FROM matching.client_requests cr
            LEFT JOIN catalog.product_translations pt
                ON pt.product_id = cr.product_id AND pt.locale = :locale
            LEFT JOIN catalog.units u ON u.id = cr.unit_id
            WHERE cr.client_id = :uid::uuid
            AND cr.status NOT IN ('draft', 'cancelled')
            ORDER BY cr.created_at DESC
        SQL, [
            'uid'    => $client->getId()->toRfc4122(),
            'locale' => $request->query->get('locale', 'fr'),
        ]);

        return $this->json(array_map(static function (array $r): array {
            $qty = $r['quantity'] !== null ? rtrim(rtrim((string) $r['quantity'], '0'), '.') : null;

            return [
                'id'     => $r['id'],
                'title'  => trim(($r['product_name'] ?? '') . ($qty ? " - {$qty}{$r['unit']}" : '')),
                'status' => $r['status'], // statut brut : le front décide du libellé et de la couleur
                'sentAt' => (new \DateTimeImmutable($r['created_at']))->format(DATE_ATOM),
            ];
        }, $rows));
    }

    private function counters(string $uid): array
    {
        $row = $this->db->fetchAssociative(<<<'SQL'
            SELECT
              (SELECT count(*) FROM matching.client_requests
                WHERE client_id = :uid::uuid
                  AND status IN ('sent','waiting_replies','replies_received','conversation_open')
              ) AS active_requests,
              (SELECT count(*) FROM matching.producer_replies r
                JOIN matching.client_requests cr ON cr.id = r.request_id
                WHERE cr.client_id = :uid::uuid AND r.status IN ('sent','seen')
              ) AS received_replies,
              (SELECT count(*) FROM messaging.messages m
                JOIN messaging.conversations c ON c.id = m.conversation_id
                WHERE c.client_id = :uid::uuid
                  AND m.sender_id IS DISTINCT FROM :uid::uuid
                  AND COALESCE(m.is_system, false) = false
                  AND NOT EXISTS (SELECT 1 FROM messaging.message_reads mr
                                  WHERE mr.message_id = m.id AND mr.user_id = :uid::uuid)
              ) AS unread_messages
        SQL, ['uid' => $uid]);

        return [
            'activeRequests'  => (int) $row['active_requests'],
            'receivedReplies' => (int) $row['received_replies'],
            'unreadMessages'  => (int) $row['unread_messages'],
        ];
    }

    private function latestRequests(string $uid, string $locale): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT cr.id, cr.status, cr.created_at, cr.quantity, u.code AS unit,
                   COALESCE(pt.name, cr.custom_product) AS product_name
            FROM matching.client_requests cr
            LEFT JOIN catalog.product_translations pt
                   ON pt.product_id = cr.product_id AND pt.locale = :locale
            LEFT JOIN catalog.units u ON u.id = cr.unit_id
            WHERE cr.client_id = :uid::uuid
              AND cr.status NOT IN ('draft', 'cancelled', 'archived')
            ORDER BY cr.created_at DESC
            LIMIT 5
        SQL, ['uid' => $uid, 'locale' => $locale]);

        return array_map(static function (array $r): array {
            $qty = $r['quantity'] !== null ? rtrim(rtrim((string) $r['quantity'], '0'), '.') : null;

            return [
                'id'     => $r['id'],
                'title'  => trim(($r['product_name'] ?? '') . ($qty ? " - {$qty}{$r['unit']}" : '')),
                'sentAt' => (new \DateTimeImmutable($r['created_at']))->format(DATE_ATOM),
                // le front n'a que 2 états : "réponses reçues" ou "en attente"
                'status' => in_array($r['status'], ['replies_received', 'conversation_open'], true)
                    ? 'replied' : 'pending',
            ];
        }, $rows);
    }

    private function latestMessages(string $uid): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT c.id, pp.farm_name, lm.content,
              (SELECT count(*) FROM messaging.messages m
                WHERE m.conversation_id = c.id
                  AND m.sender_id IS DISTINCT FROM :uid::uuid
                  AND NOT EXISTS (SELECT 1 FROM messaging.message_reads mr
                                  WHERE mr.message_id = m.id AND mr.user_id = :uid::uuid)
              ) AS unread_count
            FROM messaging.conversations c
            JOIN producer.producer_profiles pp ON pp.id = c.producer_id
            LEFT JOIN LATERAL (
              SELECT content FROM messaging.messages
              WHERE conversation_id = c.id
              ORDER BY created_at DESC LIMIT 1
            ) lm ON true
            WHERE c.client_id = :uid::uuid AND c.status = 'open'
            ORDER BY c.last_message_at DESC NULLS LAST
            LIMIT 3
        SQL, ['uid' => $uid]);

        return array_map(fn (array $r): array => [
            'id'           => $r['id'],
            'producerName' => $r['farm_name'],
            'initials'     => $this->initials((string) $r['farm_name']),
            'subtitle'     => mb_strimwidth((string) $r['content'], 0, 60, '…'),
            'unreadCount'  => (int) $r['unread_count'],
        ], $rows);
    }

    private function favorites(string $uid): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT pp.id, pp.slug, pp.farm_name
            FROM engagement.favorites f
            JOIN producer.producer_profiles pp ON pp.id = f.target_id
            WHERE f.user_id = :uid::uuid
              AND f.target_type = 'producer_profile'
              AND pp.is_active = true
            ORDER BY f.created_at DESC
            LIMIT 3
        SQL, ['uid' => $uid]);

        return array_map(fn (array $r): array => [
            'id'       => $r['id'],
            'slug'     => $r['slug'],
            'name'     => $r['farm_name'],
            'initials' => $this->initials((string) $r['farm_name']),
        ], $rows);
    }

    /** "Ferme Dupont" => "FD" ; "Miel & Bio" => "MB" (ignore les symboles) */
    private function initials(string $name): string
    {
        $words = array_filter(
            preg_split('/\s+/u', trim($name)) ?: [],
            static fn (string $w): bool => preg_match('/^\p{L}/u', $w) === 1,
        );

        return mb_strtoupper(implode('', array_map(
            static fn (string $w): string => mb_substr($w, 0, 1),
            array_slice($words, 0, 2),
        )));
    }
}