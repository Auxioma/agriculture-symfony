<?php

/**
 * Demandes clients avec une variété de statuts/besoins/pays -- $status parcourt volontairement plusieurs
 * valeurs de RequestStatus pour que DashboardController::index() (compte des demandes actives) et
 * reporting() (répartition par pays, catégories populaires) affichent des chiffres non nuls. $expiresAt est
 * posé explicitement (comme le fait ClientRequestController::createRequest() en vrai), pour donner des
 * lignes exploitables à SendExpiryRemindersCommand et à l'index idx_client_requests_expires_at.
 */

namespace App\DataFixtures;

use App\Entity\Catalog\Country;
use App\Entity\Catalog\Product;
use App\Entity\Identity\User;
use App\Entity\Matching\ClientRequest;
use App\Enum\NeedType;
use App\Enum\RequestStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;

class ClientRequestFixtures extends Fixture implements DependentFixtureInterface
{
    // * Un statut différent par index (modulo) : garantit un peu de tout (Sent, ConversationOpen,
    // * Cancelled, Reported...) quel que soit le nombre de clients de UserFixtures.
    private const STATUSES = [
        RequestStatus::Sent,
        RequestStatus::ConversationOpen,
        RequestStatus::DealFound,
        RequestStatus::Cancelled,
        RequestStatus::Reported,
    ];
    private const NEED_TYPES = [NeedType::OneShot, NeedType::Recurring, NeedType::QuoteRequest];

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');
        $country = $this->getReference(CatalogFixtures::COUNTRY_FR, Country::class);

        for ($i = 0; $i < UserFixtures::CLIENT_COUNT; ++$i) {
            $client = $this->getReference(UserFixtures::CLIENT_REFERENCE_PREFIX.$i, User::class);
            $productReference = CatalogFixtures::PRODUCT_REFERENCES[$i % \count(CatalogFixtures::PRODUCT_REFERENCES)];

            $request = new ClientRequest();
            $request->setClient($client);
            $request->setProduct($this->getReference($productReference, Product::class));
            $request->setNeedType(self::NEED_TYPES[$i % \count(self::NEED_TYPES)]);
            $request->setQuantity((string) $faker->randomFloat(2, 5, 200));
            $request->setBudgetMax((string) $faker->randomFloat(2, 20, 500));
            $request->setCountry($country);
            $request->setCity($faker->city());
            $request->setPostalCode($faker->postcode());
            $request->setMessage($faker->paragraph());
            $request->setStatus(self::STATUSES[$i % \count(self::STATUSES)]);
            $request->setExpiresAt(new \DateTimeImmutable('+30 days'));
            $manager->persist($request);
            $this->addReference('client-request-'.$i, $request);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [UserFixtures::class, CatalogFixtures::class];
    }
}
