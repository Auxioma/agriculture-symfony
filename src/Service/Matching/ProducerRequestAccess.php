<?php

namespace App\Service\Matching;

use App\Entity\Producer\ProducerProfile;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Un producteur voit une demande en entier et peut y répondre s'il a le droit "reply_to_requests" de son offre
 * (billing.producer_has_feature, la règle du MLD : abonnement actif ou d'essai, dans sa période). Sinon il n'a que l'aperçu
 * limité du cahier fonctionnel ("Producteur non abonné") : AvailableRequestPresenter cache ce qui est réservé aux abonnés.
 */
final readonly class ProducerRequestAccess
{
    // * un seul droit pour voir en entier et répondre : "Le producteur paie pour voir les demandes complètes et répondre"
    public const FEATURE = 'reply_to_requests';

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function hasFullAccess(ProducerProfile $producer): bool
    {
        return (bool) $this->em->getConnection()->fetchOne(
            'SELECT billing.producer_has_feature(:pid, :feature)',
            ['pid' => $producer->getId()->toRfc4122(), 'feature' => self::FEATURE]
        );
    }
}
