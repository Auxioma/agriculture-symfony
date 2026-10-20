<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Réponse producteur (cahier fonctionnel, "Champs d'une réponse") : le cahier demande une quantité disponible
 * et des conditions de retrait et de livraison séparées, que le MPD et la table n'avaient pas (une seule colonne
 * "conditions", conservée). Le reste du diff auto-généré par doctrine:migrations:diff (search_vector, index
 * GIN/GiST, INET) est du bruit préexistant, volontairement exclu.
 */
final class Version20261008100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute available_quantity, pickup_conditions et delivery_conditions sur matching.producer_replies.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE matching.producer_replies ADD available_quantity NUMERIC(14, 3) DEFAULT NULL');
        $this->addSql('ALTER TABLE matching.producer_replies ADD pickup_conditions TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE matching.producer_replies ADD delivery_conditions TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE matching.producer_replies DROP available_quantity');
        $this->addSql('ALTER TABLE matching.producer_replies DROP pickup_conditions');
        $this->addSql('ALTER TABLE matching.producer_replies DROP delivery_conditions');
    }
}
