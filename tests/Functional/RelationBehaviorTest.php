<?php

/**
 * Copyright(c)2026 TrouveMoi (https://trouvemoi.com)
 *
 * Ce fichier fait partie d’un projet développé par Auxioma Web Agency pour l’entreprise.
 * Tous droits réservés.
 *
 * Ce code source est la propriété exclusive de Auxioma Web Agency et.
 * Toute reproduction, modification, distribution ou utilisation sans autorisation préalable est interdite.
 */

namespace App\Tests\Functional;

use App\Tests\DatabaseTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

final class RelationBehaviorTest extends DatabaseTestCase
{
    use EntityFactoryTrait;

    public function testAddProductSynchronizesTheInverseSideAndPersists(): void
    {
        $country = $this->makeCountry();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $producer = $this->makeProducerProfile($this->makeUser('rel1'), $country);

        $producerProduct = new \App\Entity\Producer\ProducerProduct();
        $producerProduct->setProduct($product);
        // *Pas de setProducer() direct --> on vérifie que addProduct() fait bien la synchronisation luimême
        $producer->addProduct($producerProduct);

        self::assertSame($producer, $producerProduct->getProducer(), 'addProduct() doit mettre à jour le côté propriétaire');

        // ! persist() explicite obligatoire ici
        $this->em->persist($producerProduct);
        $this->em->flush();
        $id = $producerProduct->getId()->toRfc4122();
        $this->em->clear();

        $reloaded = $this->em->find(\App\Entity\Producer\ProducerProduct::class, $id);
        self::assertSame($producer->getId()->toRfc4122(), $reloaded->getProducer()->getId()->toRfc4122());
    }

    public function testRemoveProductWithOrphanRemovalDeletesTheRow(): void
    {
        $country = $this->makeCountry();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $producer = $this->makeProducerProfile($this->makeUser('rel2'), $country);
        $this->makeProducerProduct($producer, $product);
        $this->em->flush();

        $producerId = $producer->getId()->toRfc4122();
        $this->em->clear();

        // * On recharge depuis la base pour s'assurer que l'entité est bien gérée par l'EntityManager et que la suppression se fait via l'ORM
        $reloadedProducer = $this->em->find(\App\Entity\Producer\ProducerProfile::class, $producerId);
        $toRemove = $reloadedProducer->getProducts()->first();
        self::assertNotFalse($toRemove);

        $reloadedProducer->removeProduct($toRemove);
        $this->em->flush();

        // * Vérification en SQL brut que la ligne a bien été supprimée de la table de jointure
        $count = $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM producer.producer_products WHERE producer_id = :id', ['id' => $producerId]
        );
        self::assertSame(0, (int) $count, "orphanRemoval doit vraiment supprimer la ligne, pas juste détacher l'association");
    }

    public function testProducerSettingIsCascadePersistedWithItsProducer(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('rel3'), $country);

        $setting = new \App\Entity\Producer\ProducerSetting();
        $setting->setPickupEnabled(true);
        // * setSettings() synchronise déjà le côté inverse (settings->setProducer($this)).
        $producer->setSettings($setting);

        // ! Pas de persist($setting) explicite, on vérifie que cascade: ['persist'] côté ProducerProfile::$settings suffit.
        $this->em->flush();

        $producerId = $producer->getId()->toRfc4122();
        $this->em->clear();

        $reloadedSetting = $this->em->find(\App\Entity\Producer\ProducerSetting::class, $producerId);
        self::assertNotNull($reloadedSetting, "cascade: ['persist'] doit avoir persisté ProducerSetting sans persist() explicite");
        self::assertTrue($reloadedSetting->isPickupEnabled());
    }
}
