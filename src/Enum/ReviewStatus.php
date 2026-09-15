<?php

/**
 * Statut de Review::$status. Pending posé automatiquement à la création
 * (ReviewController::createReview()), en attente de modération. Published/Rejected posés par
 * ReviewCrudController (actions "Publier"/"Rejeter" du back-office) -- seuls les avis Published
 * sont renvoyés par ProducerController::listProducerReviews() (lecture publique de la fiche
 * producteur) et peuvent recevoir une réponse du producteur (ReviewController::respondToReview()).
 */

namespace App\Enum;

enum ReviewStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Rejected = 'rejected';
}
