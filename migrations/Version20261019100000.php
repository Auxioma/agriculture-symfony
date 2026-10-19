<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Labels déclarés par un producteur pour un de ses produits (Bio sur ses tomates, pas sur ses pommes). Le MLD donne
 * "labels" parmi les attributs de ProducerProduct mais le modèle physique n'avait que producer_labels, rattaché à
 * l'exploitation (avec vérification et justificatif) : cette table de liaison réalise l'attribut du MLD. Ces labels de
 * produit sont déclaratifs, la vérification reste celle de producer_labels.
 */
final class Version20261019100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table de liaison producer.producer_product_labels : labels déclarés par produit.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE producer.producer_product_labels (producer_product_id UUID NOT NULL, label_id UUID NOT NULL, PRIMARY KEY (producer_product_id, label_id))');
        $this->addSql('CREATE INDEX IDX_C59F479F65EFAD74 ON producer.producer_product_labels (producer_product_id)');
        $this->addSql('CREATE INDEX IDX_C59F479F33B92F39 ON producer.producer_product_labels (label_id)');
        $this->addSql('ALTER TABLE producer.producer_product_labels ADD CONSTRAINT FK_C59F479F65EFAD74 FOREIGN KEY (producer_product_id) REFERENCES producer.producer_products (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE producer.producer_product_labels ADD CONSTRAINT FK_C59F479F33B92F39 FOREIGN KEY (label_id) REFERENCES catalog.labels (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE producer.producer_product_labels');
    }
}
