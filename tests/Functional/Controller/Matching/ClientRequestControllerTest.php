<?php

namespace App\Tests\Functional;

use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste POST /api/requests (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf §20.4 -- premier volet
 * du bloc "demandes clients/producteur" : créer une demande. Les autres routes du bloc (liste, détail,
 * cancel/archive/duplicate, puis le côté producteur en §20.5) suivront dans des fichiers séparés.
 */

final class ClientRequestControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    public function testCreateRequestWithProductSucceeds(): void
    {
        $token = $this->registerClientAndLogin();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        // * flush() nécessaire ici (contrairement aux tests DatabaseTestCase habituels) : la requête HTTP
        // * qui suit tourne dans le même kernel/EntityManager (disableReboot), donc category/product doivent
        // * déjà être en base pour que le contrôleur les retrouve via $em->find().
        $this->em->flush();

        $this->client->request('POST', '/api/requests', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([
            'needType' => 'price_request',
            'productId' => $product->getId()->toRfc4122(),
        ]));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status, product_id FROM matching.client_requests WHERE id = :id',
            ['id' => $data['id']]
        );
        self::assertSame('sent', $row['status']);
        self::assertSame($product->getId()->toRfc4122(), $row['product_id']);
    }

    public function testCreateRequestWithCustomProductOnlySucceeds(): void
    {
        $token = $this->registerClientAndLogin();

        // * customProduct seul suffit : la règle du contrôleur n'exige category OU product OU customProduct,
        // * pas les trois -- ce test vérifie spécifiquement la branche "aucun des trois autres".
        $this->client->request('POST', '/api/requests', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([
            'needType' => 'quote_request',
            'customProduct' => 'Un produit hors catalogue',
        ]));

        self::assertResponseStatusCodeSame(201);
    }

    public function testCreateRequestRejectsWhenNoProductInformationGiven(): void
    {
        $token = $this->registerClientAndLogin();

        // ! Ce 422 vient d'une règle métier écrite à la main dans le contrôleur, pas de #[MapRequestPayload] :
        // ! categoryId/productId/customProduct sont tous individuellement optionnels dans le DTO.
        $this->client->request('POST', '/api/requests', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([
            'needType' => 'price_request',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateRequestRejectsUnknownCategory(): void
    {
        $token = $this->registerClientAndLogin();

        // * UUID syntaxiquement valide mais qui ne correspond à aucune Category en base : distingue ce cas
        // * (422 "catégorie inconnue") du test précédent (422 "aucune info produit fournie").
        $this->client->request('POST', '/api/requests', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([
            'needType' => 'price_request',
            'categoryId' => \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateRequestRejectsUnauthenticatedRequest(): void
    {
        // * Pas de header Authorization : le firewall "api" (access_control IS_AUTHENTICATED_FULLY) doit
        // * refuser avant même d'atteindre le contrôleur.
        $this->client->request('POST', '/api/requests', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'needType' => 'price_request',
            'customProduct' => 'Test',
        ]));

        self::assertResponseStatusCodeSame(401);
    }

    public function testCreateRequestStoresLocationWhenCoordinatesProvided(): void
    {
        $token = $this->registerClientAndLogin();

        $this->client->request('POST', '/api/requests', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([
            'needType' => 'price_request',
            'customProduct' => 'Test géoloc',
            'latitude' => 48.8566,
            'longitude' => 2.3522,
        ]));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // * Même vérification indépendante en SQL brut que PostGisMappingTest : prouve que le contrôleur a
        // * bien géocodé le bon point (format EWKT "SRID=4326;POINT(lon lat)"), pas juste "une valeur non nulle".
        $point = $this->em->getConnection()->fetchAssociative(
            'SELECT ST_X(location::geometry) AS lon, ST_Y(location::geometry) AS lat FROM matching.client_requests WHERE id = :id',
            ['id' => $data['id']]
        );
        self::assertEqualsWithDelta(2.3522, (float) $point['lon'], 0.0001);
        self::assertEqualsWithDelta(48.8566, (float) $point['lat'], 0.0001);
    }

    public function testListMyRequestsOnlyReturnsOwnRequests(): void
    {
        $tokenA = $this->registerClientAndLogin('clienta');
        $this->client->request('POST', '/api/requests', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenA,
        ], content: json_encode(['needType' => 'price_request', 'customProduct' => 'Demande A']));

        $tokenB = $this->registerClientAndLogin('clientb');
        $this->client->request('POST', '/api/requests', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenB,
        ], content: json_encode(['needType' => 'price_request', 'customProduct' => 'Demande B']));

        // On reste connecté en tant que client B et on liste ses demandes.
        $this->client->request('GET', '/api/client/requests', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokenB]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame('Demande B', $data[0]['customProduct']);
    }

    public function testGetRequestDetailReturnsFullData(): void
    {
        $token = $this->registerClientAndLogin();

        $this->client->request('POST', '/api/requests', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['needType' => 'price_request', 'customProduct' => 'Détail test', 'message' => 'Bonjour']));
        $created = json_decode($this->client->getResponse()->getContent(), true);

        $this->client->request('GET', '/api/client/requests/'.$created['id'], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Bonjour', $data['message']);
    }

    public function testGetRequestDetailRejectsAccessToAnotherClientsRequest(): void
    {
        $tokenA = $this->registerClientAndLogin('clienta');
        $this->client->request('POST', '/api/requests', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenA,
        ], content: json_encode(['needType' => 'price_request', 'customProduct' => 'Privée']));
        $created = json_decode($this->client->getResponse()->getContent(), true);

        $tokenB = $this->registerClientAndLogin('clientb');
        $this->client->request('GET', '/api/client/requests/'.$created['id'], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokenB]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testGetRequestDetailReturns404ForUnknownId(): void
    {
        $token = $this->registerClientAndLogin();

        $this->client->request('GET', '/api/client/requests/'.\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testCancelRequestUpdatesStatus(): void
    {
        $token = $this->registerClientAndLogin();
        $this->client->request('POST', '/api/requests', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: json_encode(['needType' => 'price_request', 'customProduct' => 'À annuler']));
        $created = json_decode($this->client->getResponse()->getContent(), true);

        $this->client->request('POST', '/api/client/requests/'.$created['id'].'/cancel', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative('SELECT status FROM matching.client_requests WHERE id = :id', ['id' => $created['id']]);
        self::assertSame('cancelled', $row['status']);
    }

    public function testCancelRequestRejectsAccessToAnotherClientsRequest(): void
    {
        $tokenA = $this->registerClientAndLogin('clienta');
        $this->client->request('POST', '/api/requests', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokenA], content: json_encode(['needType' => 'price_request', 'customProduct' => 'Privée']));
        $created = json_decode($this->client->getResponse()->getContent(), true);

        $tokenB = $this->registerClientAndLogin('clientb');
        $this->client->request('POST', '/api/client/requests/'.$created['id'].'/cancel', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokenB]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testArchiveRequestUpdatesStatus(): void
    {
        $token = $this->registerClientAndLogin();
        $this->client->request('POST', '/api/requests', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: json_encode(['needType' => 'price_request', 'customProduct' => 'À archiver']));
        $created = json_decode($this->client->getResponse()->getContent(), true);

        $this->client->request('POST', '/api/client/requests/'.$created['id'].'/archive', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative('SELECT status FROM matching.client_requests WHERE id = :id', ['id' => $created['id']]);
        self::assertSame('archived', $row['status']);
    }

    public function testDuplicateRequestCreatesANewOneWithSentStatus(): void
    {
        $token = $this->registerClientAndLogin();
        $this->client->request('POST', '/api/requests', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: json_encode(['needType' => 'price_request', 'customProduct' => 'Original', 'message' => 'Message original']));
        $original = json_decode($this->client->getResponse()->getContent(), true);

        $this->client->request('POST', '/api/client/requests/'.$original['id'].'/duplicate', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(201);
        $duplicate = json_decode($this->client->getResponse()->getContent(), true);

        self::assertNotSame($original['id'], $duplicate['id']);

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status, message, custom_product FROM matching.client_requests WHERE id = :id',
            ['id' => $duplicate['id']]
        );
        self::assertSame('sent', $row['status']);
        self::assertSame('Message original', $row['message']);
        self::assertSame('Original', $row['custom_product']);
    }
    
}