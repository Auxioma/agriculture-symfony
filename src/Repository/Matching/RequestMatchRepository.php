<?php

/**
 * Repository Doctrine pour RequestMatch -- une seule requête personnalisée (createAvailableQueryBuilder,
 * partagée par la liste des demandes disponibles et le dashboard producteur), le reste hérite des
 * méthodes CRUD standard de ServiceEntityRepository (find/findBy/findOneBy/findAll). Les accès plus
 * complexes de ce projet passent directement par EntityManagerInterface (QueryBuilder ou SQL brut)
 * dans les contrôleurs plutôt que par des méthodes dédiées ici.
 */

namespace App\Repository\Matching;

use App\Entity\Matching\ProducerReply;
use App\Entity\Matching\RequestMatch;
use App\Entity\Producer\ProducerProfile;
use App\Enum\MatchStatus;
use App\Enum\ReplyStatus;
use App\Enum\RequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RequestMatch>
 */
class RequestMatchRepository extends ServiceEntityRepository
{
    private const OPEN_REQUEST_STATUSES = [
        RequestStatus::Sent,
        RequestStatus::WaitingReplies,
        RequestStatus::RepliesReceived,
        RequestStatus::ConversationOpen,
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RequestMatch::class);
    }

    // * "Disponible" = correspondance encore proposée ou débloquée, sur une demande encore ouverte et à laquelle le
    // * producteur n'a pas encore répondu (réponse envoyée ou refus : répondre ne change pas le statut de la
    // * correspondance, d'où la sous-requête). L'appelant ajoute son tri (alias m = correspondance, r = demande).
    public function createAvailableQueryBuilder(ProducerProfile $producer): QueryBuilder
    {
        return $this->createQueryBuilder('m')
            ->select('m', 'r')
            ->join('m.request', 'r')
            ->where('m.producer = :producer')
            ->andWhere('m.status IN (:matchStatuses)')
            ->andWhere('r.status IN (:requestStatuses)')
            ->andWhere('NOT EXISTS (SELECT pr.id FROM '.ProducerReply::class.' pr WHERE pr.request = r AND pr.producer = m.producer AND pr.status <> :draft)')
            ->setParameter('draft', ReplyStatus::Draft->value)
            ->setParameter('producer', $producer)
            ->setParameter('matchStatuses', [MatchStatus::Proposed->value, MatchStatus::Unlocked->value])
            ->setParameter('requestStatuses', array_map(static fn (RequestStatus $s) => $s->value, self::OPEN_REQUEST_STATUSES));
    }

    //    /**
    //     * @return RequestMatch[] Returns an array of RequestMatch objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('r.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?RequestMatch
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
