<?php

namespace App\Tests\Functional;

use App\Tests\DatabaseTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;


// Tests fonctionnels pour vérifier l'intégrité référentielle lors de la suppression des demandes clients, 
// y compris la validation des cascades et des contraintes de clé étrangère.


final class ReferentialIntegrityTest extends DatabaseTestCase
{
    use EntityFactoryTrait;

    public function testRemovingClientRequestCascadesToItsChildren(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $producer = $this->makeProducerProfile($this->makeUser('cascade-producer'), $this->makeCountry());
        $request = $this->makeClientRequest($this->makeUser('cascade-client'), $product);
        $this->em->flush();

        $match = new \App\Entity\Matching\RequestMatch();
        $match->setRequest($request);
        $match->setProducer($producer);
        $this->em->persist($match);

        $event = new \App\Entity\Matching\RequestEvent();
        $event->setRequest($request);
        $event->setEventType('created');
        $this->em->persist($event);

        $this->em->flush();

        $requestId = $request->getId()->toRfc4122();
        $matchId = $match->getId()->toRfc4122();
        $eventId = $event->getId()->toRfc4122();
        $this->em->clear();

        // * On recharge depuis la base : simule un vrai scénario où les collections ne sont pas déjà en mémoire.
        $reloadedRequest = $this->em->find(\App\Entity\Matching\ClientRequest::class, $requestId);
        $this->em->remove($reloadedRequest);
        $this->em->flush();

        $connection = $this->em->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM matching.client_requests WHERE id = :id', ['id' => $requestId]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM matching.request_matches WHERE id = :id', ['id' => $matchId]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM matching.request_events WHERE id = :id', ['id' => $eventId]));
    }

    // ! Vérifie que la base n'autorise pas la suppression d'un parent si des enfants existent, sans passer par l'ORM.
    public function testRawSqlDeleteOfClientRequestFailsWithoutRemovingChildrenFirst(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $producer = $this->makeProducerProfile($this->makeUser('cascade-producer2'), $this->makeCountry());
        $request = $this->makeClientRequest($this->makeUser('cascade-client2'), $product);
        $this->em->flush();

        $match = new \App\Entity\Matching\RequestMatch();
        $match->setRequest($request);
        $match->setProducer($producer);
        $this->em->persist($match);
        $this->em->flush();

        // ! Aucun ON DELETE CASCADE en base sur ces FK (vérifié dans la migration) sans passer par l'ORM
        $this->expectException(\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class);
        $this->em->getConnection()->executeStatement(
            'DELETE FROM matching.client_requests WHERE id = :id',
            ['id' => $request->getId()->toRfc4122()]
        );
    }


}