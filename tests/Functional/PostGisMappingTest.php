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

final class PostGisMappingTest extends DatabaseTestCase
{
    use EntityFactoryTrait;

    public function testProducerProfileLocationRoundTripsThroughTheOrm(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('geo'), $country);

        // * Le type Doctrine geography attend une chaîne EWKT en écriture (ST_GeographyFromText),
        $producer->setLocation('SRID=4326;POINT(2.3522 48.8566)');
        $this->em->flush();

        $id = $producer->getId()->toRfc4122();
        $this->em->clear();

        // *Vérification en SQL brut --> prouve que l'écriture ORM a bien géocodé le bon point
        $point = $this->em->getConnection()->fetchAssociative(
            'SELECT ST_X(location::geometry) AS lon, ST_Y(location::geometry) AS lat FROM producer.producer_profiles WHERE id = :id',
            ['id' => $id]
        );
        self::assertEqualsWithDelta(2.3522, (float) $point['lon'], 0.0001);
        self::assertEqualsWithDelta(48.8566, (float) $point['lat'], 0.0001);

        $reloaded = $this->em->find(\App\Entity\Producer\ProducerProfile::class, $id);
        self::assertNotNull($reloaded->getLocation());
        self::assertStringContainsString('POINT', $reloaded->getLocation());
    }

    public function testDeliveryZonePolygonRoundTripsThroughTheOrm(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('geo2'), $country);
        $this->em->flush();

        $zone = new \App\Entity\Producer\DeliveryZone();
        $zone->setProducer($producer);
        $zone->setZone('SRID=4326;POLYGON((2.25 48.80, 2.45 48.80, 2.45 48.90, 2.25 48.90, 2.25 48.80))');
        $this->em->persist($zone);
        $this->em->flush();

        $id = $zone->getId()->toRfc4122();
        $this->em->clear();

        // *preuve que la géométrie écrite est correcte, pas juste non nulle
        $contains = $this->em->getConnection()->fetchOne(
            "SELECT ST_Contains(zone::geometry, ST_GeomFromText('POINT(2.35 48.85)', 4326)) FROM producer.delivery_zones WHERE id = :id",
            ['id' => $id]
        );
        self::assertTrue((bool) $contains);

        $reloaded = $this->em->find(\App\Entity\Producer\DeliveryZone::class, $id);
        self::assertNotNull($reloaded->getZone());
        self::assertStringContainsString('POLYGON', $reloaded->getZone());
    }

    public function testClientRequestLocationRoundTripsThroughTheOrm(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $request = $this->makeClientRequest($this->makeUser('geo3'), $product);

        $request->setLocation('SRID=4326;POINT(2.3600 48.8600)');
        $this->em->flush();

        $id = $request->getId()->toRfc4122();
        $this->em->clear();

        $point = $this->em->getConnection()->fetchAssociative(
            'SELECT ST_X(location::geometry) AS lon, ST_Y(location::geometry) AS lat FROM matching.client_requests WHERE id = :id',
            ['id' => $id]
        );
        self::assertEqualsWithDelta(2.3600, (float) $point['lon'], 0.0001);
        self::assertEqualsWithDelta(48.8600, (float) $point['lat'], 0.0001);

        $reloaded = $this->em->find(\App\Entity\Matching\ClientRequest::class, $id);
        self::assertNotNull($reloaded->getLocation());
        self::assertStringContainsString('POINT', $reloaded->getLocation());
    }
}
