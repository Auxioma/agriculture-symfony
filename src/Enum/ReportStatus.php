<?php

/**
 * Statut de Report::$status (signalement polymorphe -- targetType/targetId, pas de FK directe). Open est la
 * seule valeur réellement posée aujourd'hui (valeur par défaut de l'entité) ; le tableau de bord admin
 * compte les signalements "Open" comme non traités (DashboardController::index()). InReview/Resolved/
 * Rejected existent dans le schéma pour un futur écran de traitement des signalements, mais aucune route ne
 * les pose encore -- la modération réelle passe aujourd'hui par ConversationStatus (voir ce fichier) plutôt
 * que par ce statut-ci.
 */

namespace App\Enum;

enum ReportStatus: string
{
    case Open = 'open';
    case InReview = 'in_review';
    case Resolved = 'resolved';
    case Rejected = 'rejected';
}
