<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021082952 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Données de référence du tunnel de demande : devise EUR, pays FR, unités de mesure.';
    }

    public function up(Schema $schema): void
    {
        // is_active vaut false par défaut dans l'entité : il faut le mettre à true explicitement.
        $this->addSql("INSERT INTO catalog.currencies (code, name, symbol, decimals, is_active)
                    VALUES ('EUR', 'Euro', '€', 2, true)
                    ON CONFLICT (code) DO NOTHING");

        // Pays par défaut de ClientRequestController (DEFAULT_COUNTRY). Inséré APRÈS la devise qu'il référence.
        $this->addSql("INSERT INTO catalog.countries (code, name, currency, distance_unit, is_active)
                    VALUES ('FR', 'France', 'EUR', 'km', true)
                    ON CONFLICT (code) DO NOTHING");

        // Pas de ON CONFLICT : la table units n'a pas (encore) de contrainte UNIQUE sur code.
        // NOT EXISTS donne le même résultat : on n'insère pas une unité qui existe déjà.
        $units = [
            ['kg', 'Kilogramme'], ['tonne', 'Tonne'], ['litre', 'Litre'], ['piece', 'Unité'],
            ['caisse', 'Caisse'], ['palette', 'Palette'], ['lot', 'Lot'], ['botte', 'Botte'],
            ['bouteille', 'Bouteille'], ['barquette', 'Barquette'], ['panier', 'Panier'],
        ];

        foreach ($units as [$code, $label]) {
            // Valeurs écrites en dur ci-dessus (pas de saisie utilisateur) : l'insertion directe est sans risque.
            $this->addSql(sprintf(
                "INSERT INTO catalog.units (id, code, label)
                SELECT gen_random_uuid(), '%1\$s', '%2\$s'
                WHERE NOT EXISTS (SELECT 1 FROM catalog.units WHERE code = '%1\$s')",
                $code,
                $label
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM catalog.countries WHERE code = 'FR'");
        $this->addSql("DELETE FROM catalog.currencies WHERE code = 'EUR'");
        $this->addSql("DELETE FROM catalog.units WHERE code IN
            ('kg','tonne','litre','piece','caisse','palette','lot','botte','bouteille','barquette','panier')");
    }
}