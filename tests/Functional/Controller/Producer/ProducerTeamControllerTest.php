<?php

namespace App\Tests\Functional\Controller\Producer;

use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste GET/POST /api/producer/team, DELETE /api/producer/team/{userId} et le pendant côté invité
 * GET /api/team-invitations + POST /api/team-invitations/{producerId}/accept (cahier fonctionnel,
 * "Équipe exploitation" -- version restreinte, voir le docblock de ProducerTeamController).
 */
final class ProducerTeamControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    /**
     * @return array{0: string, 1: \App\Entity\Producer\ProducerProfile}
     */
    private function loginAsProducer(string $emailPrefix = 'producer'): array
    {
        $country = $this->makeCountry();
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

    public function testInviteExistingUserSucceedsAndAppearsPendingInList(): void
    {
        [$producerToken] = $this->loginAsProducer();
        $inviteeToken = $this->registerClientAndLogin('invitee');
        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$inviteeToken]);
        $inviteeEmail = json_decode($this->client->getResponse()->getContent(), true)['email'];

        $this->client->request('POST', '/api/producer/team', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$producerToken,
        ], content: json_encode(['email' => $inviteeEmail, 'role' => 'Vendeur']));
        self::assertResponseStatusCodeSame(201);
        $created = json_decode($this->client->getResponse()->getContent(), true);
        self::assertFalse($created['isActive']);
        self::assertSame('Vendeur', $created['role']);

        $this->client->request('GET', '/api/producer/team', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$producerToken]);
        self::assertResponseIsSuccessful();
        self::assertCount(1, json_decode($this->client->getResponse()->getContent(), true));
    }

    public function testInviteUnknownEmailReturns404(): void
    {
        [$producerToken] = $this->loginAsProducer();

        $this->client->request('POST', '/api/producer/team', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$producerToken,
        ], content: json_encode(['email' => 'personne@inexistant.test']));

        self::assertResponseStatusCodeSame(404);
    }

    public function testInviteDuplicateReturns409(): void
    {
        [$producerToken] = $this->loginAsProducer();
        $inviteeToken = $this->registerClientAndLogin('invitee');
        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$inviteeToken]);
        $inviteeEmail = json_decode($this->client->getResponse()->getContent(), true)['email'];

        $payload = json_encode(['email' => $inviteeEmail]);
        $this->client->request('POST', '/api/producer/team', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$producerToken,
        ], content: $payload);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/api/producer/team', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$producerToken,
        ], content: $payload);
        self::assertResponseStatusCodeSame(409);
    }

    public function testInviteeCanListAndAcceptInvitation(): void
    {
        [$producerToken, $producer] = $this->loginAsProducer();
        $inviteeToken = $this->registerClientAndLogin('invitee');
        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$inviteeToken]);
        $inviteeEmail = json_decode($this->client->getResponse()->getContent(), true)['email'];

        $this->client->request('POST', '/api/producer/team', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$producerToken,
        ], content: json_encode(['email' => $inviteeEmail]));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/team-invitations', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$inviteeToken]);
        self::assertResponseIsSuccessful();
        $invitations = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $invitations);
        self::assertFalse($invitations[0]['isActive']);

        $producerId = $producer->getId()->toRfc4122();
        $this->client->request('POST', '/api/team-invitations/'.$producerId.'/accept', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$inviteeToken]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/producer/team', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$producerToken]);
        $members = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($members[0]['isActive']);
        self::assertNotNull($members[0]['acceptedAt']);

        // * Accepter une deuxième fois une invitation déjà acceptée doit être rejeté.
        $this->client->request('POST', '/api/team-invitations/'.$producerId.'/accept', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$inviteeToken]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testAcceptRejectsUnknownInvitation(): void
    {
        $token = $this->registerClientAndLogin('client');

        $this->client->request('POST', '/api/team-invitations/00000000-0000-4000-8000-000000000000/accept', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRemoveMemberSucceeds(): void
    {
        [$producerToken] = $this->loginAsProducer();
        $inviteeToken = $this->registerClientAndLogin('invitee');
        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$inviteeToken]);
        $me = json_decode($this->client->getResponse()->getContent(), true);

        $this->client->request('POST', '/api/producer/team', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$producerToken,
        ], content: json_encode(['email' => $me['email']]));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('DELETE', '/api/producer/team/'.$me['id'], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$producerToken]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/producer/team', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$producerToken]);
        self::assertCount(0, json_decode($this->client->getResponse()->getContent(), true));
    }
}
