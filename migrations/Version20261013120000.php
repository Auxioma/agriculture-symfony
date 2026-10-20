<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un seul abonnement en vigueur (actif, en essai ou en retard de paiement) par producteur, imposé par la base. Le MLD
 * le prévoit ("partial unique active subscription per producer") mais seul l'identifiant Stripe était unique : le seul
 * garde-fou était le contrôle à la création de la session de paiement, que deux sessions ouvertes en parallèle
 * contournent. Les abonnements annulés ou expirés restent en nombre illimité (historique).
 */
final class Version20261013120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index unique partiel : un seul abonnement en vigueur par producteur.';
    }

    public function up(Schema $schema): void
    {
        // * l'index ne peut pas se créer sur des doublons déjà présents : message clair plutôt que l'erreur SQL brute
        $duplicates = (int) $this->connection->fetchOne("SELECT count(*) FROM (SELECT 1 FROM billing.subscriptions WHERE status IN ('active', 'trialing', 'past_due') GROUP BY producer_id HAVING count(*) > 1) d");
        $this->abortIf($duplicates > 0, \sprintf("%d producteur(s) ont plus d'un abonnement en vigueur : n'en garder qu'un (annuler les autres) puis relancer.", $duplicates));

        $this->addSql("CREATE UNIQUE INDEX uniq_subscriptions_one_in_force_per_producer ON billing.subscriptions (producer_id) WHERE status IN ('active', 'trialing', 'past_due')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX billing.uniq_subscriptions_one_in_force_per_producer');
    }
}
