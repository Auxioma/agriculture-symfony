<?php

namespace App\Tests\Functional\Controller\Matching;

use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste POST /api/requests (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf -- premier volet
 * du bloc "demandes clients/producteur" : créer une demande. Les autres routes du bloc (liste, détail,
 * cancel/archive/duplicate, puis le côté producteur) suivront dans des fichiers séparés.
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

    public function testCreateRequestNotifiesClientAndMatchedProducers(): void
    {
        $token = $this->registerClientAndLogin();

        $country = $this->makeCountry();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $country);
        $this->makeProducerProduct($producer, $product, true);
        $this->em->flush();
        $this->setGeographyPoint('producer.producer_profiles', 'location', $producer->getId()->toRfc4122(), 2.35, 48.85);

        $this->client->request('POST', '/api/requests', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([
            'needType' => 'price_request',
            'productId' => $product->getId()->toRfc4122(),
            'latitude' => 48.86,
            'longitude' => 2.36,
        ]));
        self::assertResponseStatusCodeSame(201);

        // * Cahier fonctionnel "Demande envoyée" -- le client reçoit toujours cette notification, matché ou non.
        // * Compté par type plutôt que par user_id : registerClientAndLogin() ne retourne que le token,
        // * pas l'entité User, et ce test n'a besoin de rien de plus précis (un seul client dans ce test).
        $requestSentCount = (int) $this->em->getConnection()->fetchOne(
            "SELECT count(*) FROM notification.notifications WHERE type = 'request_sent'"
        );
        self::assertSame(1, $requestSentCount);

        // * Cahier fonctionnel "Nouvelle demande pertinente" -- le producteur matché (produit + zone) doit être notifié.
        $producerNotificationCount = (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM notification.notifications WHERE type = :type AND user_id = :userId',
            ['type' => 'new_relevant_request', 'userId' => $producer->getOwner()->getId()->toRfc4122()]
        );
        self::assertSame(1, $producerNotificationCount);
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

        // * customProduct satisfait chk_client_requests_product_or_custom (categoryId seul ne suffit pas,
        // * voir applyRequestData()) -- la requête doit donc échouer précisément sur la catégorie inconnue,
        // * pas sur l'absence de product/customProduct. UUID syntaxiquement valide mais absent de la base.
        $this->client->request('POST', '/api/requests', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([
            'needType' => 'price_request',
            'customProduct' => 'Peu importe',
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

    public function testUpdateRequestAppliesNewFields(): void
    {
        $token = $this->registerClientAndLogin();
        $this->client->request('POST', '/api/requests', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: json_encode(['needType' => 'price_request', 'customProduct' => 'Brouillon', 'message' => 'Ancien message']));
        $created = json_decode($this->client->getResponse()->getContent(), true);

        $this->client->request('PUT', '/api/client/requests/'.$created['id'], server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: json_encode([
            'needType' => 'quote_request',
            'customProduct' => 'Produit corrigé',
            'message' => 'Nouveau message',
        ]));

        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT need_type, custom_product, message FROM matching.client_requests WHERE id = :id',
            ['id' => $created['id']]
        );
        self::assertSame('quote_request', $row['need_type']);
        self::assertSame('Produit corrigé', $row['custom_product']);
        self::assertSame('Nouveau message', $row['message']);
    }

    // * applyRequestData() remet chaque association à null avant de la re-poser : une demande passée d'un
    // * produit catalogué à un customProduct doit donc perdre categoryId/productId, pas les garder en plus.
    public function testUpdateRequestClearsAssociationOmittedFromPayload(): void
    {
        $token = $this->registerClientAndLogin();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $this->em->flush();

        $this->client->request('POST', '/api/requests', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: json_encode(['needType' => 'price_request', 'categoryId' => $category->getId()->toRfc4122(), 'productId' => $product->getId()->toRfc4122()]));
        $created = json_decode($this->client->getResponse()->getContent(), true);

        $this->client->request('PUT', '/api/client/requests/'.$created['id'], server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: json_encode([
            'needType' => 'price_request',
            'customProduct' => 'Plus de catégorie ni produit',
        ]));

        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT category_id, product_id, custom_product FROM matching.client_requests WHERE id = :id',
            ['id' => $created['id']]
        );
        self::assertNull($row['category_id']);
        self::assertNull($row['product_id']);
        self::assertSame('Plus de catégorie ni produit', $row['custom_product']);
    }

    public function testUpdateRequestRejectsAccessToAnotherClientsRequest(): void
    {
        $tokenA = $this->registerClientAndLogin('clienta');
        $this->client->request('POST', '/api/requests', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokenA], content: json_encode(['needType' => 'price_request', 'customProduct' => 'Privée']));
        $created = json_decode($this->client->getResponse()->getContent(), true);

        $tokenB = $this->registerClientAndLogin('clientb');
        $this->client->request('PUT', '/api/client/requests/'.$created['id'], server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokenB], content: json_encode(['needType' => 'price_request', 'customProduct' => 'Piratée']));

        self::assertResponseStatusCodeSame(403);
    }

    public function testUpdateRequestReturns404ForUnknownId(): void
    {
        $token = $this->registerClientAndLogin();

        $this->client->request('PUT', '/api/client/requests/'.\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: json_encode(['needType' => 'price_request', 'customProduct' => 'Fantôme']));

        self::assertResponseStatusCodeSame(404);
    }

    public function testUpdateRequestRejectsWhenNoProductInformationGiven(): void
    {
        $token = $this->registerClientAndLogin();
        $this->client->request('POST', '/api/requests', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: json_encode(['needType' => 'price_request', 'customProduct' => 'À corriger']));
        $created = json_decode($this->client->getResponse()->getContent(), true);

        $this->client->request('PUT', '/api/client/requests/'.$created['id'], server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: json_encode(['needType' => 'price_request']));

        self::assertResponseStatusCodeSame(422);
    }

    // * Une fois qu'un producteur a répondu (RepliesReceived et au-delà), la demande n'est plus modifiable --
    // * choix délibéré non explicité par le cahier, voir le docblock de EDITABLE_STATUSES.
    public function testUpdateRequestIsRejectedOnceRepliesHaveBeenReceived(): void
    {
        $token = $this->registerClientAndLogin();
        $this->client->request('POST', '/api/requests', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: json_encode(['needType' => 'price_request', 'customProduct' => 'Déjà répondue']));
        $created = json_decode($this->client->getResponse()->getContent(), true);

        $this->em->getConnection()->executeStatement(
            "UPDATE matching.client_requests SET status = 'replies_received' WHERE id = :id",
            ['id' => $created['id']]
        );

        $this->client->request('PUT', '/api/client/requests/'.$created['id'], server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: json_encode(['needType' => 'price_request', 'customProduct' => 'Trop tard']));

        self::assertResponseStatusCodeSame(409);
    }
}