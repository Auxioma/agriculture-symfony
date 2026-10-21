<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un producteur peut avoir plusieurs variétés d'un même produit (Gala et Golden pour les pommes : le MLD parle de « plusieurs
 * produits, variétés et périodes de disponibilité »), mais pas deux fois la même. Seul un contrôle de l'API refusait jusque-là
 * tout doublon de produit ; cet index remplace cette règle trop stricte par la bonne, et la garantit aussi contre deux
 * requêtes simultanées. Sans variété compte comme une variété ; la casse et les espaces autour ne comptent pas.
 */
final class Version20261021100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index unique : une variété par produit et par producteur.';
    }

    public function up(Schema $schema): void
    {
        // * l'index ne peut pas se créer sur des doublons déjà présents : message clair plutôt que l'erreur SQL brute
        $duplicates = (int) $this->connection->fetchOne("SELECT count(*) FROM (SELECT 1 FROM producer.producer_products GROUP BY producer_id, product_id, lower(btrim(coalesce(variety, ''))) HAVING count(*) > 1) d");
        $this->abortIf($duplicates > 0, \sprintf("%d produit(s) de producteur sont en double (même produit, même variété) : en supprimer ou renommer la variété, puis relancer.", $duplicates));

        $this->addSql("CREATE UNIQUE INDEX uniq_producer_products_variety ON producer.producer_products (producer_id, product_id, (lower(btrim(coalesce(variety, '')))))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX producer.uniq_producer_products_variety');
    }
}
