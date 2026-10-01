<?php

/**
 * Référentiel catalogue de base (pays, devise, unités, catégories, produits, labels) -- sans ces données,
 * aucune autre fixture ne peut créer de ClientRequest/ProducerProduct valide (toutes référencent le
 * catalogue). Volontairement écrit à la main plutôt qu'avec Faker : c'est un référentiel métier réel, pas
 * des données aléatoires -- Faker sert dans les fixtures suivantes pour les noms/emails/textes libres.
 */

namespace App\DataFixtures;

use App\Entity\Catalog\Category;
use App\Entity\Catalog\Country;
use App\Entity\Catalog\Currency;
use App\Entity\Catalog\Label;
use App\Entity\Catalog\Product;
use App\Entity\Catalog\Unit;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class CatalogFixtures extends Fixture
{
    public const CURRENCY_EUR = 'currency-eur';
    public const COUNTRY_FR = 'country-fr';
    public const UNIT_KG = 'unit-kg';
    public const UNIT_UNITE = 'unit-unite';
    public const LABEL_BIO = 'label-bio';
    public const LABEL_HVE = 'label-hve';
    public const LABEL_AGRICULTURE_RAISONNEE = 'label-agriculture-raisonnee';
    public const LABEL_LOCAL = 'label-local';
    public const PRODUCT_REFERENCES = [
        'product-pommes',
        'product-fraises',
        'product-tomates',
        'product-carottes',
        'product-fromage-chevre',
        'product-poulet-fermier',
        'product-miel-fleurs',
    ];

    public function load(ObjectManager $manager): void
    {
        $currency = (new Currency())
            ->setCode('EUR')->setName('Euro')->setSymbol('€')->setDecimals(2)->setIsActive(true);
        $manager->persist($currency);
        $this->addReference(self::CURRENCY_EUR, $currency);

        foreach ([['FR', 'France'], ['BE', 'Belgique'], ['CH', 'Suisse']] as [$code, $name]) {
            $country = (new Country())->setCode($code)->setName($name)->setCurrency('EUR')->setIsActive(true);
            $manager->persist($country);
            if ('FR' === $code) {
                $this->addReference(self::COUNTRY_FR, $country);
            }
        }

        $kg = (new Unit())->setCode('kg')->setLabel('Kilogramme')->setUnitType('poids');
        $unite = (new Unit())->setCode('unite')->setLabel('Unité')->setUnitType('quantite');
        $manager->persist($kg);
        $manager->persist($unite);
        $this->addReference(self::UNIT_KG, $kg);
        $this->addReference(self::UNIT_UNITE, $unite);

        $catalogue = [
            'Fruits' => ['pommes' => 'Pommes', 'fraises' => 'Fraises'],
            'Légumes' => ['tomates' => 'Tomates', 'carottes' => 'Carottes'],
            'Produits laitiers' => ['fromage-chevre' => 'Fromage de chèvre'],
            'Viandes & Volailles' => ['poulet-fermier' => 'Poulet fermier'],
            'Miel & Produits de la ruche' => ['miel-fleurs' => 'Miel de fleurs'],
        ];
        foreach ($catalogue as $categoryName => $products) {
            $category = (new Category())->setName($categoryName)->setIsActive(true);
            $manager->persist($category);
            foreach ($products as $slug => $productName) {
                $product = (new Product())->setCategory($category)->setName($productName)->setIsActive(true);
                $manager->persist($product);
                $this->addReference('product-'.$slug, $product);
            }
        }

        // * "Local" ajouté en plus des 3 labels déjà là : cahier fonctionnel, fiche producteur publique --
        // * "Badges : vérifié, bio, local, HVE, AOP/AOC ou labels locaux".
        $labelReferences = [
            'Bio' => ['bio', self::LABEL_BIO],
            'Agriculture raisonnée' => ['agriculture-raisonnee', self::LABEL_AGRICULTURE_RAISONNEE],
            'HVE' => ['hve', self::LABEL_HVE],
            'Local' => ['local', self::LABEL_LOCAL],
        ];
        foreach ($labelReferences as $labelName => [$code, $reference]) {
            $label = (new Label())->setCode($code)->setName($labelName);
            $manager->persist($label);
            $this->addReference($reference, $label);
        }

        // * Chaque fixture flush la sienne : les fixtures suivantes résolvent leurs références
        // * (getReference()) via des proxies Doctrine qui interrogent réellement la base -- sans ce flush,
        // * la ligne n'existe pas encore et le proxy lève EntityNotFoundException dès son premier accès.
        $manager->flush();
    }
}
