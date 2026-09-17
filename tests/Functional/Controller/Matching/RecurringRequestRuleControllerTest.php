<?php

namespace App\Tests\Functional\Controller\Matching;

use App\Entity\Matching\ClientRequest;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste POST/GET/PUT/DELETE /api/client/requests/{id}/recurring-rule (cahier fonctionnel -- "demande
 * récurrente", NeedType::Recurring).
 */

final class RecurringRequestRuleControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    /** @return array{0: string, 1: ClientRequest} */
    private function loginAsClient(string $emailPrefix = 'client'): array
    {
        $client = $this->makeUserWithPassword($emailPrefix, 'motdepasse123');
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $request = $this->makeClientRequest($client, $product);
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $client->getEmail(),
            'password' => 'motdepasse123',
        ]));
        $token = json_decode($this->client->getResponse()->getContent(), true)['token'];

        return [$token, $request];
    }

    public function testCreateRuleSucceedsAndComputesNextRunAt(): void
    {
        [$token, $request] = $this->loginAsClient();

        $this->client->request('POST', '/api/client/requests/'.$request->getId()->toRfc4122().'/recurring-rule', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['frequency' => 'weekly']));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('weekly', $data['frequency']);
        self::assertTrue($data['isActive']);
        self::assertNotNull($data['nextRunAt']);
    }

    public function testCreateRuleRejectsWhenOneAlreadyExists(): void
    {
        [$token, $request] = $this->loginAsClient();

        $this->client->request('POST', '/api/client/requests/'.$request->getId()->toRfc4122().'/recurring-rule', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['frequency' => 'weekly']));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/api/client/requests/'.$request->getId()->toRfc4122().'/recurring-rule', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['frequency' => 'monthly']));
        self::assertResponseStatusCodeSame(409);
    }

    public function testCreateRuleRejectsNonOwner(): void
    {
        [$token, $request] = $this->loginAsClient('owner');
        [$otherToken] = $this->loginAsClient('other');

        $this->client->request('POST', '/api/client/requests/'.$request->getId()->toRfc4122().'/recurring-rule', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$otherToken,
        ], content: json_encode(['frequency' => 'weekly']));

        self::assertResponseStatusCodeSame(403);
    }

    public function testGetRuleReturns404WhenNoneExists(): void
    {
        [$token, $request] = $this->loginAsClient();

        $this->client->request('GET', '/api/client/requests/'.$request->getId()->toRfc4122().'/recurring-rule', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testUpdateRuleChangesFrequencyAndEndAt(): void
    {
        [$token, $request] = $this->loginAsClient();

        $this->client->request('POST', '/api/client/requests/'.$request->getId()->toRfc4122().'/recurring-rule', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['frequency' => 'weekly']));

        $this->client->request('PUT', '/api/client/requests/'.$request->getId()->toRfc4122().'/recurring-rule', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['frequency' => 'monthly', 'endAt' => '2027-01-01']));

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('monthly', $data['frequency']);
        self::assertSame('2027-01-01', $data['endAt']);
    }

    public function testDeleteRuleSucceeds(): void
    {
        [$token, $request] = $this->loginAsClient();

        $this->client->request('POST', '/api/client/requests/'.$request->getId()->toRfc4122().'/recurring-rule', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['frequency' => 'weekly']));

        $this->client->request('DELETE', '/api/client/requests/'.$request->getId()->toRfc4122().'/recurring-rule', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/client/requests/'.$request->getId()->toRfc4122().'/recurring-rule', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
        self::assertResponseStatusCodeSame(404);
    }
}
