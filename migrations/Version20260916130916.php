<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute support.reply_templates (SupportReplyTemplate) : modèles de réponse partagés par l'équipe
 * support (cahier fonctionnel, module Back-office "Support" -- distinct de producer.quick_replies,
 * qui appartient à un producteur précis pour sa messagerie client).
 */
final class Version20260916130916 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute support.reply_templates (modèles de réponse support, distincts de QuickReply)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE support.reply_templates (id UUID NOT NULL, title VARCHAR(120) DEFAULT NULL, content TEXT DEFAULT NULL, position INT DEFAULT NULL, is_active BOOLEAN NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE support.reply_templates');
    }
}
