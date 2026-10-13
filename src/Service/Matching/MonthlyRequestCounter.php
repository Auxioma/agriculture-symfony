<?php

namespace App\Service\Matching;

use App\Entity\Matching\RequestMatch;
use App\Entity\Producer\ProducerProfile;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Nombre de demandes reçues par un producteur depuis le début du mois, à comparer au quota de son plan
 * (SubscriptionPlan::$limits['requests_per_month']). Lu par le tableau de bord et par la page "Mon abonnement".
 */
final readonly class MonthlyRequestCounter
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function count(ProducerProfile $producer): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(RequestMatch::class, 'm')
            ->where('m.producer = :producer')
            ->andWhere('m.createdAt >= :monthStart')
            ->setParameter('producer', $producer)
            ->setParameter('monthStart', new \DateTimeImmutable('first day of this month 00:00:00'))
            ->getQuery()
            ->getSingleScalarResult();
    }
}
