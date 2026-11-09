<?php

namespace App\Tests\Functional\Controller\Matching;

use App\Entity\Catalog\Country;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\DefaultReferenceDataTrait;
use App\Tests\Fixtures\EntityFactoryTrait;
use Symfony\Component\Uid\Uuid;

/**
 * Teste POST /api/requests (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf -- premier volet
 * du bloc "demandes clients/producteur" : créer une demande. Les autres routes du bloc (liste, détail,
 * cancel/archive/duplicate, puis le côté producteur) suivront dans des fichiers séparés.
 */

final class ClientRequestControllerTest extends ApiTestCase
{
    use DefaultReferenceDataTrait;
    use EntityFactoryTrait;

    protected function setUp(): void
    {
        parent::setUp();
        // * Le contrôleur applique EUR / FR par défaut : sans ces lignes en base, tout POST/PUT sans
        // * currencyCode/countryCode répond 422.
        $this->seedDefaultReferenceData($this->em->getConnection());
    }

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

        // * FR est déjà inséré par setUp() : makeCountry() tenterait de le recréer (clé primaire dupliquée).
        $country = $this->em->find(Country::class, 'FR');
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

        // * customProduct seul suffit : la règle du contrôleur exige productId OU customProduct (categoryId
        // * seul ne suffit pas, voir testCreateRequestRejectsCategoryOnly) -- ce test couvre la branche "customProduct seul".
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
            'categoryId' => Uuid::v4()->toRfc4122(),
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateRequestAppliesDefaultCurrencyAndCountry(): void
    {
        $token = $this->registerClientAndLogin();

        $this->client->request('POST', '/api/requests', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['needType' => 'price_request', 'customProduct' => 'Valeurs par défaut']));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT currency, country_code FROM matching.client_requests WHERE id = :id',
            ['id' => $data['id']]
        );
        self::assertSame('EUR', $row['currency']);
        self::assertSame('FR', $row['country_code']);
    }

    public function testCreateRequestRejectsUnknownCurrency(): void
    {
        $token = $this->registerClientAndLogin();

        $this->client->request('POST', '/api/requests', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['needType' => 'price_request', 'customProduct' => 'Test', 'currencyCode' => 'ZZZ']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateRequestRejectsUnknownCountry(): void
    {
        $token = $this->registerClientAndLogin();

        $this->client->request('POST', '/api/requests', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['needType' => 'price_request', 'customProduct' => 'Test', 'countryCode' => 'ZZ']));

        self::assertResponseStatusCodeSame(422);
    }

    // * Garde-fou : categoryId seul violerait chk_client_requests_product_or_custom (500 en base).
    // * Le contrôleur doit répondre 422 avant d'atteindre la base.
    public function testCreateRequestRejectsCategoryOnly(): void
    {
        $token = $this->registerClientAndLogin();
        $category = $this->makeCategory();
        $this->em->flush();

        $this->client->request('POST', '/api/requests', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['needType' => 'price_request', 'categoryId' => $category->getId()->toRfc4122()]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateRequestRejectsUnknownProduct(): void
    {
        $token = $this->registerClientAndLogin();

        $this->client->request('POST', '/api/requests', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['needType' => 'price_request', 'productId' => Uuid::v4()->toRfc4122()]));

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
        // * Format servi par ClientDashboardController::listMyRequests (qui a la priorité sur le même chemin) :
        // * {id, title, status, sentAt}. "title" = nom du produit (ou customProduct) + quantité éventuelle.
        // * array_column() : en cas d'échec, PHPUnit affiche le contenu réel plutôt qu'un simple "null".
        self::assertSame(['Demande B'], array_column($data, 'title'), json_encode($data));
        self::assertSame('sent', $data[0]['status']);
    }

    // * Le dashboard masque les brouillons et les demandes annulées (WHERE status NOT IN ('draft', 'cancelled')).
    public function testListMyRequestsExcludesCancelledRequests(): void
    {
        $token = $this->registerClientAndLogin();
        $this->client->request('POST', '/api/requests', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: json_encode(['needType' => 'price_request', 'customProduct' => 'À masquer']));
        $created = json_decode($this->client->getResponse()->getContent(), true);

        $this->client->request('POST', '/api/client/requests/'.$created['id'].'/cancel', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/client/requests', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($this->client->getResponse()->getContent(), true));
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

        $this->client->request('GET', '/api/client/requests/'.Uuid::v4()->toRfc4122(), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

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

        $this->client->request('PUT', '/api/client/requests/'.Uuid::v4()->toRfc4122(), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: json_encode(['needType' => 'price_request', 'customProduct' => 'Fantôme']));

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

    private function createRequests(string $token, int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $this->client->request('POST', '/api/requests', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: json_encode(['needType' => 'price_request', 'customProduct' => 'Demande '.$i]));
            self::assertResponseStatusCodeSame(201);
        }
    }

    public function testListMyRequestsIsPaginatedAndTheTotalIsInTheHeader(): void
    {
        $token = $this->registerClientAndLogin();
        $this->createRequests($token, 5);

        $seen = [];
        foreach ([1 => 2, 2 => 2, 3 => 1] as $page => $expectedCount) {
            $this->client->request('GET', '/api/client/requests?pageSize=2&page='.$page, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

            self::assertResponseIsSuccessful();
            $data = json_decode($this->client->getResponse()->getContent(), true);
            self::assertCount($expectedCount, $data, 'page '.$page);
            self::assertSame('5', $this->client->getResponse()->headers->get('X-Total-Count'));
            $seen = [...$seen, ...array_column($data, 'id')];
        }

        // * le départage des égalités (même seconde) garde les pages stables : chaque demande une fois, aucune oubliée
        self::assertCount(5, array_unique($seen));
    }

    public function testListMyRequestsIsBoundedEvenWithoutParameters(): void
    {
        $token = $this->registerClientAndLogin();
        $this->createRequests($token, 21);

        $this->client->request('GET', '/api/client/requests', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        self::assertCount(20, json_decode($this->client->getResponse()->getContent(), true));
        self::assertSame('21', $this->client->getResponse()->headers->get('X-Total-Count'));
    }

    public function testListMyRequestsTotalIgnoresCancelledRequests(): void
    {
        $token = $this->registerClientAndLogin();
        $this->createRequests($token, 3);
        $this->client->request('GET', '/api/client/requests', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $first = json_decode($this->client->getResponse()->getContent(), true)[0]['id'];
        $this->client->request('POST', '/api/client/requests/'.$first.'/cancel', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/client/requests?pageSize=1', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertSame('2', $this->client->getResponse()->headers->get('X-Total-Count'));
    }

    public function testListMyRequestsRejectsInvalidPagination(): void
    {
        $token = $this->registerClientAndLogin();

        foreach (['page=0', 'pageSize=0', 'pageSize=101', 'pageSize=abc'] as $query) {
            $this->client->request('GET', '/api/client/requests?'.$query, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
            self::assertResponseStatusCodeSame(400, $query);
        }
    }
}
