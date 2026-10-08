<?php

/**
 * Repository Doctrine pour ProducerProduct -- méthodes CRUD standard de ServiceEntityRepository 
 * (find/findBy/findOneBy/findAll) + une requête personnalisée (countProducersByCategory). Les accès 
 * plus complexes de ce projet passent directement par EntityManagerInterface (QueryBuilder ou SQL 
 * brut) dans les contrôleurs plutôt que par des méthodes dédiées ici.
 */

namespace App\Repository\Producer;

use App\Entity\Producer\ProducerProduct;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProducerProduct>
 */
class ProducerProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProducerProduct::class);
    }

    //    /**
    //     * @return ProducerProduct[] Returns an array of ProducerProduct objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('p.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?ProducerProduct
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    /** @return array<string, int>  categoryId => nombre de producteurs */
    public function countProducersByCategory(): array
    {
        // $rows = $this->createQueryBuilder('pr')
        //     ->select('IDENTITY(p.category) AS categoryId', 'COUNT(DISTINCT pp.id) AS producerCount')
        //     ->join('pr.product', 'p')
        //     ->join('pr.producer', 'pp')
        //     ->where('pr.isActive = true')
        //     ->andWhere('pp.isActive = true')
        //     ->andWhere('pp.verificationStatus = :status')
        //     ->setParameter('status', 'verified') // si c'est un enum PHP : ProducerStatus::Verified
        //     ->groupBy('p.category')
        //     ->getQuery()
        //     ->getArrayResult();

        // $counts = [];
        // foreach ($rows as $row) {
        //     $counts[$row['categoryId']] = (int) $row['producerCount'];
        // }

        // return $counts;

        $sql = <<<'SQL'
            WITH RECURSIVE category_tree (ancestor_id, node_id) AS (
                SELECT id, id FROM catalog.categories WHERE is_active = true
                UNION
                SELECT t.ancestor_id, c.id
                FROM category_tree t
                JOIN catalog.categories c ON c.parent_id = t.node_id
                WHERE c.is_active = true
            )
            SELECT t.ancestor_id AS category_id, COUNT(DISTINCT pp.id) AS producer_count
            FROM category_tree t
            JOIN catalog.products p ON p.category_id = t.node_id
            JOIN producer.producer_products pr ON pr.product_id = p.id AND pr.is_active = true
            JOIN producer.producer_profiles pp ON pp.id = pr.producer_id
            WHERE pp.is_active = true
            GROUP BY t.ancestor_id
            SQL;
 
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql);
 
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['category_id']] = (int) $row['producer_count'];
        }
 
        return $counts;
    }
}