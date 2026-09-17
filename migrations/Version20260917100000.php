<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Branche Coupon sur Stripe (cahier fonctionnel 24.1 V1, "coupons abonnement") : $providerCouponId référence
 * un Coupon Stripe créé au préalable dans le Dashboard (même principe que PlanPrice::$providerPriceId --
 * pas d'appel à l'API Stripe de création de coupon depuis ce dépôt). Ajoute aussi la contrainte UNIQUE sur
 * "code" qui manquait depuis la création de la table (Version20260901130245) : sans elle, deux coupons
 * portant le même code auraient été possibles, avec un findOneBy(['code' => ...]) en résolvant un au hasard.
 */
final class Version20260917100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute billing.coupons.provider_coupon_id et une contrainte UNIQUE sur billing.coupons.code';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing.coupons ADD provider_coupon_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE billing.coupons ADD CONSTRAINT UNIQ_coupons_code UNIQUE (code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing.coupons DROP CONSTRAINT UNIQ_coupons_code');
        $this->addSql('ALTER TABLE billing.coupons DROP provider_coupon_id');
    }
}
