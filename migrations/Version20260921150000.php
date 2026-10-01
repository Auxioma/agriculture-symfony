<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prépare les écrans "Labels" et "Paramètres plateforme" du back-office : catalog.labels.is_active permet de
 * retirer un label de la liste publique sans supprimer ses rattachements (les labels existants restent
 * actifs), et content.platform_settings stocke les paramètres globaux en clé/valeur.
 */
final class Version20260921150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute catalog.labels.is_active et crée content.platform_settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog.labels ADD is_active BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('CREATE TABLE content.platform_settings (setting_key VARCHAR(64) NOT NULL, value TEXT DEFAULT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (setting_key))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE content.platform_settings');
        $this->addSql('ALTER TABLE catalog.labels DROP is_active');
    }
}
