<?php

/**
 * Fiches producteur complètes (profil + préférences + quelques produits + abonnement + photo + labels)
 * pour les propriétaires créés par UserFixtures. $verificationStatus et le statut d'abonnement sont
 * volontairement variés (pas tous Verified/Active) pour que les compteurs de DashboardController::index()
 * (producteurs en attente) et le taux de churn de reporting() (abonnements annulés) ne soient pas nuls sur
 * une base de démo. Ville + location tirées d'une vraie liste de villes françaises (voir self::CITIES) --
 * nécessaire pour que GET /api/producers/featured affiche une distance cohérente avec la ville.
 */

namespace App\DataFixtures;

use App\Entity\Billing\PlanPrice;
use App\Entity\Billing\Subscription;
use App\Entity\Catalog\Country;
use App\Entity\Catalog\Label;
use App\Entity\Catalog\Product;
use App\Entity\Identity\User;
use App\Entity\Producer\ProducerLabel;
use App\Entity\Producer\ProducerMedia;
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

    // * Villes + coordonnées réelles (plutôt que $faker->city(), qui invente un nom sans lien avec une
    // * position) : nécessaire pour que GET /api/producers/featured calcule une vraie distance cohérente
    // * avec la ville affichée.
    private const CITIES = [
        ['Bordeaux', 44.8378, -0.5792],
        ['Aix-en-Provence', 43.5297, 5.4474],
        ['Lyon', 45.7640, 4.8357],
        ['Toulouse', 43.6047, 1.4442],
        ['Nantes', 47.2184, -1.5536],
        ['Rennes', 48.1173, -1.6778],
    ];

    // * Une photo par producteur (Unsplash, licence libre -- cf. memoire "Stockage fichiers = S3/MinIO" : en
    // * prod le fileUrl pointe vers le bucket, ici c'est juste pour avoir une fiche de démo présentable).
    private const PHOTO_URLS = [
        'https://images.unsplash.com/photo-1500382017468-9049fed747ef?w=600&h=600&fit=crop&q=80',
        'https://images.unsplash.com/photo-1500937386664-56d1dfef3854?w=600&h=600&fit=crop&q=80',
        'https://images.unsplash.com/photo-1472396961693-142e6e269027?w=600&h=600&fit=crop&q=80',
        'https://images.unsplash.com/photo-1500595046743-cd271d694d30?w=600&h=600&fit=crop&q=80',
        'https://images.unsplash.com/photo-1464226184884-fa280b87c399?w=600&h=600&fit=crop&q=80',
        'https://images.unsplash.com/photo-1501004318641-b39e6451bec6?w=600&h=600&fit=crop&q=80',
    ];

    // * Deux labels par producteur (sauf celui volontairement non vérifié, cf. plus bas) : assez pour peupler
    // * les badges de GET /api/producers/{id} et de la future section "featured" sans sur-labelliser.
    private const LABEL_REFERENCE_PAIRS = [
        [CatalogFixtures::LABEL_BIO, CatalogFixtures::LABEL_LOCAL],
        [CatalogFixtures::LABEL_HVE, CatalogFixtures::LABEL_LOCAL],
        [CatalogFixtures::LABEL_AGRICULTURE_RAISONNEE, CatalogFixtures::LABEL_LOCAL],
    ];

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');
        $country = $this->getReference(CatalogFixtures::COUNTRY_FR, Country::class);
        $planPrice = $this->getReference(SubscriptionPlanFixtures::PLAN_PRICE_STANDARD_MONTHLY, PlanPrice::class);

        for ($i = 0; $i < UserFixtures::PRODUCER_OWNER_COUNT; ++$i) {
            $owner = $this->getReference(UserFixtures::PRODUCER_OWNER_REFERENCE_PREFIX.$i, User::class);

            [$cityName, $latitude, $longitude] = self::CITIES[$i % \count(self::CITIES)];
            $verificationStatus = self::VERIFICATION_STATUSES[$i % \count(self::VERIFICATION_STATUSES)];

            $farmName = 'Ferme '.$faker->lastName();
            $producer = new ProducerProfile();
            $producer->setOwner($owner);
            $producer->setFarmName($farmName);
            $producer->setSlug($faker->slug());
            $producer->setDescription($faker->paragraph());
            $producer->setCountry($country);
            $producer->setCity($cityName);
            $producer->setPostalCode($faker->postcode());
            $producer->setLocation(sprintf('SRID=4326;POINT(%F %F)', $longitude, $latitude));
            $producer->setVerificationStatus($verificationStatus);
            $producer->setIsActive(true);
            $manager->persist($producer);
            $this->addReference(self::PRODUCER_PROFILE_REFERENCE_PREFIX.$i, $producer);

            $photo = new ProducerMedia();
            $photo->setProducer($producer);
            $photo->setType('photo');
            $photo->setFileUrl(self::PHOTO_URLS[$i % \count(self::PHOTO_URLS)]);
            $photo->setPosition(0);
            $photo->setIsPublic(true);
            $manager->persist($photo);

            // * Pas de label sur le producteur Pending : un profil pas encore validé n'a pas de raison
            // * d'afficher des badges de confiance (cf. commentaire de getProducer() sur les labels verifiedAt).
            if (VerificationStatus::Verified === $verificationStatus) {
                foreach (self::LABEL_REFERENCE_PAIRS[$i % \count(self::LABEL_REFERENCE_PAIRS)] as $labelReference) {
                    $producerLabel = new ProducerLabel();
                    $producerLabel->setProducer($producer);
                    $producerLabel->setLabel($this->getReference($labelReference, Label::class));
                    $producerLabel->setVerifiedAt(new \DateTimeImmutable('-10 days'));
                    $manager->persist($producerLabel);
                }
            }

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
