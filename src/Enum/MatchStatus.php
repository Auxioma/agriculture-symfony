<?php

/**
 * Statut d'un rapprochement demande/producteur (RequestMatch::$status, table matching.request_matches
 * peuplée par la fonction SQL matching.populate_request_matches()). Proposed est la seule valeur posée
 * aujourd'hui (valeur par défaut de la colonne) -- Unlocked/Ignored/Expired existent dans le schéma pour
 * distinguer plus tard un producteur non abonné qui débloque une demande, en ignore une, ou dont la
 * fenêtre de contact a expiré, mais rien ne les positionne encore.
 */

namespace App\Enum;

enum MatchStatus: string
{
    case Proposed = 'proposed';
    case Unlocked = 'unlocked';
    case Ignored = 'ignored';
    case Expired = 'expired';
}
