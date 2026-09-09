<?php

/**
 * Repository Doctrine pour BlockedUser -- aucune requête personnalisée, hérite uniquement des
 * méthodes CRUD standard de ServiceEntityRepository (find/findBy/findOneBy/findAll). Les accès plus
 * complexes de ce projet passent directement par EntityManagerInterface (QueryBuilder ou SQL brut)
 * dans les contrôleurs plutôt que par des méthodes dédiées ici.
 */

namespace App\Repository\Messaging;

use App\Entity\Messaging\BlockedUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BlockedUser>
 */
class BlockedUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlockedUser::class);
    }

    //    /**
    //     * @return BlockedUser[] Returns an array of BlockedUser objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('b')
    //            ->andWhere('b.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('b.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?BlockedUser
    //    {
    //        return $this->createQueryBuilder('b')
    //            ->andWhere('b.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
