<?php

/**
 * Repository Doctrine pour QuickReply -- aucune requête personnalisée, hérite uniquement des
 * méthodes CRUD standard de ServiceEntityRepository (find/findBy/findOneBy/findAll). Les accès plus
 * complexes de ce projet passent directement par EntityManagerInterface (QueryBuilder ou SQL brut)
 * dans les contrôleurs plutôt que par des méthodes dédiées ici.
 */

namespace App\Repository\Producer;

use App\Entity\Producer\QuickReply;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuickReply>
 */
class QuickReplyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuickReply::class);
    }

    //    /**
    //     * @return QuickReply[] Returns an array of QuickReply objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('q')
    //            ->andWhere('q.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('q.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?QuickReply
    //    {
    //        return $this->createQueryBuilder('q')
    //            ->andWhere('q.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
