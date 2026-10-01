<?php

/**
 * Avis clients sur les producteurs -- manquaient entièrement avant (aucune fixture Review n'existait), ce
 * qui laissait GET /api/producers/{id}/reviews et la moyenne de note toujours vides en démo. $rating varie
 * volontairement par producteur (pas tous à 5) pour que le tri "top notés" de
 * GET /api/producers/featured ait un vrai classement à produire plutôt que des ex-aequo partout.
 */

namespace App\DataFixtures;

use App\Entity\Identity\User;
use App\Entity\Matching\ClientRequest;
use App\Entity\Producer\ProducerProfile;
use App\Entity\Trust\Review;
use App\Enum\ReviewStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;

class ReviewFixtures extends Fixture implements DependentFixtureInterface
{
    // * Plusieurs avis par producteur (ratings fixes, pas aléatoires) : les producteurs d'index 0 et 2
    // * ressortent nettement devant les autres dans le tri "top notés + vérifiés" de la section featured.
    private const RATINGS_BY_PRODUCER = [
        [5, 5, 4],
        [4, 4, 3],
        [5, 4, 5],
        [3, 4],
        [4, 5, 4],
        [4, 3],
    ];

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        for ($producerIndex = 0; $producerIndex < UserFixtures::PRODUCER_OWNER_COUNT; ++$producerIndex) {
            $producer = $this->getReference(ProducerFixtures::PRODUCER_PROFILE_REFERENCE_PREFIX.$producerIndex, ProducerProfile::class);
            $ratings = self::RATINGS_BY_PRODUCER[$producerIndex % \count(self::RATINGS_BY_PRODUCER)];

            foreach ($ratings as $reviewIndex => $rating) {
                // * Chaque avis prend un client/demande différents (modulo UserFixtures::CLIENT_COUNT) --
                // * la contrainte uniq_client_request_producer interdirait deux avis identiques sinon.
                $clientIndex = ($producerIndex * 3 + $reviewIndex) % UserFixtures::CLIENT_COUNT;
                $client = $this->getReference(UserFixtures::CLIENT_REFERENCE_PREFIX.$clientIndex, User::class);
                $request = $this->getReference('client-request-'.$clientIndex, ClientRequest::class);

                $review = new Review();
                $review->setClient($client);
                $review->setProducer($producer);
                $review->setRequest($request);
                $review->setRating($rating);
                $review->setComment($faker->sentence());
                $review->setStatus(ReviewStatus::Published);
                $manager->persist($review);
            }
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [ProducerFixtures::class, ClientRequestFixtures::class, UserFixtures::class];
    }
}
