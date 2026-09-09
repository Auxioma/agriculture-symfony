<?php

/**
 * Repository Doctrine pour OutboxEvent -- aucune requête personnalisée, hérite uniquement des
 * méthodes CRUD standard de ServiceEntityRepository (find/findBy/findOneBy/findAll). Les accès plus
 * complexes de ce projet passent directement par EntityManagerInterface (QueryBuilder ou SQL brut)
 * dans les contrôleurs plutôt que par des méthodes dédiées ici.
 */

namespace App\Repository\Audit;

use App\Entity\Audit\OutboxEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OutboxEvent>
 */
class OutboxEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OutboxEvent::class);
    }

    //    /**
    //     * @return OutboxEvent[] Returns an array of OutboxEvent objects
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

    //    public function findOneBySomeField($value): ?OutboxEvent
    //    {
    //        return $this->createQueryBuilder('o')
    //            ->andWhere('o.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
