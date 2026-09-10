<?php

/**
 * Offres d'abonnement (Standard/Premium) avec leurs tarifs mensuel/annuel -- référentiel figé comme
 * CatalogFixtures, pas de Faker ici : ce sont de vraies offres commerciales, pas des données aléatoires.
 * Dépend de CatalogFixtures pour la devise (EUR).
 */

namespace App\DataFixtures;

use App\Entity\Billing\PlanPrice;
use App\Entity\Billing\SubscriptionPlan;
use App\Enum\BillingCycle;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class SubscriptionPlanFixtures extends Fixture implements DependentFixtureInterface
{
    public const PLAN_PRICE_STANDARD_MONTHLY = 'plan-price-standard-monthly';
    public const PLAN_PRICE_PREMIUM_MONTHLY = 'plan-price-premium-monthly';

    public function load(ObjectManager $manager): void
    {
        $currency = $this->getReference(CatalogFixtures::CURRENCY_EUR, \App\Entity\Catalog\Currency::class);

        $standard = (new SubscriptionPlan())
            ->setCode('standard')->setName('Standard')
            ->setDescription('Accès aux demandes clients et à la messagerie.')
            ->setFeatures(['reply_to_requests' => true, 'max_products' => 20])
            ->setIsActive(true)->setPosition(1);
        $manager->persist($standard);

        $premium = (new SubscriptionPlan())
            ->setCode('premium')->setName('Premium')
            ->setDescription('Accès illimité, mise en avant dans les résultats de recherche.')
            ->setFeatures(['reply_to_requests' => true, 'max_products' => null, 'featured' => true])
            ->setIsActive(true)->setPosition(2);
        $manager->persist($premium);

        foreach ([$standard, $premium] as $plan) {
            foreach ([BillingCycle::Monthly, BillingCycle::Yearly] as $cycle) {
                $price = (new PlanPrice())
                    ->setPlan($plan)->setBillingCycle($cycle)
                    ->setAmount('standard' === $plan->getCode()
                        ? (BillingCycle::Monthly === $cycle ? '19.00' : '190.00')
                        : (BillingCycle::Monthly === $cycle ? '49.00' : '490.00'))
                    ->setCurrency($currency)->setIsActive(true);
                $manager->persist($price);

                if ('standard' === $plan->getCode() && BillingCycle::Monthly === $cycle) {
                    $this->addReference(self::PLAN_PRICE_STANDARD_MONTHLY, $price);
                }
                if ('premium' === $plan->getCode() && BillingCycle::Monthly === $cycle) {
                    $this->addReference(self::PLAN_PRICE_PREMIUM_MONTHLY, $price);
                }
            }
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [CatalogFixtures::class];
    }
}
