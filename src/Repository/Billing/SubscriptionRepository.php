<?php

/**
 * Repository Doctrine pour Subscription -- les méthodes CRUD standard de ServiceEntityRepository
 * (find/findBy/findOneBy/findAll), plus findCurrentOf() que partagent la page "Mon abonnement" et le tableau de
 * bord. Les autres accès passent directement par EntityManagerInterface (QueryBuilder ou SQL brut) dans les
 * contrôleurs plutôt que par des méthodes dédiées ici.
 */

namespace App\Repository\Billing;

use App\Entity\Billing\Subscription;
use App\Entity\Producer\ProducerProfile;
use App\Enum\SubscriptionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    // * en vigueur = donne encore des droits (la même liste que l'index unique des abonnements)
    private const IN_FORCE = [SubscriptionStatus::Active, SubscriptionStatus::Trialing, SubscriptionStatus::PastDue];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    /**
     * L'abonnement à montrer au producteur : celui qui est en vigueur, sinon le plus récent (annulé ou expiré). Le plus
     * récent tout court ne suffit pas : un paiement en double est gardé "cancelled" et enregistré après l'abonnement qu'on
     * garde, il le cacherait et le producteur se verrait sans abonnement.
     */
    public function findCurrentOf(ProducerProfile $producer): ?Subscription
    {
        return $this->findOneBy(['producer' => $producer, 'status' => self::IN_FORCE], ['createdAt' => 'DESC'])
            ?? $this->findOneBy(['producer' => $producer], ['createdAt' => 'DESC']);
    }

    //    /**
    //     * @return Subscription[] Returns an array of Subscription objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('s')
    //            ->andWhere('s.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('s.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Subscription
    //    {
    //        return $this->createQueryBuilder('s')
    //            ->andWhere('s.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
