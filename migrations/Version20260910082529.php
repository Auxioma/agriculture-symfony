<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute des index sur les colonnes de statut/date fréquemment filtrées par le back-office (dashboard,
 * reporting, SendExpiryRemindersCommand) mais qui ne sont ni une clé étrangère (déjà indexée
 * automatiquement par Doctrine) ni couvertes par une contrainte unique.
 */
final class Version20260910082529 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index sur les colonnes status/paid_at/expires_at filtrées par le dashboard et le reporting admin';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_client_requests_status ON matching.client_requests (status)');
        $this->addSql('CREATE INDEX idx_client_requests_expires_at ON matching.client_requests (expires_at)');
        $this->addSql('CREATE INDEX idx_conversations_status ON messaging.conversations (status)');
        $this->addSql('CREATE INDEX idx_producer_profiles_verification_status ON producer.producer_profiles (verification_status)');
        $this->addSql('CREATE INDEX idx_tickets_status ON support.tickets (status)');
        $this->addSql('CREATE INDEX idx_reports_status ON trust.reports (status)');
        $this->addSql('CREATE INDEX idx_subscriptions_status ON billing.subscriptions (status)');
        $this->addSql('CREATE INDEX idx_reviews_status ON trust.reviews (status)');
        $this->addSql('CREATE INDEX idx_invoices_paid_at ON billing.invoices (paid_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX matching.idx_client_requests_status');
        $this->addSql('DROP INDEX matching.idx_client_requests_expires_at');
        $this->addSql('DROP INDEX messaging.idx_conversations_status');
        $this->addSql('DROP INDEX producer.idx_producer_profiles_verification_status');
        $this->addSql('DROP INDEX support.idx_tickets_status');
        $this->addSql('DROP INDEX trust.idx_reports_status');
        $this->addSql('DROP INDEX billing.idx_subscriptions_status');
        $this->addSql('DROP INDEX trust.idx_reviews_status');
        $this->addSql('DROP INDEX billing.idx_invoices_paid_at');
    }
}
