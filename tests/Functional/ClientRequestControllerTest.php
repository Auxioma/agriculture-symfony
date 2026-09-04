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
}