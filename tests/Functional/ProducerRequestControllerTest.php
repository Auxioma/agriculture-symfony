<?php

namespace App\Tests\Functional;

use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste GET /api/producer/requests/available et GET /api/producer/requests/{id} (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf §20.5).
 * reply/decline suivront dans un round séparé.
 */
final class ProducerRequestControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    public function testAvailableRequestsShowsMatchedRequest(): void
    {
        $tokenClient = $this->registerClientAndLogin('client');

        $country = $this->makeCountry();
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
}
