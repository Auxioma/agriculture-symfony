<?php

namespace App\Tests\Functional\Controller\Auth;

use App\Tests\ApiTestCase;

/**
 * Teste la pagination de GET /api/me/addresses (cahier DevOps : pagination des listes de l'API) : l'adresse par défaut
 * d'abord, des pages stables, un total en en-tête.
 */
final class AddressPaginationTest extends ApiTestCase
{
    private function addAddress(string $token, string $label, bool $isDefault = false): string
    {
        $this->client->request('POST', '/api/me/addresses', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: json_encode([
            'label' => $label,
            'address1' => '1 rue des Vergers',
            'city' => 'Lyon',
            'postalCode' => '69001',
            'countryCode' => 'FR',
            'isDefault' => $isDefault,
        ]));
        self::assertResponseStatusCodeSame(201);

        return json_decode($this->client->getResponse()->getContent(), true)['id'];
    }

    public function testTheListIsPaginatedDefaultAddressFirstWithTheTotalInTheHeader(): void
    {
        $token = $this->registerClientAndLogin('client');
        $this->addAddress($token, 'Maison');
        $this->addAddress($token, 'Bureau');
        $this->addAddress($token, 'Ferme');
        $defaultId = $this->addAddress($token, 'Résidence principale', isDefault: true);
        $this->addAddress($token, 'Chalet');

        $seen = [];
        foreach ([1 => 2, 2 => 2, 3 => 1] as $page => $expectedCount) {
            $this->client->request('GET', '/api/me/addresses?pageSize=2&page='.$page, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

            self::assertResponseIsSuccessful();
            $data = json_decode($this->client->getResponse()->getContent(), true);
            self::assertCount($expectedCount, $data, 'page '.$page);
            self::assertSame('5', $this->client->getResponse()->headers->get('X-Total-Count'));
            if (1 === $page) {
                self::assertSame($defaultId, $data[0]['id']);
            }
            $seen = [...$seen, ...array_column($data, 'id')];
        }
        self::assertCount(5, array_unique($seen));
    }

    public function testTheListRejectsInvalidPagination(): void
    {
        $token = $this->registerClientAndLogin('client');

        foreach (['page=0', 'pageSize=101', 'pageSize=abc'] as $query) {
            $this->client->request('GET', '/api/me/addresses?'.$query, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
            self::assertResponseStatusCodeSame(400, $query);
        }
    }
}
