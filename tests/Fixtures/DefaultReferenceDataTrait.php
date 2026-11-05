<?php

namespace App\Tests\Fixtures;

use Doctrine\DBAL\Connection;

/**
 * Données de référence dont dépend ClientRequestController (DEFAULT_CURRENCY = EUR, DEFAULT_COUNTRY = FR).
 * À appeler dans setUp() de tout test qui crée une demande via POST /api/requests sans currencyCode/countryCode.
 * La connexion est passée en paramètre : la trait ne dépend d'aucune propriété de la classe de test.
 */
trait DefaultReferenceDataTrait
{
    private function seedDefaultReferenceData(Connection $connection): void
    {
        // * SQL brut + ON CONFLICT DO NOTHING : idempotent, que la base de test soit déjà seedée ou non.
        $connection->executeStatement(
            "INSERT INTO catalog.currencies (code, name, symbol, decimals, is_active)
             VALUES ('EUR', 'Euro', '€', 2, true) ON CONFLICT (code) DO NOTHING"
        );
        $connection->executeStatement(
            "INSERT INTO catalog.countries (code, name, currency, distance_unit, is_active)
             VALUES ('FR', 'France', 'EUR', 'km', true) ON CONFLICT (code) DO NOTHING"
        );
    }
}