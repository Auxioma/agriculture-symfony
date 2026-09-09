<?php

namespace App\Tests;

/**
 * Écrit une géométrie ponctuelle PostGIS en SQL brut, pour les tests qui ont besoin d'une position réelle
 * (calcul de distance, rayon de matching...) sans passer par le format EWKT côté PHP. Utilisé aussi bien
 * par DatabaseTestCase (tests directs sur l'ORM/la base) que par ApiTestCase (tests qui passent par l'API) --
 * d'où l'extraction en trait plutôt qu'une méthode dupliquée dans les deux classes de base.
 */
trait GeographyTestHelperTrait
{
    protected function setGeographyPoint(string $table, string $column, string $id, float $lon, float $lat): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE {$table} SET {$column} = ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)::geography WHERE id = :id",
            ['lon' => $lon, 'lat' => $lat, 'id' => $id]
        );
    }
}
