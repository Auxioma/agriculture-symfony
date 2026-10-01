<?php

namespace App\Tests\Functional\Controller\Producer;

use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Teste GET/POST /api/producer/verification-documents et GET/POST/PUT/DELETE
 * /api/producer/labels(/{labelId}) (cahier fonctionnel, "Vérification avancée :
 * labels/certifications avec preuve").
 */
final class ProducerVerificationControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    /**
     * @return array{0: string, 1: \App\Entity\Producer\ProducerProfile}
     */
    private function loginAsProducer(string $emailPrefix = 'producer', string $countryCode = 'FR'): array
    {
        $country = $this->makeCountry($countryCode);
        $owner = $this->makeUserWithPassword($emailPrefix, 'motdepasse123');
        $owner->setRoles([User::ROLE_PRODUCER]);
        $producer = $this->makeProducerProfile($owner, $country);
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $owner->getEmail(),
            'password' => 'motdepasse123',
        ]));
        $token = json_decode($this->client->getResponse()->getContent(), true)['token'];

        return [$token, $producer];
    }

    public function testUploadDocumentSucceedsAndAppearsInListWithSignedUrl(): void
    {
        [$token] = $this->loginAsProducer();
        $file = new UploadedFile(__DIR__.'/../../../Fixtures/files/sample.jpg', 'kbis.jpg', 'image/jpeg', null, true);

        $this->client->request('POST', '/api/producer/verification-documents', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], parameters: ['type' => 'kbis'], files: ['file' => $file]);
        self::assertResponseStatusCodeSame(201);
        $created = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('pending', $created['status']);

        $this->client->request('GET', '/api/producer/verification-documents', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame('kbis', $data[0]['type']);
        self::assertStringStartsWith('https://attachments.test/', $data[0]['url']);
    }

    public function testUploadDocumentRejectsUnsupportedMimeType(): void
    {
        [$token] = $this->loginAsProducer();
        $file = new UploadedFile(__DIR__.'/../../../Fixtures/files/sample.txt', 'sample.txt', 'text/plain', null, true);

        $this->client->request('POST', '/api/producer/verification-documents', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], files: ['file' => $file]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testClaimLabelWithoutDocumentSucceeds(): void
    {
        [$token] = $this->loginAsProducer();
        $label = $this->makeLabel();
        $this->em->flush();

        $this->client->request('POST', '/api/producer/labels', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['labelId' => $label->getId()->toRfc4122()]));
        self::assertResponseStatusCodeSame(201);
        $created = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNull($created['verifiedAt']);
        self::assertNull($created['documentId']);

        $this->client->request('GET', '/api/producer/labels', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertCount(1, json_decode($this->client->getResponse()->getContent(), true));
    }

    public function testClaimLabelRejectsUnknownLabel(): void
    {
        [$token] = $this->loginAsProducer();

        $this->client->request('POST', '/api/producer/labels', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['labelId' => '00000000-0000-4000-8000-000000000000']));
        self::assertResponseStatusCodeSame(404);
    }

    // * Un label désactivé depuis le back-office n'est plus proposé (GET /api/labels) : il ne peut plus non plus
    // * être revendiqué par un producteur qui connaîtrait encore son identifiant.
    public function testClaimLabelRejectsInactiveLabel(): void
    {
        [$token] = $this->loginAsProducer();
        $label = $this->makeLabel();
        $label->setIsActive(false);
        $this->em->flush();

        $this->client->request('POST', '/api/producer/labels', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['labelId' => $label->getId()->toRfc4122()]));
        self::assertResponseStatusCodeSame(404);
    }

    public function testClaimLabelRejectsDuplicate(): void
    {
        [$token] = $this->loginAsProducer();
        $label = $this->makeLabel();
        $this->em->flush();
        $payload = json_encode(['labelId' => $label->getId()->toRfc4122()]);

        $this->client->request('POST', '/api/producer/labels', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: $payload);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/api/producer/labels', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: $payload);
        self::assertResponseStatusCodeSame(409);
    }

    public function testAttachDocumentToClaimedLabelSucceeds(): void
    {
        [$token] = $this->loginAsProducer();
        $label = $this->makeLabel();
        $this->em->flush();

        $file = new UploadedFile(__DIR__.'/../../../Fixtures/files/sample.jpg', 'certif.jpg', 'image/jpeg', null, true);
        $this->client->request('POST', '/api/producer/verification-documents', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], files: ['file' => $file]);
        $documentId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', '/api/producer/labels', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['labelId' => $label->getId()->toRfc4122()]));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('PUT', '/api/producer/labels/'.$label->getId()->toRfc4122(), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['documentId' => $documentId]));
        self::assertResponseIsSuccessful();
        self::assertSame($documentId, json_decode($this->client->getResponse()->getContent(), true)['documentId']);
    }

    public function testAttachDocumentRejectsForeignDocument(): void
    {
        [$token] = $this->loginAsProducer();
        [$otherToken] = $this->loginAsProducer('other', 'BE');
        $label = $this->makeLabel();
        $this->em->flush();

        $file = new UploadedFile(__DIR__.'/../../../Fixtures/files/sample.jpg', 'certif.jpg', 'image/jpeg', null, true);
        $this->client->request('POST', '/api/producer/verification-documents', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$otherToken,
        ], files: ['file' => $file]);
        $foreignDocumentId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', '/api/producer/labels', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['labelId' => $label->getId()->toRfc4122()]));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('PUT', '/api/producer/labels/'.$label->getId()->toRfc4122(), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['documentId' => $foreignDocumentId]));
        self::assertResponseStatusCodeSame(404);
    }

    public function testRemoveLabelSucceeds(): void
    {
        [$token] = $this->loginAsProducer();
        $label = $this->makeLabel();
        $this->em->flush();

        $this->client->request('POST', '/api/producer/labels', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['labelId' => $label->getId()->toRfc4122()]));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('DELETE', '/api/producer/labels/'.$label->getId()->toRfc4122(), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/producer/labels', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertCount(0, json_decode($this->client->getResponse()->getContent(), true));
    }
}
