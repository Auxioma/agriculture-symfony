<?php

namespace App\Tests\Functional\Controller\Matching;

use App\Entity\Catalog\Unit;
use App\Entity\Catalog\Country;
use App\Entity\Identity\User;
use App\Entity\Matching\RequestMatch;
use App\Enum\MatchStatus;
use App\Entity\Producer\ProducerProfile;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\DefaultReferenceDataTrait;
use App\Tests\Fixtures\EntityFactoryTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

/**
 * Teste les 4 routes du bloc "producteur" des demandes (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf) : GET available, GET {id},
 * POST {id}/reply, POST {id}/decline. Couvre aussi l'ouverture implicite de conversation déclenchée par reply()
 * la vérification complète de la messagerie elle-même vit dans ConversationControllerTest.php.
 */

final class ProducerRequestControllerTest extends ApiTestCase
{
    use DefaultReferenceDataTrait;
    use EntityFactoryTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDefaultReferenceData($this->em->getConnection());
    }

    public function testAvailableRequestsShowsMatchedRequest(): void
    {
        $tokenClient = $this->registerClientAndLogin('client');

        // * FR est déjà inséré par setUp() : makeCountry() tenterait de le recréer (clé primaire dupliquée).
        $country = $this->em->find(Country::class, 'FR');
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        // * makeUserWithPassword() (pas makeUser()) : ce compte doit pouvoir se logger pour de vrai
        // * via /api/auth/login plus bas, contrairement aux autres fixtures qui ne s'authentifient jamais.
        $producerOwner = $this->makeUserWithPassword('producer', 'motdepasse123');
        $producerOwner->setRoles([User::ROLE_PRODUCER]);
        $producer = $this->makeProducerProfile($producerOwner, $country);
        $this->makeProducerProduct($producer, $product, true);
        $this->em->flush();

        $this->setGeographyPoint('producer.producer_profiles', 'location', $producer->getId()->toRfc4122(), 2.35, 48.85);

        $this->client->request('POST', '/api/requests', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient,
        ], content: json_encode([
            'needType' => 'price_request',
            'productId' => $product->getId()->toRfc4122(),
            'latitude' => 48.86,
            'longitude' => 2.36,
        ]));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $producerOwner->getEmail(),
            'password' => 'motdepasse123',
        ]));
        self::assertResponseIsSuccessful();
        $tokenProducer = json_decode($this->client->getResponse()->getContent(), true)['token'];

        $this->client->request('GET', '/api/producer/requests/available', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokenProducer]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame('price_request', $data[0]['needType']);
    }

    public function testAvailableRequestsRejectsAccountWithoutProducerProfile(): void
    {
        $token = $this->registerClientAndLogin();

        // * Un compte client "normal" (sans ProducerProfile) ne doit pas pouvoir consulter cette route.
        $this->client->request('GET', '/api/producer/requests/available', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(403);
    }

    private function setUpMatchedRequestAndProducer(): array
    {
        $tokenClient = $this->registerClientAndLogin('client');

        // * FR est déjà inséré par setUp() : makeCountry() tenterait de le recréer (clé primaire dupliquée).
        $country = $this->em->find(Country::class, 'FR');
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $producerOwner = $this->makeUserWithPassword('producer', 'motdepasse123');
        $producerOwner->setRoles([User::ROLE_PRODUCER]);
        $producer = $this->makeProducerProfile($producerOwner, $country);
        $this->makeProducerProduct($producer, $product, true);
        $this->em->flush();

        $this->setGeographyPoint('producer.producer_profiles', 'location', $producer->getId()->toRfc4122(), 2.35, 48.85);

        $this->client->request('POST', '/api/requests', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient,
        ], content: json_encode([
            'needType' => 'price_request',
            'productId' => $product->getId()->toRfc4122(),
            'latitude' => 48.86,
            'longitude' => 2.36,
        ]));
        // * Garde-fou : sans lui, un échec de création (422...) ferait planter chaque test plus loin, avec un message trompeur.
        self::assertResponseStatusCodeSame(201);
        $requestId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $producerOwner->getEmail(),
            'password' => 'motdepasse123',
        ]));
        $producerToken = json_decode($this->client->getResponse()->getContent(), true)['token'];

        // ! Les deux requêtes HTTP ci-dessus déclenchent un reset automatique de l'EntityManager entre
        // ! chaque requête (Symfony vide son identity map après chaque requête du client de test, pour
        // ! simuler l'isolation d'une vraie requête HTTP en production). $producer, persisté avant ces
        // ! requêtes, n'est donc plus "managed" du point de vue de $this->em : on le recharge pour repartir
        // ! avec une entité fraîche, sinon un persist() ultérieur qui le référence (ex. makeActiveSubscription)
        // ! plante avec "A new entity was found through the relationship...".
        $producer = $this->em->getRepository(ProducerProfile::class)->find($producer->getId());

        return [$requestId, $producerToken, $producer];
    }

    public function testGetRequestDetailForProducerReturnsData(): void
    {
        [$requestId, $producerToken] = $this->setUpMatchedRequestAndProducer();

        $this->client->request('GET', '/api/producer/requests/'.$requestId, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$producerToken]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($requestId, $data['id']);
        self::assertArrayHasKey('matchScore', $data);
    }

    public function testGetRequestDetailForProducerReturns404ForUnknownId(): void
    {
        [, $producerToken] = $this->setUpMatchedRequestAndProducer();

        $this->client->request('GET', '/api/producer/requests/'.Uuid::v4()->toRfc4122(), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$producerToken]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testGetRequestDetailForProducerRejectsUnmatchedRequest(): void
    {
        [, $producerToken] = $this->setUpMatchedRequestAndProducer();

        // * Demande sur un produit que ce producteur ne vend pas : elle existe (pas de 404), mais aucun
        // * RequestMatch n'aura été généré pour lui -- c'est ça qui doit donner le 403, pas un ID inconnu.
        $tokenOtherClient = $this->registerClientAndLogin('other-client');
        $otherCategory = $this->makeCategory('Autre catégorie');
        $unrelatedProduct = $this->makeProduct($otherCategory, 'Produit non vendu');
        $this->em->flush();

        $this->client->request('POST', '/api/requests', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenOtherClient,
        ], content: json_encode(['needType' => 'price_request', 'productId' => $unrelatedProduct->getId()->toRfc4122()]));
        self::assertResponseStatusCodeSame(201);
        $unmatchedRequestId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('GET', '/api/producer/requests/'.$unmatchedRequestId, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$producerToken]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testReplyToRequestSucceedsWhenProducerHasFeature(): void
    {
        [$requestId, $producerToken, $producer] = $this->setUpMatchedRequestAndProducer();
        $this->makeActiveSubscription($producer, features: ['reply_to_requests' => true]);
        $this->em->flush();

        $this->client->request('POST', '/api/producer/requests/'.$requestId.'/reply', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$producerToken,
        ], content: json_encode(['replyText' => 'Oui disponible', 'priceAmount' => '12.50']));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status, reply_text FROM matching.producer_replies WHERE id = :id',
            ['id' => $data['id']]
        );
        self::assertSame('sent', $row['status']);
        self::assertSame('Oui disponible', $row['reply_text']);
    }

    public function testReplyToRequestOpensConversation(): void
    {
        [$requestId, $producerToken, $producer] = $this->setUpMatchedRequestAndProducer();
        $this->makeActiveSubscription($producer, features: ['reply_to_requests' => true]);
        $this->em->flush();

        $this->client->request('POST', '/api/producer/requests/'.$requestId.'/reply', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$producerToken,
        ], content: json_encode(['replyText' => 'Oui disponible']));
        self::assertResponseStatusCodeSame(201);

        // * pas de route de création dédiée, la conversation doit exister dès la première réponse
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status FROM messaging.conversations WHERE request_id = :requestId',
            ['requestId' => $requestId]
        );
        self::assertNotFalse($row);
        self::assertSame('open', $row['status']);
    }

    private function requestStatus(string $requestId): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT status FROM matching.client_requests WHERE id = :id', ['id' => $requestId]);
    }

    // * Cahier fonctionnel, statuts de demande : "Réponses reçues - un ou plusieurs producteurs ont répondu". La demande reste "Envoyée" jusque-là.
    public function testSendingAReplyMovesTheRequestToRepliesReceived(): void
    {
        [$requestId, $producerToken, $producer] = $this->setUpMatchedRequestAndProducer();
        $this->makeActiveSubscription($producer, features: ['reply_to_requests' => true]);
        $this->em->flush();
        self::assertSame('sent', $this->requestStatus($requestId));

        $this->sendReply($requestId, $producerToken, ['replyText' => 'Oui disponible']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('replies_received', $this->requestStatus($requestId));
    }

    public function testADraftDoesNotChangeTheRequestStatus(): void
    {
        [$requestId, $producerToken] = $this->setUpMatchedRequestAndProducer();

        $this->sendReply($requestId, $producerToken, ['draft' => true, 'replyText' => 'Premier jet']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('sent', $this->requestStatus($requestId));
    }

    public function testDecliningDoesNotChangeTheRequestStatus(): void
    {
        [$requestId, $producerToken] = $this->setUpMatchedRequestAndProducer();

        $this->client->request('POST', '/api/producer/requests/'.$requestId.'/decline', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$producerToken]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('sent', $this->requestStatus($requestId));
    }

    // * même règle que ConversationController : jamais de retour en arrière depuis une conversation ouverte (ni depuis un statut terminal)
    public function testAReplyDoesNotTakeAnOpenConversationBackToRepliesReceived(): void
    {
        [$requestId, $producerToken, $producer] = $this->setUpMatchedRequestAndProducer();
        $this->makeActiveSubscription($producer, features: ['reply_to_requests' => true]);
        $this->em->flush();
        $this->em->getConnection()->executeStatement("UPDATE matching.client_requests SET status = 'conversation_open' WHERE id = :id", ['id' => $requestId]);

        $this->sendReply($requestId, $producerToken, ['replyText' => 'Oui disponible']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('conversation_open', $this->requestStatus($requestId));
    }

    public function testReplyToRequestNotifiesClient(): void
    {
        [$requestId, $producerToken, $producer] = $this->setUpMatchedRequestAndProducer();
        $this->makeActiveSubscription($producer, features: ['reply_to_requests' => true]);
        $this->em->flush();

        $clientId = $this->em->getConnection()->fetchOne(
            'SELECT client_id FROM matching.client_requests WHERE id = :id',
            ['id' => $requestId]
        );

        // * priceAmount présent -- cahier fonctionnel distingue "Devis reçu" de "Producteur ayant répondu" (sans prix).
        $this->client->request('POST', '/api/producer/requests/'.$requestId.'/reply', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$producerToken,
        ], content: json_encode(['replyText' => 'Oui disponible', 'priceAmount' => '12.50']));
        self::assertResponseStatusCodeSame(201);

        // * Filtré par type : setUpMatchedRequestAndProducer() a déjà généré une notification "request_sent"
        // * pour ce même client lors de la création de la demande -- il y a donc déjà 2 lignes en base ici.
        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT type, title FROM notification.notifications WHERE user_id = :userId AND type = 'quote_received'",
            ['userId' => $clientId]
        );
        self::assertNotFalse($row);
    }

    public function testReplyToRequestStoresQuantityAndSeparateConditions(): void
    {
        [$requestId, $producerToken, $producer] = $this->setUpMatchedRequestAndProducer();
        $this->makeActiveSubscription($producer, features: ['reply_to_requests' => true]);
        $this->em->flush();

        // * cahier fonctionnel, champs d'une réponse : quantité disponible, conditions de retrait et de livraison séparées
        $this->client->request('POST', '/api/producer/requests/'.$requestId.'/reply', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$producerToken,
        ], content: json_encode([
            'priceAmount' => '12',
            'availableQuantity' => '20',
            'pickupConditions' => 'Retrait à la ferme',
            'deliveryConditions' => 'Non proposée',
            'validUntil' => '2026-09-30',
        ]));
        self::assertResponseStatusCodeSame(201);

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT available_quantity, pickup_conditions, delivery_conditions, valid_until FROM matching.producer_replies WHERE request_id = :id',
            ['id' => $requestId]
        );
        self::assertEquals(20, $row['available_quantity']);
        self::assertSame('Retrait à la ferme', $row['pickup_conditions']);
        self::assertSame('Non proposée', $row['delivery_conditions']);
        self::assertSame('2026-09-30', $row['valid_until']);
    }

    public function testReplyToRequestRejectsNegativeAvailableQuantity(): void
    {
        [$requestId, $producerToken, $producer] = $this->setUpMatchedRequestAndProducer();
        $this->makeActiveSubscription($producer, features: ['reply_to_requests' => true]);
        $this->em->flush();

        $this->client->request('POST', '/api/producer/requests/'.$requestId.'/reply', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$producerToken,
        ], content: json_encode(['replyText' => 'Oui', 'availableQuantity' => '-5']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testDraftIsSavedWithoutSubscriptionNorConversationAndUpdatedInPlace(): void
    {
        [$requestId, $producerToken] = $this->setUpMatchedRequestAndProducer();
        $auth = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$producerToken];
        // * Pas d'abonnement : on peut préparer un brouillon (rien d'obligatoire), pas envoyer.

        $this->client->request('POST', '/api/producer/requests/'.$requestId.'/reply', server: $auth, content: json_encode(['draft' => true, 'replyText' => 'Premier jet']));
        self::assertResponseStatusCodeSame(201);
        $first = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('draft', $first['status']);

        $this->client->request('POST', '/api/producer/requests/'.$requestId.'/reply', server: $auth, content: json_encode(['draft' => true, 'priceAmount' => '9']));
        $second = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame($first['id'], $second['id']);
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT reply_text, price_amount FROM matching.producer_replies WHERE request_id = :id', ['id' => $requestId]);
        self::assertCount(1, $rows);
        // * le second enregistrement remplace le premier : le texte du premier jet n'est plus là
        self::assertNull($rows[0]['reply_text']);
        self::assertEquals(9, $rows[0]['price_amount']);
        self::assertFalse($this->em->getConnection()->fetchOne('SELECT 1 FROM messaging.conversations WHERE request_id = :id', ['id' => $requestId]));
    }

    public function testSendingReplacesTheDraftAndTheRequestCannotBeAnsweredTwice(): void
    {
        [$requestId, $producerToken, $producer] = $this->setUpMatchedRequestAndProducer();
        $this->makeActiveSubscription($producer, features: ['reply_to_requests' => true]);
        $this->em->flush();
        $auth = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$producerToken];

        $this->client->request('POST', '/api/producer/requests/'.$requestId.'/reply', server: $auth, content: json_encode(['draft' => true, 'replyText' => 'Brouillon']));
        $this->client->request('POST', '/api/producer/requests/'.$requestId.'/reply', server: $auth, content: json_encode(['replyText' => 'Version finale']));
        self::assertResponseStatusCodeSame(201);

        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT status, reply_text FROM matching.producer_replies WHERE request_id = :id', ['id' => $requestId]);
        self::assertSame([['status' => 'sent', 'reply_text' => 'Version finale']], $rows);

        $this->client->request('POST', '/api/producer/requests/'.$requestId.'/reply', server: $auth, content: json_encode(['replyText' => 'Encore']));
        self::assertResponseStatusCodeSame(409);
        $this->client->request('POST', '/api/producer/requests/'.$requestId.'/reply', server: $auth, content: json_encode(['draft' => true]));
        self::assertResponseStatusCodeSame(409);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function sendReply(string $requestId, string $producerToken, array $body): void
    {
        $this->client->request('POST', '/api/producer/requests/'.$requestId.'/reply', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$producerToken,
        ], content: json_encode($body));
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * Les messages du fil de la demande, du plus ancien au plus récent.
     *
     * @return list<array<string, mixed>>
     */
    private function threadOf(string $requestId): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT m.content, m.is_system, m.sender_id FROM messaging.messages m JOIN messaging.conversations c ON c.id = m.conversation_id WHERE c.request_id = :id ORDER BY m.created_at',
            ['id' => $requestId]
        );
    }

    // * Avant, la réponse ouvrait la conversation mais n'y postait rien : le client ouvrait un chat vide.
    public function testTheReplyTextIsTheFirstMessageOfTheThread(): void
    {
        [$requestId, $producerToken, $producer] = $this->setUpMatchedRequestAndProducer();
        $this->makeActiveSubscription($producer, features: ['reply_to_requests' => true]);
        $this->em->flush();

        $this->sendReply($requestId, $producerToken, ['replyText' => '  Oui disponible, venez quand vous voulez.  ']);

        $messages = $this->threadOf($requestId);
        self::assertCount(1, $messages);
        self::assertSame('Oui disponible, venez quand vous voulez.', $messages[0]['content']);
        self::assertFalse($messages[0]['is_system']);
        self::assertSame($producer->getOwner()->getId()->toRfc4122(), $messages[0]['sender_id']);
        // * Sans cela la conversation resterait mal triée dans la liste des conversations.
        self::assertNotNull($this->em->getConnection()->fetchOne('SELECT last_message_at FROM messaging.conversations WHERE request_id = :id', ['id' => $requestId]));
    }

    public function testAReplyWithTextAndPricePostsOnlyTheText(): void
    {
        [$requestId, $producerToken, $producer] = $this->setUpMatchedRequestAndProducer();
        $this->makeActiveSubscription($producer, features: ['reply_to_requests' => true]);
        $this->em->flush();

        $this->sendReply($requestId, $producerToken, ['replyText' => 'Oui disponible', 'priceAmount' => '12.50']);

        // * Le prix reste dans le devis : pas de second message qui le répéterait.
        self::assertSame(['Oui disponible'], array_column($this->threadOf($requestId), 'content'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function quoteSummaries(): array
    {
        return [
            'prix entier' => ['12', 'Devis : 12 €/kg'],
            'prix à décimales' => ['12.5', 'Devis : 12,50 €/kg'],
            'prix au-dessus de mille' => ['1250.5', 'Devis : 1 250,50 €/kg'],
        ];
    }

    // * Un prix seul : un message système résume le devis dans le fil (le cahier fonctionnel, "proposer un prix" parmi les actions du chat).
    #[DataProvider('quoteSummaries')]
    public function testAPriceOnlyReplyPostsASystemSummaryOfTheQuote(string $price, string $summary): void
    {
        [$requestId, $producerToken, $producer] = $this->setUpMatchedRequestAndProducer();
        $this->makeActiveSubscription($producer, features: ['reply_to_requests' => true]);
        $unit = (new Unit())->setCode('kg')->setLabel('Kilogramme');
        // * pas de Currency à créer : l'EUR est déjà en base, semée par setUp()
        $this->em->persist($unit);
        $this->em->flush();

        $this->sendReply($requestId, $producerToken, ['priceAmount' => $price, 'priceUnitId' => $unit->getId()->toRfc4122(), 'currencyCode' => 'EUR']);

        $messages = $this->threadOf($requestId);
        self::assertCount(1, $messages);
        self::assertSame($summary, $messages[0]['content']);
        self::assertTrue($messages[0]['is_system']);
        self::assertNull($messages[0]['sender_id']);
    }

    public function testTheQuoteSummaryOnlySaysWhatIsKnown(): void
    {
        [$requestId, $producerToken, $producer] = $this->setUpMatchedRequestAndProducer();
        $this->makeActiveSubscription($producer, features: ['reply_to_requests' => true]);
        $this->em->flush();

        $this->sendReply($requestId, $producerToken, ['priceAmount' => '9']);

        self::assertSame(['Devis : 9'], array_column($this->threadOf($requestId), 'content'));
    }

    public function testABlankTextPostsNothingUnlessThereIsAPrice(): void
    {
        [$requestId, $producerToken, $producer] = $this->setUpMatchedRequestAndProducer();
        $this->makeActiveSubscription($producer, features: ['reply_to_requests' => true]);
        $this->em->flush();

        $this->sendReply($requestId, $producerToken, ['replyText' => '   ']);

        self::assertSame([], $this->threadOf($requestId));
        // * La conversation s'ouvre quand même : la réponse est envoyée.
        self::assertNotFalse($this->em->getConnection()->fetchOne('SELECT 1 FROM messaging.conversations WHERE request_id = :id', ['id' => $requestId]));
    }

    public function testADraftPostsNothingAndSendingItPostsTheMessage(): void
    {
        [$requestId, $producerToken, $producer] = $this->setUpMatchedRequestAndProducer();
        $this->makeActiveSubscription($producer, features: ['reply_to_requests' => true]);
        $this->em->flush();

        $this->sendReply($requestId, $producerToken, ['draft' => true, 'replyText' => 'Brouillon']);
        self::assertSame([], $this->threadOf($requestId));

        $this->sendReply($requestId, $producerToken, ['replyText' => 'Version finale']);
        self::assertSame(['Version finale'], array_column($this->threadOf($requestId), 'content'));
    }

    // * Le client est déjà prévenu par "Devis reçu" ou "Un producteur a répondu" : pas de "nouveau message" en plus.
    public function testThePostedMessageDoesNotAddANewMessageNotification(): void
    {
        [$requestId, $producerToken, $producer] = $this->setUpMatchedRequestAndProducer();
        $this->makeActiveSubscription($producer, features: ['reply_to_requests' => true]);
        $this->em->flush();
        $clientId = $this->em->getConnection()->fetchOne('SELECT client_id FROM matching.client_requests WHERE id = :id', ['id' => $requestId]);

        $this->sendReply($requestId, $producerToken, ['replyText' => 'Oui disponible', 'priceAmount' => '12']);

        $types = $this->em->getConnection()->fetchFirstColumn('SELECT type FROM notification.notifications WHERE user_id = :id', ['id' => $clientId]);
        self::assertContains('quote_received', $types);
        self::assertNotContains('new_message', $types);
    }

    public function testReplyToRequestRejectsProducerWithoutFeature(): void
    {
        [$requestId, $producerToken] = $this->setUpMatchedRequestAndProducer();
        // * Pas d'abonnement du tout : producer_has_feature() doit renvoyer false.

        $this->client->request('POST', '/api/producer/requests/'.$requestId.'/reply', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$producerToken,
        ], content: json_encode(['replyText' => 'Oui disponible']));

        self::assertResponseStatusCodeSame(403);
    }

    public function testReplyToRequestRejectsEmptyPayload(): void
    {
        [$requestId, $producerToken, $producer] = $this->setUpMatchedRequestAndProducer();
        $this->makeActiveSubscription($producer, features: ['reply_to_requests' => true]);
        $this->em->flush();

        $this->client->request('POST', '/api/producer/requests/'.$requestId.'/reply', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$producerToken,
        ], content: json_encode([]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testDeclineRequestSucceedsWithoutFeatureCheck(): void
    {
        [$requestId, $producerToken] = $this->setUpMatchedRequestAndProducer();
        // * Pas d'abonnement : decline() ne vérifie pas producer_has_feature(), ça doit quand même réussir.

        $this->client->request('POST', '/api/producer/requests/'.$requestId.'/decline', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$producerToken]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $row = $this->em->getConnection()->fetchAssociative('SELECT status FROM matching.producer_replies WHERE id = :id', ['id' => $data['id']]);
        self::assertSame('declined', $row['status']);
    }

    /**
     * Un producteur connecté avec $count demandes proposées, de la mieux notée à la moins bien notée (score 90, 80, 70...).
     *
     * @return array{0: string, 1: list<string>} [jeton, identifiants des demandes dans l'ordre des scores]
     */
    private function setUpProducerWithMatches(int $count): array
    {
        $country = $this->em->find(Country::class, 'FR');
        $owner = $this->makeUserWithPassword('producer', 'motdepasse123');
        $owner->setRoles([User::ROLE_PRODUCER]);
        $producer = $this->makeProducerProfile($owner, $country);
        $client = $this->makeUser('client');

        $requestIds = [];
        for ($i = 0; $i < $count; ++$i) {
            $request = $this->makeClientRequest($client);
            $match = new RequestMatch();
            $match->setRequest($request);
            $match->setProducer($producer);
            $match->setStatus(MatchStatus::Proposed);
            $match->setScore((string) (90 - 10 * $i));
            $this->em->persist($match);
            $requestIds[] = $request->getId()->toRfc4122();
        }
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $owner->getEmail(),
            'password' => 'motdepasse123',
        ]));
        self::assertResponseIsSuccessful();

        return [json_decode($this->client->getResponse()->getContent(), true)['token'], $requestIds];
    }

    public function testAvailableRequestsArePaginatedAndTheTotalIsInTheHeader(): void
    {
        [$token] = $this->setUpProducerWithMatches(5);

        $this->client->request('GET', '/api/producer/requests/available?pageSize=2&page=2', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(2, $data);
        self::assertSame(['70.00', '60.00'], array_map(static fn (array $row) => $row['score'], $data));
        self::assertSame('5', $this->client->getResponse()->headers->get('X-Total-Count'));
    }

    public function testAvailableRequestsLastPageHoldsTheRemainder(): void
    {
        [$token] = $this->setUpProducerWithMatches(5);

        $this->client->request('GET', '/api/producer/requests/available?pageSize=2&page=3', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        self::assertCount(1, json_decode($this->client->getResponse()->getContent(), true));
        self::assertSame('5', $this->client->getResponse()->headers->get('X-Total-Count'));
    }

    public function testAvailableRequestsPageBeyondTheEndIsEmptyButKeepsTheTotal(): void
    {
        [$token] = $this->setUpProducerWithMatches(3);

        $this->client->request('GET', '/api/producer/requests/available?page=9', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($this->client->getResponse()->getContent(), true));
        self::assertSame('3', $this->client->getResponse()->headers->get('X-Total-Count'));
    }

    public function testAvailableRequestsAreBoundedEvenWithoutParameters(): void
    {
        [$token] = $this->setUpProducerWithMatches(21);

        $this->client->request('GET', '/api/producer/requests/available', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        self::assertCount(20, json_decode($this->client->getResponse()->getContent(), true));
        self::assertSame('21', $this->client->getResponse()->headers->get('X-Total-Count'));
    }

    #[DataProvider('invalidPaginationQueries')]
    public function testAvailableRequestsRejectInvalidPagination(string $query): void
    {
        [$token] = $this->setUpProducerWithMatches(1);

        $this->client->request('GET', '/api/producer/requests/available?'.$query, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidPaginationQueries(): iterable
    {
        yield 'page à zéro' => ['page=0'];
        yield 'pageSize illisible' => ['pageSize=abc'];
        yield 'pageSize au-dessus du plafond' => ['pageSize=101'];
    }

    public function testReceivedRequestsArePaginatedWithoutGapOrRepeatAndWithTheirStatus(): void
    {
        [$token, $requestIds] = $this->setUpProducerWithMatches(5);

        $seen = [];
        foreach ([1 => 2, 2 => 2, 3 => 1] as $page => $expectedCount) {
            $this->client->request('GET', '/api/producer/requests/received?pageSize=2&page='.$page, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

            self::assertResponseIsSuccessful();
            $data = json_decode($this->client->getResponse()->getContent(), true);
            self::assertCount($expectedCount, $data);
            self::assertSame('5', $this->client->getResponse()->headers->get('X-Total-Count'));
            // * toutes encore à traiter : le statut est calculé pour les demandes de la page
            foreach ($data as $row) {
                self::assertSame('new', $row['status']);
                $seen[] = $row['requestId'];
            }
        }

        // * le départage des égalités (même seconde) garde les pages stables : chaque demande une fois, aucune oubliée
        self::assertCount(5, array_unique($seen));
        self::assertEqualsCanonicalizing($requestIds, $seen);
    }

    public function testReceivedRequestsShowTheReplyOfARequestOnTheRequestedPage(): void
    {
        [$token, $requestIds] = $this->setUpProducerWithMatches(3);
        $this->client->request('POST', '/api/producer/requests/'.$requestIds[0].'/decline', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(201);

        // * une demande par page : la réponse n'est cherchée que pour la demande de la page, et la bonne est trouvée
        $declined = [];
        foreach ([1, 2, 3] as $page) {
            $this->client->request('GET', '/api/producer/requests/received?pageSize=1&page='.$page, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
            self::assertResponseIsSuccessful();
            $row = json_decode($this->client->getResponse()->getContent(), true)[0];
            if ($row['declined']) {
                $declined[] = $row;
            }
        }

        self::assertCount(1, $declined);
        self::assertSame($requestIds[0], $declined[0]['requestId']);
        self::assertSame('treated', $declined[0]['status']);
    }

    public function testReceivedRequestsRejectInvalidPagination(): void
    {
        [$token] = $this->setUpProducerWithMatches(1);

        $this->client->request('GET', '/api/producer/requests/received?pageSize=0', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(400);
    }
}
