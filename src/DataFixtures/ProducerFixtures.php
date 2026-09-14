<?php

/**
 * Fiches producteur complètes (profil + préférences + quelques produits + abonnement) pour les
 * propriétaires créés par UserFixtures. $verificationStatus et le statut d'abonnement sont volontairement
 * variés (pas tous Verified/Active) pour que les compteurs de DashboardController::index() (producteurs en
 * attente) et le taux de churn de reporting() (abonnements annulés) ne soient pas nuls sur une base de démo.
 */

namespace App\DataFixtures;

use App\Entity\Billing\PlanPrice;
use App\Entity\Billing\Subscription;
use App\Entity\Catalog\Country;
use App\Entity\Catalog\Product;
use App\Entity\Identity\User;
use App\Entity\Producer\ProducerProduct;
use App\Entity\Producer\ProducerProfile;
use App\Entity\Producer\ProducerSetting;
use App\Enum\SubscriptionStatus;
use App\Enum\VerificationStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;

class ProducerFixtures extends Fixture implements DependentFixtureInterface
{
    public const PRODUCER_PROFILE_REFERENCE_PREFIX = 'producer-profile-';

    // * Un statut différent par index (modulo la taille du tableau) : garantit au moins un producteur
    // * Pending et un abonnement Cancelled quel que soit UserFixtures::PRODUCER_OWNER_COUNT.
    private const VERIFICATION_STATUSES = [
        VerificationStatus::Verified,
        VerificationStatus::Verified,
        VerificationStatus::Verified,
        VerificationStatus::Pending,
    ];
    private const SUBSCRIPTION_STATUSES = [
        SubscriptionStatus::Active,
        SubscriptionStatus::Active,
        SubscriptionStatus::Trialing,
        SubscriptionStatus::Cancelled,
    ];

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');
        $country = $this->getReference(CatalogFixtures::COUNTRY_FR, Country::class);
        $planPrice = $this->getReference(SubscriptionPlanFixtures::PLAN_PRICE_STANDARD_MONTHLY, PlanPrice::class);

        for ($i = 0; $i < UserFixtures::PRODUCER_OWNER_COUNT; ++$i) {
            $owner = $this->getReference(UserFixtures::PRODUCER_OWNER_REFERENCE_PREFIX.$i, User::class);

            $farmName = 'Ferme '.$faker->lastName();
            $producer = new ProducerProfile();
            $producer->setOwner($owner);
            $producer->setFarmName($farmName);
            $producer->setSlug($faker->slug());
            $producer->setDescription($faker->paragraph());
            $producer->setCountry($country);
            $producer->setCity($faker->city());
            $producer->setPostalCode($faker->postcode());
            $producer->setVerificationStatus(self::VERIFICATION_STATUSES[$i % \count(self::VERIFICATION_STATUSES)]);
            $producer->setIsActive(true);
            $manager->persist($producer);
            $this->addReference(self::PRODUCER_PROFILE_REFERENCE_PREFIX.$i, $producer);

            $setting = new ProducerSetting();
            $setting->setProducer($producer);
            $setting->setAcceptsIndividuals(true);
            $setting->setAcceptsProfessionals(0 === $i % 2);
            $setting->setPickupEnabled(true);
            $setting->setDeliveryEnabled(0 === $i % 2);
            $manager->persist($setting);

            foreach ($this->pickProductReferences($i) as $productReference) {
                $producerProduct = new ProducerProduct();
                $producerProduct->setProducer($producer);
                $producerProduct->setProduct($this->getReference($productReference, Product::class));
                $producerProduct->setDefaultPrice((string) $faker->randomFloat(2, 2, 25));
                $producerProduct->setIsActive(true);
                $manager->persist($producerProduct);
            }

            $subscriptionStatus = self::SUBSCRIPTION_STATUSES[$i % \count(self::SUBSCRIPTION_STATUSES)];
            $subscription = new Subscription();
            $subscription->setProducer($producer);
            $subscription->setPlanPrice($planPrice);
            $subscription->setStatus($subscriptionStatus);
            $subscription->setCurrentPeriodStart(new \DateTimeImmutable('-15 days'));
            $subscription->setCurrentPeriodEnd(new \DateTimeImmutable('+15 days'));
            $manager->persist($subscription);
        }

        $manager->flush();
    }

    /**
     * @return list<string>
     */
    private function pickProductReferences(int $index): array
    {
        $references = CatalogFixtures::PRODUCT_REFERENCES;

        return [$references[$index % \count($references)], $references[($index + 1) % \count($references)]];
    }

    public function getDependencies(): array
    {
        return [UserFixtures::class, CatalogFixtures::class, SubscriptionPlanFixtures::class];
    }
}
