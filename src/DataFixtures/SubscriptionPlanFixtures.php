<?php

/**
 * Offres d'abonnement des cahiers (Essentiel, Pro, Premium) avec leurs tarifs mensuel/annuel -- référentiel figé
 * comme CatalogFixtures, pas de Faker ici : ce sont de vraies offres commerciales, pas des données aléatoires.
 * Le gratuit n'est pas une offre en base : c'est l'absence d'abonnement (voir ProducerRequestAccess).
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
    public const PLAN_PRICE_PRO_MONTHLY = 'plan-price-pro-monthly';
    public const PLAN_PRICE_PREMIUM_MONTHLY = 'plan-price-premium-monthly';

    // * Dans l'ordre d'affichage. Les cahiers donnent des fourchettes (essentiel 9 à 12 €, pro 19 à 29 €, premium 39 à 59 €,
    // * annuel -15 à -20 %) et disent que les prix sont à valider avec le client : les montants ci-dessous sont des valeurs
    // * de démo dedans, l'annuel = 10 mois (-17 %). Même chose pour le quota : essentiel "demandes limitées", pro "élevées",
    // * premium "illimitées" (pas de clé = illimité, voir SubscriptionController::listPlans).
    // ! multi-utilisateurs (premium) n'est pas écrit : ça dépend des comptes équipe, prévus en V2 dans le cahier.
    // ! pas de max_products non plus : aucun cahier ne limite le nombre de produits d'une offre.
    // * recommended = l'offre mise en avant sur la page des forfaits (carte verte, badge « Recommandé »), Pro d'après les cahiers.
    public const PLANS = [
        'essentiel' => [
            'name' => 'Essentiel',
            'description' => 'Pour démarrer : un nombre limité de demandes, le chat et les notifications.',
            'features' => ['reply_to_requests' => true, 'chat' => true],
            'limits' => ['requests_per_month' => 15],
            'monthly' => '10.00',
            'yearly' => '100.00',
        ],
        'pro' => [
            'name' => 'Pro',
            'description' => 'Plus de demandes, le chat complet et les statistiques de base.',
            'features' => ['reply_to_requests' => true, 'chat' => true, 'stats' => 'basic', 'recommended' => true],
            'limits' => ['requests_per_month' => 60],
            'monthly' => '19.00',
            'yearly' => '190.00',
        ],
        'premium' => [
            'name' => 'Premium',
            'description' => 'Mise en avant, statistiques avancées et support prioritaire.',
            'features' => ['reply_to_requests' => true, 'chat' => true, 'stats' => 'advanced', 'featured' => true, 'priority_support' => true],
            'limits' => null,
            'monthly' => '49.00',
            'yearly' => '490.00',
        ],
    ];

    public function load(ObjectManager $manager): void
    {
        $currency = $this->getReference(CatalogFixtures::CURRENCY_EUR, \App\Entity\Catalog\Currency::class);

        foreach (array_keys(self::PLANS) as $position => $code) {
            $data = self::PLANS[$code];

            $plan = (new SubscriptionPlan())
                ->setCode($code)->setName($data['name'])->setDescription($data['description'])
                ->setFeatures($data['features'])->setLimits($data['limits'])
                ->setIsActive(true)->setPosition($position + 1);
            $manager->persist($plan);

            foreach ([BillingCycle::Monthly, BillingCycle::Yearly] as $cycle) {
                $price = (new PlanPrice())
                    ->setPlan($plan)->setBillingCycle($cycle)
                    ->setAmount($data[$cycle->value])
                    ->setCurrency($currency)->setIsActive(true);
                $manager->persist($price);

                $this->addReference(\sprintf('plan-price-%s-%s', $code, $cycle->value), $price);
            }
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [CatalogFixtures::class];
    }
}
