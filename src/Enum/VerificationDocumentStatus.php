<?php

/**
 * Statut de VerificationDocument::$status. Pending posé à l'upload
 * (ProducerVerificationController::uploadDocument()) ; Approved/Rejected posés par
 * VerificationDocumentCrudController (back-office). Approuver un document lié à un ProducerLabel
 * (via $document) fait aussi passer ce label en vérifié -- voir
 * VerificationDocumentCrudController::approveDocument().
 */

namespace App\Enum;

enum VerificationDocumentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
