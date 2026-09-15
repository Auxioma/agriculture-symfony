<?php

namespace App\Tests\Functional\Controller\Trust;

use App\Entity\Identity\User;
use App\Entity\Producer\ProducerProfile;
use App\Entity\Trust\Review;
use App\Enum\ReviewStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste POST /api/reviews, GET /api/producers/{id}/reviews et POST /api/reviews/{id}/response
 * (cahier fonctionnel).
 */
final class ReviewControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    /**
     * Même flux réel que ConversationControllerTest::setUpOpenConversation() (demande, matching, réponse
     * producteur) : un avis exige une Conversation existante, donc l'installer directement en fixture
     * contournerait la garde qu'on veut justement tester.
     *
     * @return array{0: string, 1: string, 2: string, 3: string} [requestId, tokenClient, tokenProducer, producerId]
     */
    private function setUpRequestWithProducerReply(): array
    {
        $tokenClient = $this->registerClientAndLogin('client');

        $country = $this->makeCountry();
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
        $requestId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $producerOwner->getEmail(),
            'password' => 'motdepasse123',
        ]));
        $tokenProducer = json_decode($this->client->getResponse()->getContent(), true)['token'];

        $producer = $this->em->getRepository(ProducerProfile::class)->find($producer->getId());
        $this->makeActiveSubscription($producer, features: ['reply_to_requests' => true]);
        $this->em->flush();

        $this->client->request('POST', '/api/producer/requests/'.$requestId.'/reply', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenProducer,
        ], content: json_encode(['replyText' => 'Oui disponible']));
        self::assertResponseStatusCodeSame(201);

        return [$requestId, $tokenClient, $tokenProducer, $producer->getId()->toRfc4122()];
    }

    public function testCreateReviewSucceedsAfterRealInteraction(): void
    {
        [$requestId, $tokenClient, , $producerId] = $this->setUpRequestWithProducerReply();

        $this->client->request('POST', '/api/reviews', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient,
        ], content: json_encode([
            'requestId' => $requestId,
            'producerId' => $producerId,
            'rating' => 5,
            'comment' => 'Très bon contact, produits de qualité.',
        ]));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('pending', $data['status']);

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status, rating FROM trust.reviews WHERE id = :id',
            ['id' => $data['id']]
        );
        self::assertSame('pending', $row['status']);
        self::assertSame(5, $row['rating']);
    }

    public function testCreateReviewRejectsWithoutPriorInteraction(): void
    {
        [$requestId, $tokenClient] = $this->setUpRequestWithProducerReply();
        $country = $this->makeCountry('BE', 'Belgique');
        $otherProducer = $this->makeProducerProfile($this->makeUser('other_producer'), $country);
        $this->em->flush();

        $this->client->request('POST', '/api/reviews', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient,
        ], content: json_encode([
            'requestId' => $requestId,
            'producerId' => $otherProducer->getId()->toRfc4122(),
            'rating' => 4,
        ]));

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateReviewRejectsDuplicate(): void
    {
        [$requestId, $tokenClient, , $producerId] = $this->setUpRequestWithProducerReply();

        $payload = json_encode(['requestId' => $requestId, 'producerId' => $producerId, 'rating' => 4]);
        $this->client->request('POST', '/api/reviews', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient,
        ], content: $payload);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/api/reviews', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient,
        ], content: $payload);
        self::assertResponseStatusCodeSame(409);
    }

    public function testCreateReviewRejectsInvalidRating(): void
    {
        [$requestId, $tokenClient, , $producerId] = $this->setUpRequestWithProducerReply();

        $this->client->request('POST', '/api/reviews', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient,
        ], content: json_encode(['requestId' => $requestId, 'producerId' => $producerId, 'rating' => 8]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testPublicProducerReviewsOnlyShowsPublishedAndComputesAverage(): void
    {
        [$requestId, $tokenClient, , $producerId] = $this->setUpRequestWithProducerReply();

        $this->client->request('POST', '/api/reviews', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient,
        ], content: json_encode(['requestId' => $requestId, 'producerId' => $producerId, 'rating' => 4]));
        $reviewId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        // * Encore Pending : ne doit pas apparaître publiquement.
        $this->client->request('GET', '/api/producers/'.$producerId.'/reviews');
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(0, $data['count']);
        self::assertNull($data['averageRating']);

        $review = $this->em->getRepository(Review::class)->find($reviewId);
        $review->setStatus(ReviewStatus::Published);
        $this->em->flush();

        $this->client->request('GET', '/api/producers/'.$producerId.'/reviews');
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(1, $data['count']);
        // * assertEquals plutôt qu'assertSame : json_encode(4.0) peut être sérialisé "4" (sans décimale)
        // * selon la configuration serialize_precision, ce qui redécode en int -- la valeur numérique
        // * compte ici, pas le type PHP exact après aller-retour JSON.
        self::assertEquals(4.0, $data['averageRating']);
        self::assertSame($reviewId, $data['reviews'][0]['id']);
    }

    public function testRespondToReviewRequiresPublishedStatus(): void
    {
        [$requestId, $tokenClient, $tokenProducer, $producerId] = $this->setUpRequestWithProducerReply();

        $this->client->request('POST', '/api/reviews', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient,
        ], content: json_encode(['requestId' => $requestId, 'producerId' => $producerId, 'rating' => 5]));
        $reviewId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', '/api/reviews/'.$reviewId.'/response', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenProducer,
        ], content: json_encode(['response' => 'Merci !']));
        self::assertResponseStatusCodeSame(409, 'Un avis encore Pending ne doit pas pouvoir recevoir de réponse.');

        $review = $this->em->getRepository(Review::class)->find($reviewId);
        $review->setStatus(ReviewStatus::Published);
        $this->em->flush();

        $this->client->request('POST', '/api/reviews/'.$reviewId.'/response', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenProducer,
        ], content: json_encode(['response' => 'Merci pour votre retour !']));
        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT producer_response FROM trust.reviews WHERE id = :id',
            ['id' => $reviewId]
        );
        self::assertSame('Merci pour votre retour !', $row['producer_response']);
    }

    public function testRespondToReviewRejectsNonOwningProducer(): void
    {
        [$requestId, $tokenClient, , $producerId] = $this->setUpRequestWithProducerReply();

        $this->client->request('POST', '/api/reviews', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenClient,
        ], content: json_encode(['requestId' => $requestId, 'producerId' => $producerId, 'rating' => 5]));
        $reviewId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $review = $this->em->getRepository(Review::class)->find($reviewId);
        $review->setStatus(ReviewStatus::Published);
        $this->em->flush();

        $intruderOwner = $this->makeUserWithPassword('intruder', 'motdepasse123');
        $intruderOwner->setRoles([User::ROLE_PRODUCER]);
        $this->makeProducerProfile($intruderOwner, $this->makeCountry('DE', 'Allemagne'));
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $intruderOwner->getEmail(),
            'password' => 'motdepasse123',
        ]));
        $tokenOtherProducer = json_decode($this->client->getResponse()->getContent(), true)['token'];

        $this->client->request('POST', '/api/reviews/'.$reviewId.'/response', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenOtherProducer,
        ], content: json_encode(['response' => 'Pas le mien']));
        self::assertResponseStatusCodeSame(403);
    }
}
