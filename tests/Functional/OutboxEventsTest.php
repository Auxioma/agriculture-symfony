<?php

namespace App\Tests\Functional;

use App\Tests\DatabaseTestCase;

final class OutboxEventsTest extends DatabaseTestCase
{
    public function testEnqueueEventInsertsAPendingOutboxRow(): void
    {
        $connection = $this->em->getConnection();
        $aggregateId = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();

        $eventId = $connection->fetchOne(
            "SELECT audit.enqueue_event(:type, :aggregateType, :aggregateId, :payload)",
            [
                'type' => 'request.sent',
                'aggregateType' => 'client_request',
                'aggregateId' => $aggregateId,
                'payload' => json_encode(['foo' => 'bar']),
            ]
        );

        self::assertNotEmpty($eventId, 'enqueue_event should return a UUID');

        $row = $connection->fetchAssociative(
            'SELECT * FROM audit.outbox_events WHERE id = :id',
            ['id' => $eventId]
        );

        self::assertNotFalse($row);
        self::assertSame('request.sent', $row['event_type']);
        self::assertSame('client_request', $row['aggregate_type']);
        self::assertSame($aggregateId, $row['aggregate_id']);
        self::assertSame('pending', $row['status']);
        self::assertNotNull($row['available_at']);
        self::assertNull($row['processed_at'], 'a freshly enqueued event should not be marked processed yet');
        self::assertSame(['foo' => 'bar'], json_decode($row['payload'], true));
    }

    public function testEnqueueEventDefaultsPayloadToEmptyObject(): void
    {
        $connection = $this->em->getConnection();
        $aggregateId = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();

        // Pas de 4e paramètre : on vérifie le DEFAULT '{}'::jsonb côté SQL.
        $eventId = $connection->fetchOne(
            "SELECT audit.enqueue_event(:type, :aggregateType, :aggregateId)",
            ['type' => 'ping', 'aggregateType' => 'test', 'aggregateId' => $aggregateId]
        );

        $row = $connection->fetchAssociative('SELECT payload FROM audit.outbox_events WHERE id = :id', ['id' => $eventId]);
        self::assertSame([], json_decode($row['payload'], true));
    }
}