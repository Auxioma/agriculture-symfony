<?php

namespace App\Tests\Fixtures;

use App\Entity\Billing\PlanPrice;
use App\Entity\Billing\Subscription;
use App\Entity\Billing\SubscriptionPlan;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Country;
use App\Entity\Catalog\Product;
use App\Entity\Identity\User;
use App\Entity\Matching\ClientRequest;
use App\Entity\Producer\ProducerProduct;
use App\Entity\Producer\ProducerProfile;
use App\Enum\BillingCycle;
use App\Enum\NeedType;
use App\Enum\RequestStatus;
use App\Enum\SubscriptionStatus;
use App\Enum\VerificationStatus;

/**
 * Fabriques de fixtures minimales et réutilisables pour les tests fonctionnels en base.
 * Chaque entité retournée est persistée mais pas flush — appeler $this->em->flush() une seule fois par test.
 */
trait EntityFactoryTrait
{
    protected function makeCountry(string $code = 'FR', string $name = 'France'): Country
    {
        $country = new Country();
        $country->setCode($code);
        $country->setName($name);
        $this->em->persist($country);

        return $country;
    }

    protected function makeCategory(string $name = 'Fruits'): Category
    {
        $category = new Category();
        $category->setName($name);
        $this->em->persist($category);

        return $category;
    }

    protected function makeProduct(Category $category, string $name = 'Pommes'): Product
    {
        $product = new Product();
        $product->setCategory($category);
        $product->setName($name);
        $this->em->persist($product);

        return $product;
    }

    protected function makeUser(string $emailPrefix = 'user'): User
    {
        $user = new User();
        $user->setEmail($emailPrefix.'_'.bin2hex(random_bytes(6)).'@test.local');
        $user->setPasswordHash('x');
        $this->em->persist($user);

        return $user;
    }

    protected function makeProducerProfile(
        User $owner,
        Country $country,
        VerificationStatus $verificationStatus = VerificationStatus::Verified,
    ): ProducerProfile {
        $producer = new ProducerProfile();
        $producer->setOwner($owner);
        $producer->setFarmName('Ferme Test');
        $producer->setSlug('ferme-test-'.bin2hex(random_bytes(6)));
        $producer->setCountry($country);
        $producer->setVerificationStatus($verificationStatus);
        $this->em->persist($producer);

        return $producer;
    }

    protected function makeProducerProduct(ProducerProfile $producer, Product $product, bool $isActive = true): ProducerProduct
    {
        $producerProduct = new ProducerProduct();
        $producerProduct->setProducer($producer);
        $producerProduct->setProduct($product);
        $producerProduct->setIsActive($isActive);
        $this->em->persist($producerProduct);

        return $producerProduct;
    }

    protected function makeActiveSubscription(
        ProducerProfile $producer,
        SubscriptionStatus $status = SubscriptionStatus::Active,
        ?\DateTimeImmutable $periodStart = null,
        ?\DateTimeImmutable $periodEnd = null,
        array $features = ['reply_to_requests' => true],
    ): Subscription {
        $plan = new SubscriptionPlan();
        $plan->setCode('plan-'.bin2hex(random_bytes(6)));
        $plan->setName('Basic');
        $plan->setFeatures($features);
        $this->em->persist($plan);

        $planPrice = new PlanPrice();
        $planPrice->setPlan($plan);
        $planPrice->setBillingCycle(BillingCycle::Monthly);
        $this->em->persist($planPrice);

        $subscription = new Subscription();
        $subscription->setProducer($producer);
        $subscription->setPlanPrice($planPrice);
        $subscription->setStatus($status);
        $subscription->setCurrentPeriodStart($periodStart ?? new \DateTimeImmutable('-1 day'));
        $subscription->setCurrentPeriodEnd($periodEnd ?? new \DateTimeImmutable('+29 days'));
        $this->em->persist($subscription);

        return $subscription;
    }

    protected function makeClientRequest(
        User $client,
        ?Product $product = null,
        NeedType $needType = NeedType::OneShot,
        RequestStatus $status = RequestStatus::Sent,
    ): ClientRequest {
        $request = new ClientRequest();
        $request->setClient($client);
        if (null !== $product) {
            $request->setProduct($product);
        } else {
            $request->setCustomProduct('Produit non catalogue');
        }
        $request->setNeedType($needType);
        $request->setStatus($status);
        $this->em->persist($request);

        return $request;
    }
}
