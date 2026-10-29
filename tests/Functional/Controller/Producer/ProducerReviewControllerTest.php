<?php

namespace App\Tests\Functional\Controller\Producer;

use App\Entity\Identity\User;
use App\Entity\Producer\ProducerProfile;
use App\Entity\Trust\Review;
use App\Enum\ReviewStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste GET /api/producer/reviews (cahier fonctionnel, dashboard producteur : "Avis clients").
 */
final class ProducerReviewControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    /**
     * @return array{0: string, 1: ProducerProfile}
     */
    private function loginAsProducer(): array
    {
        $owner = $this->makeUserWithPassword('producer', 'motdepasse123');
        $owner->setRoles([User::ROLE_PRODUCER]);
        $producer = $this->makeProducerProfile($owner, $this->makeCountry());
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $owner->getEmail(),
            'password' => 'motdepasse123',
        ]));

        return [json_decode($this->client->getResponse()->getContent(), true)['token'], $producer];
    }

    private function review(ProducerProfile $producer, ?User $client, int $rating = 5, ReviewStatus $status = ReviewStatus::Published, string $createdAt = '2026-08-10 09:00:00+00'): Review
    {
        // * Une demande par avis : un client ne laisse qu'un avis par demande et par producteur.
        $request = $this->makeClientRequest($client ?? $this->makeUser('author'));
        $review = $this->makeReview($client ?? $request->getClient(), $producer, $request, $rating, $status);
        if ($client === null) {
            $review->setClient(null);
        }
        $this->em->flush();
        // * created_at n'a pas de fractions de seconde et vaut "maintenant" à la création : on le pose en SQL pour que l'ordre soit sûr.
        $this->em->getConnection()->executeStatement(
            'UPDATE trust.reviews SET created_at = :at WHERE id = :id',
            ['at' => $createdAt, 'id' => $review->getId()->toRfc4122()]
        );

        return $review;
    }

    private function named(string $firstName, ?string $lastName): User
    {
        $user = $this->makeUser('author');
        $user->setFirstName($firstName);
        $user->setLastName($lastName);

        return $user;
    }

    /**
     * @return array{averageRating: float|null, count: int, reviews: list<array<string, mixed>>}
     */
    private function fetch(string $token): array
    {
        $this->client->request('GET', '/api/producer/reviews', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function testRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/producer/reviews');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRejectsAnAccountWithoutProducerProfile(): void
    {
        $token = $this->registerClientAndLogin('client');

        $this->client->request('GET', '/api/producer/reviews', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAProducerWithoutReviewsGetsAnEmptyList(): void
    {
        [$token] = $this->loginAsProducer();

        $data = $this->fetch($token);

        self::assertSame(0, $data['count']);
        self::assertNull($data['averageRating']);
        self::assertSame([], $data['reviews']);
    }

    public function testOnlyPublishedReviewsOfThisProducerAreListed(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $other = $this->makeProducerProfile($this->makeUser('other'), $this->makeCountry('BE', 'Belgique'));
        $published = $this->review($producer, $this->named('Camille', 'Roux'));
        $this->review($producer, $this->named('Lucas', 'Martin'), 1, ReviewStatus::Pending);
        $this->review($producer, $this->named('Inès', 'Garcia'), 1, ReviewStatus::Rejected);
        $this->review($other, $this->named('Marc', 'Dupont'));

        $data = $this->fetch($token);

        self::assertSame(1, $data['count']);
        self::assertSame([$published->getId()->toRfc4122()], array_column($data['reviews'], 'id'));
    }

    // * 5, 5 et 4 donnent 4,67 : une décimale, comme sur la fiche publique. Les avis en attente n'y comptent pas.
    public function testTheAverageIsRoundedToOneDecimalAndIgnoresUnpublishedReviews(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $this->review($producer, $this->named('Camille', 'Roux'), 5);
        $this->review($producer, $this->named('Lucas', 'Martin'), 5);
        $this->review($producer, $this->named('Inès', 'Garcia'), 4);
        $this->review($producer, $this->named('Marc', 'Dupont'), 1, ReviewStatus::Pending);

        $data = $this->fetch($token);

        self::assertSame(3, $data['count']);
        self::assertEqualsWithDelta(4.7, $data['averageRating'], 0.001);
    }

    public function testReviewsComeNewestFirst(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $old = $this->review($producer, $this->named('Lucas', 'Martin'), 4, createdAt: '2026-08-03 09:00:00+00');
        $new = $this->review($producer, $this->named('Camille', 'Roux'), 5, createdAt: '2026-08-10 09:00:00+00');
        $middle = $this->review($producer, $this->named('Inès', 'Garcia'), 3, createdAt: '2026-08-05 09:00:00+00');

        $data = $this->fetch($token);

        self::assertSame(
            [$new->getId()->toRfc4122(), $middle->getId()->toRfc4122(), $old->getId()->toRfc4122()],
            array_column($data['reviews'], 'id')
        );
    }

    public function testAReviewCarriesItsRatingCommentDateAndTheProducersResponse(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $review = $this->review($producer, $this->named('Camille', 'Roux'), 4, createdAt: '2026-08-10 09:00:00+00');
        $review->setComment('Tomates excellentes, échange très simple et rapide.');
        $review->setProducerResponse('Merci Camille !');
        $this->em->flush();

        $row = $this->fetch($token)['reviews'][0];

        self::assertSame(4, $row['rating']);
        self::assertSame('Tomates excellentes, échange très simple et rapide.', $row['comment']);
        self::assertSame('Merci Camille !', $row['producerResponse']);
        self::assertSame(strtotime('2026-08-10 09:00:00+00'), strtotime($row['createdAt']));
    }

    public function testAReviewWithoutResponseOrCommentSaysSo(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $this->review($producer, $this->named('Camille', 'Roux'));

        $row = $this->fetch($token)['reviews'][0];

        self::assertNull($row['producerResponse']);
        self::assertNull($row['comment']);
    }

    // * Prénom et initiale seulement, jamais le nom complet ni l'e-mail (comme sous l'avis sur la fiche publique).
    public function testTheAuthorIsShownAsFirstNameAndInitialOnly(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $this->review($producer, $this->named('Camille', 'roux'), createdAt: '2026-08-10 09:00:00+00');
        $this->review($producer, $this->named('Lucas', null), createdAt: '2026-08-09 09:00:00+00');
        $this->review($producer, $this->named('Inès', '   '), createdAt: '2026-08-08 09:00:00+00');

        $names = array_column($this->fetch($token)['reviews'], 'authorName');

        self::assertSame(['Camille R.', 'Lucas', 'Inès'], $names);
    }

    public function testAnAuthorWithoutFirstNameOrWithADeletedAccountHasNoName(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $this->review($producer, $this->named('', 'Roux'), createdAt: '2026-08-10 09:00:00+00');
        $this->review($producer, null, createdAt: '2026-08-09 09:00:00+00');

        $names = array_column($this->fetch($token)['reviews'], 'authorName');

        self::assertSame([null, null], $names);
    }
}
