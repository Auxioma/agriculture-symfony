<?php

/**
 * Repository Doctrine pour WebhookEvent -- aucune requête personnalisée, hérite uniquement des
 * méthodes CRUD standard de ServiceEntityRepository (find/findBy/findOneBy/findAll). Les accès plus
 * complexes de ce projet passent directement par EntityManagerInterface (QueryBuilder ou SQL brut)
 * dans les contrôleurs plutôt que par des méthodes dédiées ici.
 */

namespace App\Repository\Billing;

use App\Entity\Billing\WebhookEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WebhookEvent>
 */
class WebhookEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebhookEvent::class);
    }

    //    /**
    //     * @return WebhookEvent[] Returns an array of WebhookEvent objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('w')
    //            ->andWhere('w.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('w.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?WebhookEvent
    //    {
    //        return $this->createQueryBuilder('w')
    //            ->andWhere('w.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
