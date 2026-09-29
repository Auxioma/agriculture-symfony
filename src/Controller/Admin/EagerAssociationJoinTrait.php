<?php

namespace App\Controller\Admin;

use Doctrine\ORM\QueryBuilder;

/**
 * User (preference/producerProfile/presence) et ProducerProfile (settings) ont chacun des associations
 * OneToOne inverses sans colonne de clé étrangère locale -- Doctrine ne peut pas les proxifier paresseusement
 * et les résout donc par UNE REQUÊTE SÉPARÉE dès qu'un User ou un ProducerProfile est hydraté, MÊME quand ce
 * User/ProducerProfile lui-même vient d'un leftJoin/addSelect explicite dans createIndexQueryBuilder() (voir
 * AuditLogCrudController::createIndexQueryBuilder() pour le commentaire d'origine sur ce mécanisme). Sans ces
 * deux méthodes, joindre "client"/"actor"/"sender"/"owner"/"reviewedBy" (des User) ou "producer" (un
 * ProducerProfile) ne fait que déplacer le problème d'un niveau : une requête par ligne devient une requête
 * par utilisateur/producteur DISTINCT, mais les associations qu'ils embarquent restent N+1. Les deux méthodes
 * rejoignent explicitement toute la cascade dans LA MÊME requête, alias par alias pour éviter toute collision
 * quand plusieurs User/ProducerProfile sont joints dans une même createIndexQueryBuilder().
 */
trait EagerAssociationJoinTrait
{
    protected function joinUserEagerly(QueryBuilder $qb, string $userAlias): QueryBuilder
    {
        $producerAlias = $userAlias.'Producer';

        return $qb
            ->leftJoin($userAlias.'.preference', $userAlias.'Pref')->addSelect($userAlias.'Pref')
            ->leftJoin($userAlias.'.producerProfile', $producerAlias)->addSelect($producerAlias)
            ->leftJoin($producerAlias.'.settings', $producerAlias.'Settings')->addSelect($producerAlias.'Settings')
            ->leftJoin($userAlias.'.presence', $userAlias.'Presence')->addSelect($userAlias.'Presence');
    }

    protected function joinProducerEagerly(QueryBuilder $qb, string $producerAlias): QueryBuilder
    {
        return $qb
            ->leftJoin($producerAlias.'.settings', $producerAlias.'Settings')->addSelect($producerAlias.'Settings');
    }
}
