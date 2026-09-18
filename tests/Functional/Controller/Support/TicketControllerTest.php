<?php

namespace App\Tests\Functional\Controller\Support;

use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Teste POST/GET /api/support/tickets, GET /api/support/tickets/{id}, POST .../messages et
 * .../attachments (cahier fonctionnel 24.1 V1, "Support avancé" : un client/producteur ouvre et
 * suit lui-même un ticket, jusque-là uniquement gérable côté back-office).
 */

final class TicketControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    /** @return array{0: string, 1: string} [ticketId, token] */
    private function createTicket(string $emailPrefix = 'client', string $subject = 'Problème de facturation', string $content = 'Ma facture ne correspond pas.'): array
    {
        $token = $this->registerClientAndLogin($emailPrefix);

        $this->client->request('POST', '/api/support/tickets', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['subject' => $subject, 'content' => $content]));
        self::assertResponseStatusCodeSame(201);
        $ticketId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        return [$ticketId, $token];
    }

    public function testCreateTicketSucceedsAndAppearsInList(): void
    {
        [, $token] = $this->createTicket();

        $this->client->request('GET', '/api/support/tickets', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame('Problème de facturation', $data[0]['subject']);
        self::assertSame('open', $data[0]['status']);
        self::assertNull($data[0]['priority']);
    }

    public function testCreateTicketRejectsBlankContent(): void
    {
        $token = $this->registerClientAndLogin();

        $this->client->request('POST', '/api/support/tickets', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['subject' => 'Sujet', 'content' => '']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testListTicketsReturnsOnlyMine(): void
    {
        [, $token] = $this->createTicket('mine');
        $this->createTicket('other');

        $this->client->request('GET', '/api/support/tickets', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
    }

    public function testGetTicketReturnsInitialMessage(): void
    {
        [$ticketId, $token] = $this->createTicket();

        $this->client->request('GET', '/api/support/tickets/'.$ticketId, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data['messages']);
        self::assertSame('Ma facture ne correspond pas.', $data['messages'][0]['content']);
    }

    public function testGetTicketRejectsNonOwner(): void
    {
        [$ticketId] = $this->createTicket('owner');
        $otherToken = $this->registerClientAndLogin('other');

        $this->client->request('GET', '/api/support/tickets/'.$ticketId, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$otherToken]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testGetTicketReturns404WhenUnknown(): void
    {
        $token = $this->registerClientAndLogin();

        $this->client->request('GET', '/api/support/tickets/'.\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testSendMessageAppendsToTicket(): void
    {
        [$ticketId, $token] = $this->createTicket();

        $this->client->request('POST', '/api/support/tickets/'.$ticketId.'/messages', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['content' => 'Un complément d\'information.']));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/support/tickets/'.$ticketId, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(2, $data['messages']);
        self::assertSame('Un complément d\'information.', $data['messages'][1]['content']);
    }

    public function testSendMessageRejectsNonOwner(): void
    {
        [$ticketId] = $this->createTicket('owner');
        $otherToken = $this->registerClientAndLogin('other');

        $this->client->request('POST', '/api/support/tickets/'.$ticketId.'/messages', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$otherToken,
        ], content: json_encode(['content' => 'Intrus.']));

        self::assertResponseStatusCodeSame(403);
    }

    public function testSendMessageRejectsWhenTicketClosed(): void
    {
        [$ticketId, $token] = $this->createTicket();
        $this->em->getConnection()->executeStatement(
            "UPDATE support.tickets SET status = 'closed' WHERE id = :id",
            ['id' => $ticketId]
        );

        $this->client->request('POST', '/api/support/tickets/'.$ticketId.'/messages', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['content' => 'Toujours là ?']));

        self::assertResponseStatusCodeSame(409);
    }

    // * Cahier fonctionnel -- un ticket "resolved" se rouvre automatiquement dès qu'un nouveau message
    // * arrive (voir TicketController::reopenOrRejectIfClosed()), contrairement à "closed" qui est définitif.
    public function testSendMessageReopensResolvedTicket(): void
    {
        [$ticketId, $token] = $this->createTicket();
        $this->em->getConnection()->executeStatement(
            "UPDATE support.tickets SET status = 'resolved' WHERE id = :id",
            ['id' => $ticketId]
        );

        $this->client->request('POST', '/api/support/tickets/'.$ticketId.'/messages', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['content' => 'En fait ce n\'est pas réglé.']));
        self::assertResponseStatusCodeSame(201);

        $status = $this->em->getConnection()->fetchOne('SELECT status FROM support.tickets WHERE id = :id', ['id' => $ticketId]);
        self::assertSame('open', $status);
    }

    public function testUploadAttachmentSucceedsAndAppearsWithSignedUrl(): void
    {
        [$ticketId, $token] = $this->createTicket();
        $file = new UploadedFile(__DIR__.'/../../../Fixtures/files/sample.jpg', 'sample.jpg', 'image/jpeg', null, true);

        $this->client->request('POST', '/api/support/tickets/'.$ticketId.'/attachments', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], files: ['file' => $file], content: null);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT file_name, mime_type FROM support.ticket_attachments WHERE id = :id',
            ['id' => $data['attachmentId']]
        );
        self::assertNotFalse($row);
        self::assertSame('sample.jpg', $row['file_name']);

        $this->client->request('GET', '/api/support/tickets/'.$ticketId, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $ticket = json_decode($this->client->getResponse()->getContent(), true);
        $messageWithAttachment = end($ticket['messages']);
        self::assertCount(1, $messageWithAttachment['attachments']);
        self::assertStringStartsWith('https://attachments.test/', $messageWithAttachment['attachments'][0]['url']);
    }

    public function testUploadAttachmentRejectsUnsupportedMimeType(): void
    {
        [$ticketId, $token] = $this->createTicket();
        $file = new UploadedFile(__DIR__.'/../../../Fixtures/files/sample.txt', 'sample.txt', 'text/plain', null, true);

        $this->client->request('POST', '/api/support/tickets/'.$ticketId.'/attachments', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], files: ['file' => $file], content: null);

        self::assertResponseStatusCodeSame(422);
    }

    public function testUploadAttachmentRejectsNonOwner(): void
    {
        [$ticketId] = $this->createTicket('owner');
        $otherToken = $this->registerClientAndLogin('other');
        $file = new UploadedFile(__DIR__.'/../../../Fixtures/files/sample.jpg', 'sample.jpg', 'image/jpeg', null, true);

        $this->client->request('POST', '/api/support/tickets/'.$ticketId.'/attachments', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$otherToken,
        ], files: ['file' => $file], content: null);

        self::assertResponseStatusCodeSame(403);
    }
}
