<?php

namespace App\Service\Matching;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Détection de doublons et de spam sur les demandes clients (cahier fonctionnel, Back-office "Demandes" :
 * "doublons, spam" ; cahier graphisme, "détecter spam et doublons"). Aucun seuil n'est fixé par les cahiers : les
 * constantes ci-dessous sont NOS choix, à faire valider par le client. Règles volontairement simples et
 * explicables (un admin doit pouvoir dire POURQUOI une demande est signalée), calculées à la volée en SQL --
 * aucune colonne ni table ajoutée, donc rien à migrer ni à garder synchronisé.
 *
 * Un signal n'est qu'une aide à la décision : ce service ne modifie jamais une demande. Il ne porte que sur les
 * demandes "en circulation" (LIVE_STATUSES) -- une demande déjà annulée, archivée ou expirée n'a plus rien à
 * modérer, ce qui vide naturellement la file "à vérifier" une fois l'action prise.
 *
 * Faux positifs écartés à dessein : les demandes récurrentes (RunRecurringRequestsCommand) et le bouton
 * "dupliquer" du client recopient une demande à l'identique, mais la plus courte récurrence est hebdomadaire :
 * une fenêtre de doublon de 48 h ne les attrape pas.
 */
final class RequestQualityAnalyzer
{
    public const SIGNAL_DUPLICATE = 'duplicate';
    public const SIGNAL_FLOOD = 'flood';
    public const SIGNAL_LINK = 'link';
    public const SIGNAL_MASS_MESSAGE = 'mass_message';

    public const LIVE_STATUSES = ['sent', 'waiting_replies', 'replies_received', 'conversation_open'];

    // * Doublon : même client, même besoin, même lieu, publiés à moins de 48 h d'écart (double envoi, republication
    // * impatiente). Voir le docblock de classe pour la marge par rapport aux récurrences. created_at n'a que la seconde
    // * de précision en base : un double clic tombe presque toujours dans la MÊME seconde, d'où le départage par
    // * identifiant (l'un des deux est arbitrairement "l'original", de façon stable) -- sans lui, aucun n'était signalé.
    public const DUPLICATE_WINDOW_HOURS = 48;
    // * Rafale : 5 demandes publiées ou plus par le même client sur les 24 h qui précèdent celle-ci.
    public const FLOOD_THRESHOLD = 5;
    public const FLOOD_WINDOW_HOURS = 24;
    // * Message copié-collé : un texte d'au moins 30 caractères (les messages très courts comme "Bonjour" sont
    // * banals) envoyé à l'identique par au moins 3 clients différents.
    public const MASS_MESSAGE_MIN_LENGTH = 30;
    public const MASS_MESSAGE_MIN_CLIENTS = 3;

    // * Le message est comparé en minuscules, espaces multiples réduits : un copier-coller n'est jamais parfaitement
    // * identique à l'octet près.
    private const NORMALIZE_MESSAGE = "lower(btrim(regexp_replace(coalesce(%s.message, ''), '\\s+', ' ', 'g')))";

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<string> $requestIds
     *
     * @return array<string, list<string>> identifiant de demande => signaux détectés (seules les demandes signalées y figurent)
     */
    public function signalsFor(array $requestIds): array
    {
        return $this->collect($requestIds);
    }

    /**
     * Signaux de TOUTES les demandes signalées (file "à vérifier" de l'écran Demandes : liste, compteur, filtre).
     * Parcourt la table : acceptable à l'échelle d'un back-office (chaque règle est en une passe ou s'appuie sur
     * l'index de client_id), à réévaluer (index, vue matérialisée) si elle devient très volumineuse.
     *
     * @return array<string, list<string>> identifiant de demande => signaux
     */
    public function flaggedSignals(): array
    {
        return $this->collect(null);
    }

    /**
     * @return list<string>
     */
    public function flaggedIds(): array
    {
        return array_keys($this->flaggedSignals());
    }

    /**
     * Pour chaque demande en doublon, les demandes plus anciennes qu'elle recopie.
     *
     * @param list<string> $requestIds
     *
     * @return array<string, list<string>> identifiant de la demande => identifiants des originaux
     */
    public function duplicatesOf(array $requestIds): array
    {
        return $requestIds ? $this->duplicateOriginals($requestIds) : [];
    }

    /**
     * @param list<string>|null $ids null = toutes les demandes
     *
     * @return array<string, list<string>>
     */
    private function collect(?array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $signals = [];
        foreach (array_keys($this->duplicateOriginals($ids)) as $id) {
            $signals[$id][] = self::SIGNAL_DUPLICATE;
        }
        foreach ([
            self::SIGNAL_FLOOD => $this->floodIds($ids),
            self::SIGNAL_LINK => $this->linkIds($ids),
            self::SIGNAL_MASS_MESSAGE => $this->massMessageIds($ids),
        ] as $signal => $flagged) {
            foreach ($flagged as $id) {
                $signals[$id][] = $signal;
            }
        }

        return $signals;
    }

    /**
     * @param list<string>|null $ids
     *
     * @return array<string, list<string>>
     */
    private function duplicateOriginals(?array $ids): array
    {
        $what = static fn (string $t): string => sprintf("COALESCE(%1\$s.product_id::text, lower(btrim(%1\$s.custom_product)))", $t);
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT r.id AS request_id, o.id AS original_id
                 FROM matching.client_requests r
                 JOIN matching.client_requests o
                   ON o.client_id = r.client_id
                  AND o.id <> r.id
                  AND (o.created_at < r.created_at OR (o.created_at = r.created_at AND o.id < r.id))
                  AND r.created_at - o.created_at <= make_interval(hours => :window)
                  AND o.need_type = r.need_type
                  AND %s = %s
                  AND o.country_code IS NOT DISTINCT FROM r.country_code
                  AND lower(btrim(coalesce(o.city, ''))) = lower(btrim(coalesce(r.city, '')))
                  AND o.status IN (:live)
                 WHERE r.status IN (:live) %s
                 ORDER BY r.id, o.created_at",
                $what('o'),
                $what('r'),
                $this->idFilter($ids),
            ),
            $this->params($ids, ['window' => self::DUPLICATE_WINDOW_HOURS, 'live' => self::LIVE_STATUSES]),
            $this->types($ids, ['live' => ArrayParameterType::STRING]),
        );

        $originals = [];
        foreach ($rows as $row) {
            $originals[$row['request_id']][] = $row['original_id'];
        }

        return $originals;
    }

    /**
     * @param list<string>|null $ids
     *
     * @return list<string>
     */
    private function floodIds(?array $ids): array
    {
        return $this->connection->fetchFirstColumn(
            sprintf(
                "SELECT r.id FROM matching.client_requests r
                 WHERE r.status IN (:live) %s
                   AND (SELECT count(*) FROM matching.client_requests x
                        WHERE x.client_id = r.client_id
                          AND x.status <> 'draft'
                          AND x.created_at <= r.created_at
                          AND x.created_at > r.created_at - make_interval(hours => :window)) >= :threshold",
                $this->idFilter($ids),
            ),
            $this->params($ids, ['live' => self::LIVE_STATUSES, 'window' => self::FLOOD_WINDOW_HOURS, 'threshold' => self::FLOOD_THRESHOLD]),
            $this->types($ids, ['live' => ArrayParameterType::STRING]),
        );
    }

    /**
     * @param list<string>|null $ids
     *
     * @return list<string>
     */
    private function linkIds(?array $ids): array
    {
        return $this->connection->fetchFirstColumn(
            sprintf(
                "SELECT r.id FROM matching.client_requests r
                 WHERE r.status IN (:live) %s
                   AND (r.message ~* '(https?://|www\\.)' OR r.custom_product ~* '(https?://|www\\.)')",
                $this->idFilter($ids),
            ),
            $this->params($ids, ['live' => self::LIVE_STATUSES]),
            $this->types($ids, ['live' => ArrayParameterType::STRING]),
        );
    }

    /**
     * Le message est normalisé une seule fois par ligne puis regroupé (GROUP BY ... HAVING), au lieu d'une sous-requête
     * par demande : coût linéaire en nombre de demandes, pas quadratique.
     *
     * @param list<string>|null $ids
     *
     * @return list<string>
     */
    private function massMessageIds(?array $ids): array
    {
        return $this->connection->fetchFirstColumn(
            sprintf(
                "SELECT r.id FROM matching.client_requests r
                 JOIN (
                     SELECT %s AS normalized
                     FROM matching.client_requests x
                     WHERE x.status <> 'draft' AND length(%s) >= :minLength
                     GROUP BY normalized
                     HAVING count(DISTINCT x.client_id) >= :minClients
                 ) mass ON mass.normalized = %s
                 WHERE r.status IN (:live) %s",
                sprintf(self::NORMALIZE_MESSAGE, 'x'),
                sprintf(self::NORMALIZE_MESSAGE, 'x'),
                sprintf(self::NORMALIZE_MESSAGE, 'r'),
                $this->idFilter($ids),
            ),
            $this->params($ids, ['live' => self::LIVE_STATUSES, 'minLength' => self::MASS_MESSAGE_MIN_LENGTH, 'minClients' => self::MASS_MESSAGE_MIN_CLIENTS]),
            $this->types($ids, ['live' => ArrayParameterType::STRING]),
        );
    }

    /**
     * @param list<string>|null $ids
     */
    private function idFilter(?array $ids): string
    {
        return null === $ids ? '' : 'AND r.id IN (:ids)';
    }

    /**
     * @param list<string>|null    $ids
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function params(?array $ids, array $params): array
    {
        return null === $ids ? $params : $params + ['ids' => $ids];
    }

    /**
     * @param list<string>|null                          $ids
     * @param array<string, ArrayParameterType> $types
     *
     * @return array<string, ArrayParameterType>
     */
    private function types(?array $ids, array $types): array
    {
        return null === $ids ? $types : $types + ['ids' => ArrayParameterType::STRING];
    }
}
