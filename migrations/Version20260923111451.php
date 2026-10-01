<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * 2FA obligatoire admin (cahier DevOps), étape "Fondations" : colonnes nécessaires à
 * Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface et BackupCodeInterface sur User. Le reste du diff auto-
 * généré par doctrine:migrations:diff (search_vector, index GIN/GiST) est du bruit préexistant -- ces objets
 * sont créés par des migrations SQL antérieures (voir Version20260901130245) et non déclarés dans les
 * annotations d'entité, donc hors sujet ici et volontairement exclus.
 */
final class Version20260923111451 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute totp_secret et backup_codes sur identity.users (2FA admin).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity.users ADD totp_secret VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE identity.users ADD backup_codes JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity.users DROP totp_secret');
        $this->addSql('ALTER TABLE identity.users DROP backup_codes');
    }
}
