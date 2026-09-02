<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class DatabaseTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    protected function setGeographyPoint(string $table, string $column, string $id, float $lon, float $lat): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE {$table} SET {$column} = ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)::geography WHERE id = :id",
            ['lon' => $lon, 'lat' => $lat, 'id' => $id]
        );
    }
}
