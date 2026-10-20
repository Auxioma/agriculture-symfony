<?php

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Signalement d'une demande client par un producteur (cahier fonctionnel : "Archiver ou signaler une demande").
 *
 * Payload attendu par POST /api/producer/requests/{id}/report, validé par #[MapRequestPayload] dans
 * ProducerRequestController::reportRequest(). Les motifs sont ceux proposés par la page "Signaler cette demande" ;
 * l'équipe de modération les lit dans Report::$reason.
 */
final readonly class ReportClientRequestRequest
{
    public const REASONS = ['fraud', 'inappropriate', 'circumvention', 'out_of_scope', 'other'];

    // * libellés lus par l'admin (fiche d'une demande signalée)
    public const REASON_LABELS = [
        'fraud' => 'Demande frauduleuse ou fictive',
        'inappropriate' => 'Contenu inapproprié ou injurieux',
        'circumvention' => 'Coordonnées suspectes ou contournement de la plateforme',
        'out_of_scope' => 'Demande hors périmètre agricole',
        'other' => 'Autre',
    ];

    public function __construct(
        #[Assert\Choice(choices: self::REASONS, message: 'Motif de signalement inconnu.')]
        public string $reason,

        #[Assert\Length(max: 2000)]
        public ?string $message = null,
    ) {
    }
}
