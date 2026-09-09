<?php

/**
 * Repository Doctrine pour OpeningHour -- aucune requête personnalisée, hérite uniquement des
 * méthodes CRUD standard de ServiceEntityRepository (find/findBy/findOneBy/findAll). Les accès plus
 * complexes de ce projet passent directement par EntityManagerInterface (QueryBuilder ou SQL brut)
 * dans les contrôleurs plutôt que par des méthodes dédiées ici.
 */

namespace App\Repository\Producer;

use App\Entity\Producer\OpeningHour;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OpeningHour>
 */
class OpeningHourRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OpeningHour::class);
    }

    //    /**
    //     * @return OpeningHour[] Returns an array of OpeningHour objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('o')
    //            ->andWhere('o.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('o.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?OpeningHour
    //    {
    //        return $this->createQueryBuilder('o')
    //            ->andWhere('o.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
