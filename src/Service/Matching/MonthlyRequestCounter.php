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
        // * mois calendaire (depuis le 1er), pas depuis le renouvellement : le quota est "par mois" meme pour un abo annuel,
        // * une fenetre qui suit le renouvellement serait d'un an pour eux. Choix du 15/10, le cahier donne pas de période
        // ? on compte les demandes recues (matchs), "réponses limitées" --> a voir avec le client
        // TODO rien ne bloque quand le quota est atteint, c'est juste affiché
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
