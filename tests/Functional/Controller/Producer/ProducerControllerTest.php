<?php

namespace App\Tests\Functional\Controller\Producer;

use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Entity\Producer\ProducerProfile;
use App\Entity\Producer\ProducerSetting;
use App\Enum\VerificationStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Teste GET /api/producers et GET /api/producers/{identifier} (slug ou UUID) (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf,
 * round 1) et les filtres de recherche ajoutés sur GET /api/producers (cahier fonctionnel, "recherche et listing producteurs" :
 * produit, catégorie, label, retrait/livraison, producteur vérifié, localisation+rayon, tri par distance).
 * Routes publiques : aucun header Authorization envoyé dans ces tests.
 */
final class ProducerControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    public function testListProducersReturnsOnlyActiveOnes(): void
    {
        $country = $this->makeCountry();
        // * farmName distinct pour chaque producteur : makeProducerProfile() a la même valeur par défaut pour
        // * les deux sinon, ce qui rend assertNotContains() ci-dessous impossible à vérifier correctement.
        $active = $this->makeProducerProfile($this->makeUser('active'), $country, farmName: 'Ferme Active');
        $active->setIsActive(true);
        $inactive = $this->makeProducerProfile($this->makeUser('inactive'), $country, farmName: 'Ferme Inactive');
        $inactive->setIsActive(false);
        $this->em->flush();

        $this->client->request('GET', '/api/producers');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $names = array_column($data, 'farmName');
        self::assertContains($active->getFarmName(), $names);
        self::assertNotContains($inactive->getFarmName(), $names);
    }

    public function testGetProducerReturnsData(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser(), $country);
        $producer->setIsActive(true);
        $producer->setDescription('Une ferme locale');
        $this->em->flush();

        $this->client->request('GET', '/api/producers/'.$producer->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Une ferme locale', $data['description']);
    }

    public function testGetProducerReturns404WhenInactive(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser(), $country);
        $producer->setIsActive(false);
        $this->em->flush();

        $this->client->request('GET', '/api/producers/'.$producer->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
    }

    public function testListProducersFiltersByProduct(): void
    {
        $country = $this->makeCountry();
        $category = $this->makeCategory();
        $sellsIt = $this->makeProducerProfile($this->makeUser('sells'), $country, farmName: 'Vend le produit');
        $doesNotSellIt = $this->makeProducerProfile($this->makeUser('nosell'), $country, farmName: 'Ne vend pas');
        $product = $this->makeProduct($category, 'Tomates');
        // * isActive=true sur ProducerProduct : le filtre productId= exige une fiche produit active,
        // * pas juste une ligne producer_products existante (cf. le AND prp.is_active = true du contrôleur).
        $this->makeProducerProduct($sellsIt, $product, true);
        $this->em->flush();

        $this->client->request('GET', '/api/producers?productId='.$product->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $names = array_column(json_decode($this->client->getResponse()->getContent(), true), 'farmName');
        self::assertContains($sellsIt->getFarmName(), $names);
        self::assertNotContains($doesNotSellIt->getFarmName(), $names);
    }

    public function testListProducersFiltersByCategory(): void
    {
        $country = $this->makeCountry();
        $matchingCategory = $this->makeCategory('Fruits');
        $otherCategory = $this->makeCategory('Légumes');
        $inCategory = $this->makeProducerProfile($this->makeUser('in-cat'), $country, farmName: 'Dans la catégorie');
        $outOfCategory = $this->makeProducerProfile($this->makeUser('out-cat'), $country, farmName: 'Hors catégorie');
        $this->makeProducerProduct($inCategory, $this->makeProduct($matchingCategory, 'Pommes'), true);
        $this->makeProducerProduct($outOfCategory, $this->makeProduct($otherCategory, 'Carottes'), true);
        $this->em->flush();

        $this->client->request('GET', '/api/producers?categoryId='.$matchingCategory->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $names = array_column(json_decode($this->client->getResponse()->getContent(), true), 'farmName');
        self::assertContains($inCategory->getFarmName(), $names);
        self::assertNotContains($outOfCategory->getFarmName(), $names);
    }

    public function testListProducersFiltersByLabel(): void
    {
        $country = $this->makeCountry();
        $labeled = $this->makeProducerProfile($this->makeUser('labeled'), $country, farmName: 'Ferme labellisée');
        $unlabeled = $this->makeProducerProfile($this->makeUser('unlabeled'), $country, farmName: 'Ferme sans label');
        $this->makeProducerLabel($labeled, $this->makeLabel('bio', 'Bio'));
        $this->em->flush();

        // * Le paramètre s'appelle `labels` (liste séparée par des virgules) ; l'ancien `label` n'existe plus.
        $names = $this->farmNamesFrom('/api/producers?labels=bio');

        self::assertContains($labeled->getFarmName(), $names);
        self::assertNotContains($unlabeled->getFarmName(), $names);
    }

    public function testListProducersFiltersByPickupAvailable(): void
    {
        $country = $this->makeCountry();
        $withPickup = $this->makeProducerProfile($this->makeUser('pickup'), $country, farmName: 'Retrait possible');
        // * withoutSettings n'a même pas de ligne producer_settings -- le filtre doit l'exclure aussi
        // * (EXISTS échoue naturellement dans ce cas), pas seulement les settings avec pickup_enabled=false.
        $withoutSettings = $this->makeProducerProfile($this->makeUser('no-settings'), $country, farmName: 'Pas de réglages');

        $settings = new ProducerSetting();
        $settings->setProducer($withPickup);
        $settings->setPickupEnabled(true);
        $this->em->persist($settings);
        $this->em->flush();

        $this->client->request('GET', '/api/producers?pickupAvailable=true');

        self::assertResponseIsSuccessful();
        $names = array_column(json_decode($this->client->getResponse()->getContent(), true), 'farmName');
        self::assertContains($withPickup->getFarmName(), $names);
        self::assertNotContains($withoutSettings->getFarmName(), $names);
    }

    public function testListProducersFiltersByVerifiedOnly(): void
    {
        $country = $this->makeCountry();
        $verified = $this->makeProducerProfile($this->makeUser('verified'), $country, VerificationStatus::Verified, 'Ferme vérifiée');
        $pending = $this->makeProducerProfile($this->makeUser('pending'), $country, VerificationStatus::Pending, 'Ferme en attente');
        $this->em->flush();

        $this->client->request('GET', '/api/producers?verifiedOnly=true');

        self::assertResponseIsSuccessful();
        $names = array_column(json_decode($this->client->getResponse()->getContent(), true), 'farmName');
        self::assertContains($verified->getFarmName(), $names);
        self::assertNotContains($pending->getFarmName(), $names);
    }

    public function testListProducersFiltersByLocationRadius(): void
    {
        $country = $this->makeCountry();
        $nearby = $this->makeProducerProfile($this->makeUser('nearby'), $country, farmName: 'Ferme proche');
        $farAway = $this->makeProducerProfile($this->makeUser('faraway'), $country, farmName: 'Ferme lointaine');
        $this->em->flush();

        // * Paris (2.35, 48.85) et un point à ~5km, contre Marseille (~660km de Paris) largement hors du
        // * rayon de 20km demandé -- pas besoin de calcul précis, juste une distance sans ambiguïté possible.
        $this->setGeographyPoint('producer.producer_profiles', 'location', $nearby->getId()->toRfc4122(), 2.39, 48.85);
        $this->setGeographyPoint('producer.producer_profiles', 'location', $farAway->getId()->toRfc4122(), 5.37, 43.30);

        $this->client->request('GET', '/api/producers?latitude=48.85&longitude=2.35&radiusKm=20');

        self::assertResponseIsSuccessful();
        $names = array_column(json_decode($this->client->getResponse()->getContent(), true), 'farmName');
        self::assertContains($nearby->getFarmName(), $names);
        self::assertNotContains($farAway->getFarmName(), $names);
    }

    public function testListProducersSortsByDistance(): void
    {
        $country = $this->makeCountry();
        $closer = $this->makeProducerProfile($this->makeUser('closer'), $country, farmName: 'Plus proche');
        $further = $this->makeProducerProfile($this->makeUser('further'), $country, farmName: 'Plus loin');
        $this->em->flush();

        $this->setGeographyPoint('producer.producer_profiles', 'location', $closer->getId()->toRfc4122(), 2.36, 48.85);
        $this->setGeographyPoint('producer.producer_profiles', 'location', $further->getId()->toRfc4122(), 2.45, 48.85);

        $this->client->request('GET', '/api/producers?latitude=48.85&longitude=2.35&radiusKm=50&sort=distance');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($closer->getFarmName(), $data[0]['farmName']);
        self::assertSame($further->getFarmName(), $data[1]['farmName']);
        // * distanceKm doit être renseigné (pas null) dès qu'une position est fournie dans la requête.
        self::assertNotNull($data[0]['distanceKm']);
        self::assertLessThan($data[1]['distanceKm'], $data[0]['distanceKm']);
    }

    public function testFeaturedProducersOrdersByAverageRatingDescending(): void
    {
        $country = $this->makeCountry();
        $wellRated = $this->makeProducerProfile($this->makeUser('well-rated'), $country, farmName: 'Bien notée');
        $averageRated = $this->makeProducerProfile($this->makeUser('average-rated'), $country, farmName: 'Moyennement notée');
        $this->em->flush();

        $client = $this->makeUser('client');
        foreach ([5, 5, 4] as $i => $rating) {
            $this->makeReview($client, $wellRated, $this->makeClientRequest($client), $rating);
        }
        foreach ([3, 2] as $i => $rating) {
            $this->makeReview($client, $averageRated, $this->makeClientRequest($client), $rating);
        }
        $this->em->flush();

        $this->client->request('GET', '/api/producers/featured');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($wellRated->getFarmName(), $data[0]['farmName']);
        self::assertSame($averageRated->getFarmName(), $data[1]['farmName']);
        self::assertEqualsWithDelta(4.7, $data[0]['averageRating'], 0.1);
        self::assertSame(3, $data[0]['reviewCount']);
    }

    public function testFeaturedProducersExcludesUnverifiedAndReviewless(): void
    {
        $country = $this->makeCountry();
        $pending = $this->makeProducerProfile($this->makeUser('pending'), $country, VerificationStatus::Pending, 'En attente, bien notée');
        $noReview = $this->makeProducerProfile($this->makeUser('no-review'), $country, farmName: 'Vérifiée sans avis');
        $this->em->flush();

        $client = $this->makeUser('client');
        $this->makeReview($client, $pending, $this->makeClientRequest($client), 5);
        $this->em->flush();

        $this->client->request('GET', '/api/producers/featured');

        self::assertResponseIsSuccessful();
        $names = array_column(json_decode($this->client->getResponse()->getContent(), true), 'farmName');
        self::assertNotContains($pending->getFarmName(), $names, 'un producteur non vérifié ne doit jamais être mis en avant');
        self::assertNotContains($noReview->getFarmName(), $names, "un producteur sans aucun avis n'a pas de moyenne à afficher");
    }

    public function testFeaturedProducersIncludesPhotoAndVerifiedLabelsOnly(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('labeled'), $country, farmName: 'Ferme complète');
        $this->em->flush();

        $client = $this->makeUser('client');
        $this->makeReview($client, $producer, $this->makeClientRequest($client), 5);
        $this->makeProducerPhoto($producer, 'https://example.test/photo.jpg');
        $this->makeProducerLabel($producer, $this->makeLabel('bio', 'Bio'), verified: true);
        $this->makeProducerLabel($producer, $this->makeLabel('hve', 'HVE'), verified: false);
        $this->em->flush();

        $this->client->request('GET', '/api/producers/featured');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('https://example.test/photo.jpg', $data[0]['photoUrl']);
        self::assertSame([['code' => 'bio', 'name' => 'Bio']], $data[0]['labels']);
    }

    public function testFeaturedProducersRespectsLimit(): void
    {
        $country = $this->makeCountry();
        $client = $this->makeUser('client');
        for ($i = 0; $i < 4; ++$i) {
            $producer = $this->makeProducerProfile($this->makeUser('producer-'.$i), $country, farmName: 'Ferme '.$i);
            $this->em->flush();
            $this->makeReview($client, $producer, $this->makeClientRequest($client), 5);
        }
        $this->em->flush();

        $this->client->request('GET', '/api/producers/featured?limit=2');

        self::assertResponseIsSuccessful();
        self::assertCount(2, json_decode($this->client->getResponse()->getContent(), true));
    }

    // ─────────────── Labels multiples ───────────────

    public function testListProducersFiltersByMultipleLabelsRequiresAllOfThem(): void
    {
        $country = $this->makeCountry();
        $bio = $this->makeLabel('bio', 'Bio');
        $local = $this->makeLabel('local', 'Local');
        $both = $this->makeProducerProfile($this->makeUser('both'), $country, farmName: 'Bio et local');
        $onlyBio = $this->makeProducerProfile($this->makeUser('only-bio'), $country, farmName: 'Seulement bio');
        $this->makeProducerLabel($both, $bio);
        $this->makeProducerLabel($both, $local);
        $this->makeProducerLabel($onlyBio, $bio);
        $this->em->flush();

        $names = $this->farmNamesFrom('/api/producers?labels=bio,local');

        self::assertContains($both->getFarmName(), $names);
        self::assertNotContains($onlyBio->getFarmName(), $names, 'le producteur doit avoir TOUS les labels demandés');
    }

    public function testListProducersLabelFilterIgnoresUnverifiedLabels(): void
    {
        $country = $this->makeCountry();
        $bio = $this->makeLabel('bio', 'Bio');
        $verified = $this->makeProducerProfile($this->makeUser('verified-label'), $country, farmName: 'Label vérifié');
        $claimedOnly = $this->makeProducerProfile($this->makeUser('claimed-label'), $country, farmName: 'Label seulement déclaré');
        $this->makeProducerLabel($verified, $bio, verified: true);
        $this->makeProducerLabel($claimedOnly, $bio, verified: false);
        $this->em->flush();

        // * Même règle que les badges des cartes : un label non vérifié n'est jamais affiché, donc jamais filtrable.
        $names = $this->farmNamesFrom('/api/producers?labels=bio');

        self::assertContains($verified->getFarmName(), $names);
        self::assertNotContains($claimedOnly->getFarmName(), $names);
    }

    // ─────────────── Sous-catégories ───────────────

    public function testListProducersByCategoryIncludesSubcategories(): void
    {
        $country = $this->makeCountry();
        $family = $this->makeCategory('Fruits');
        $apples = $this->makeCategory('Pommes');
        $apples->setParent($family);
        $apples->setIsActive(true);
        $vegetables = $this->makeCategory('Légumes');
        $inSubcategory = $this->makeProducerProfile($this->makeUser('in-sub'), $country, farmName: 'Vend des pommes');
        $elsewhere = $this->makeProducerProfile($this->makeUser('elsewhere'), $country, farmName: 'Vend des carottes');
        $this->makeProducerProduct($inSubcategory, $this->makeProduct($apples, 'Reinette'));
        $this->makeProducerProduct($elsewhere, $this->makeProduct($vegetables, 'Carottes'));
        $this->em->flush();

        // * La page « Fruits » doit lister aussi les producteurs de la sous-catégorie « Pommes ».
        $names = $this->farmNamesFrom('/api/producers?categoryId='.$family->getId()->toRfc4122());

        self::assertContains($inSubcategory->getFarmName(), $names);
        self::assertNotContains($elsewhere->getFarmName(), $names);
    }

    public function testListProducersByCategoryIgnoresInactiveSubcategories(): void
    {
        $country = $this->makeCountry();
        $family = $this->makeCategory('Fruits');
        $hidden = $this->makeCategory('Brouillon');
        $hidden->setParent($family);
        $hidden->setIsActive(false);
        $producer = $this->makeProducerProfile($this->makeUser('in-hidden'), $country, farmName: 'Catégorie masquée');
        $this->makeProducerProduct($producer, $this->makeProduct($hidden, 'Produit masqué'));
        $this->em->flush();

        $names = $this->farmNamesFrom('/api/producers?categoryId='.$family->getId()->toRfc4122());

        self::assertNotContains($producer->getFarmName(), $names);
    }

    // ─────────────── Produits de saison ───────────────

    public function testListProducersSeasonalKeepsOnlyProducersWithAnInSeasonProduct(): void
    {
        $month = $this->currentMonth();
        $country = $this->makeCountry();
        $category = $this->makeCategory('Fruits');
        $inSeason = $this->makeProducerProfile($this->makeUser('in-season'), $country, farmName: 'Produit de saison');
        $outOfSeason = $this->makeProducerProfile($this->makeUser('out-season'), $country, farmName: 'Produit hors saison');
        $noSeason = $this->makeProducerProfile($this->makeUser('no-season'), $country, farmName: 'Produit sans saison');
        $this->makeProducerProduct($inSeason, $this->makeSeasonalProduct($category, 'De saison', $month, $month));
        $this->makeProducerProduct($outOfSeason, $this->makeSeasonalProduct($category, 'Hors saison', $this->nextMonth($month), $this->nextMonth($month)));
        $this->makeProducerProduct($noSeason, $this->makeSeasonalProduct($category, 'Sans saison', null, null));
        $this->em->flush();
        $uri = '/api/producers?categoryId='.$category->getId()->toRfc4122();

        $seasonalNames = $this->farmNamesFrom($uri.'&seasonal=true');
        $allNames = $this->farmNamesFrom($uri);

        self::assertContains($inSeason->getFarmName(), $seasonalNames);
        self::assertNotContains($outOfSeason->getFarmName(), $seasonalNames);
        self::assertNotContains($noSeason->getFarmName(), $seasonalNames, 'un produit sans dates de saison n\'est jamais « de saison »');
        // * Sans le filtre, les trois producteurs restent listés.
        self::assertContains($outOfSeason->getFarmName(), $allNames);
        self::assertContains($noSeason->getFarmName(), $allNames);
    }

    public function testListProducersSeasonalHandlesSeasonSpanningTwoYears(): void
    {
        $month = $this->currentMonth();
        // * Saison à cheval sur deux années (début > fin) qui contient le mois courant :
        // * hors janvier [mois courant → mois précédent] (branche « mois >= début »), en janvier [décembre → janvier]
        // * (branche « mois <= fin »).
        [$start, $end] = $month > 1 ? [$month, $month - 1] : [12, 1];
        $country = $this->makeCountry();
        $category = $this->makeCategory('Fruits');
        $producer = $this->makeProducerProfile($this->makeUser('wrapped'), $country, farmName: 'Saison à cheval');
        $this->makeProducerProduct($producer, $this->makeSeasonalProduct($category, 'Produit d\'hiver', $start, $end));
        $this->em->flush();

        $names = $this->farmNamesFrom('/api/producers?categoryId='.$category->getId()->toRfc4122().'&seasonal=true');

        self::assertContains($producer->getFarmName(), $names);
    }

    public function testListProducersSeasonalExcludesWrappedSeasonThatDoesNotContainCurrentMonth(): void
    {
        $month = $this->currentMonth();
        if (1 === $month || 12 === $month) {
            // * Une saison à cheval (début > fin) qui EXCLUT janvier ou décembre n'existe pas : le cas n'est
            // * testable que de février à novembre.
            self::markTestSkipped('Cas non testable en janvier et en décembre.');
        }
        $country = $this->makeCountry();
        $category = $this->makeCategory('Fruits');
        $producer = $this->makeProducerProfile($this->makeUser('wrapped-out'), $country, farmName: 'Saison à cheval sans le mois');
        // * Du mois suivant jusqu'au mois précédent, en passant par la fin d'année : exclut le mois courant.
        $this->makeProducerProduct($producer, $this->makeSeasonalProduct($category, 'Hors période', $month + 1, $month - 1));
        $this->em->flush();

        $names = $this->farmNamesFrom('/api/producers?categoryId='.$category->getId()->toRfc4122().'&seasonal=true');

        self::assertNotContains($producer->getFarmName(), $names);
    }

    public function testListProducersSeasonalRequiresTheSameProductForCategoryAndSeason(): void
    {
        $month = $this->currentMonth();
        $country = $this->makeCountry();
        $fruits = $this->makeCategory('Fruits');
        $vegetables = $this->makeCategory('Légumes');
        $producer = $this->makeProducerProfile($this->makeUser('mixed'), $country, farmName: 'Fruit hors saison, légume de saison');
        $this->makeProducerProduct($producer, $this->makeSeasonalProduct($fruits, 'Fruit hors saison', $this->nextMonth($month), $this->nextMonth($month)));
        $this->makeProducerProduct($producer, $this->makeSeasonalProduct($vegetables, 'Légume de saison', $month, $month));
        $this->em->flush();

        // * Ce producteur a bien un produit de saison ET un produit « Fruits », mais pas le MÊME produit :
        // * il ne doit pas apparaître dans les fruits de saison.
        $names = $this->farmNamesFrom('/api/producers?categoryId='.$fruits->getId()->toRfc4122().'&seasonal=true');

        self::assertNotContains($producer->getFarmName(), $names);
    }

    // ─────────────── Note minimale ───────────────

    public function testListProducersFiltersByMinRating(): void
    {
        $country = $this->makeCountry();
        $wellRated = $this->makeProducerProfile($this->makeUser('well'), $country, farmName: 'Bien notée');
        $poorlyRated = $this->makeProducerProfile($this->makeUser('poor'), $country, farmName: 'Mal notée');
        $unrated = $this->makeProducerProfile($this->makeUser('unrated'), $country, farmName: 'Sans avis');
        $this->em->flush();

        $client = $this->makeUser('client');
        foreach ([5, 5] as $rating) {
            $this->makeReview($client, $wellRated, $this->makeClientRequest($client), $rating);
        }
        $this->makeReview($client, $poorlyRated, $this->makeClientRequest($client), 2);
        $this->em->flush();

        $names = $this->farmNamesFrom('/api/producers?minRating=4');

        self::assertContains($wellRated->getFarmName(), $names);
        self::assertNotContains($poorlyRated->getFarmName(), $names);
        self::assertNotContains($unrated->getFarmName(), $names, 'sans avis, pas de moyenne : exclu quand une note minimale est demandée');
    }

    // ─────────────── Contenu des cartes ───────────────

    public function testListProducersReturnsPhotoRatingAndVerifiedLabelsOnly(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('complete'), $country, farmName: 'Ferme complète');
        $this->em->flush();

        $client = $this->makeUser('client');
        foreach ([5, 3] as $rating) {
            $this->makeReview($client, $producer, $this->makeClientRequest($client), $rating);
        }
        $this->makeProducerPhoto($producer, '/images/producers/ferme.jpg');
        $this->makeProducerLabel($producer, $this->makeLabel('bio', 'Bio'), verified: true);
        $this->makeProducerLabel($producer, $this->makeLabel('hve', 'HVE'), verified: false);
        $this->em->flush();

        $this->client->request('GET', '/api/producers');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $entry = current(array_filter($data, static fn (array $p) => 'Ferme complète' === $p['farmName']));
        self::assertSame('/images/producers/ferme.jpg', $entry['photoUrl']);
        self::assertEqualsWithDelta(4.0, $entry['averageRating'], 0.01);
        self::assertSame(2, $entry['reviewCount']);
        self::assertSame([['code' => 'bio', 'name' => 'Bio']], $entry['labels']);
    }

    // ─────────────── Validation des paramètres ───────────────

    public function testListProducersRejectsInvalidParameters(): void
    {
        $invalidQueries = [
            'categoryId=abc',
            'productId=abc',
            'minRating=9',
            'minRating=abc',
            'latitude=48.85',                                  // longitude manquante
            'longitude=2.35',                                  // latitude manquante
            'latitude=200&longitude=2.35',
            'latitude=48.85&longitude=500',
            'latitude=48.85&longitude=2.35&radiusKm=0',
            'latitude=48.85&longitude=2.35&radiusKm=100000',
            'labels='.implode(',', array_map(static fn (int $i) => 'label'.$i, range(1, 11))),
        ];

        foreach ($invalidQueries as $query) {
            $this->client->request('GET', '/api/producers?'.$query);

            self::assertResponseStatusCodeSame(400, sprintf('"%s" doit être refusé avec un 400', $query));
        }
    }

    public function testGetProducerReturns404ForMalformedId(): void
    {
        // * "abc" n'est pas un UUID : il est traité comme un slug (inconnu ici). Dans les deux cas, jamais d'erreur 500
        // * de la colonne uuid de la base, mais un 404.
        $this->client->request('GET', '/api/producers/abc');

        self::assertResponseStatusCodeSame(404);
    }

    // ─────────────── URL publique de la fiche : slug (l'UUID reste accepté) ───────────────

    public function testGetProducerBySlug(): void
    {
        $producer = $this->makeProducerWithSlug('ferme-du-soleil');

        $this->client->request('GET', '/api/producers/ferme-du-soleil');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($producer->getId()->toRfc4122(), $data['id']);
        self::assertSame('ferme-du-soleil', $data['slug']);
    }

    public function testGetProducerStillWorksByUuidWhenASlugExists(): void
    {
        $producer = $this->makeProducerWithSlug('ferme-du-soleil');

        // * Anciens liens et favoris basés sur l'id
        $this->client->request('GET', '/api/producers/'.$producer->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('ferme-du-soleil', $data['slug']);
    }

    public function testGetProducerReturns404ForUnknownSlug(): void
    {
        $this->client->request('GET', '/api/producers/ferme-inconnue');

        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('malformedSlugs')]
    public function testGetProducerReturns404ForMalformedSlug(string $slug): void
    {
        // * Rejeté avant toute requête en base : jamais de 500
        $this->client->request('GET', '/api/producers/'.$slug);

        self::assertResponseStatusCodeSame(404);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedSlugs(): iterable
    {
        yield 'underscore' => ['a_b'];
        yield 'tiret au début' => ['-ferme'];
        yield 'tiret à la fin' => ['ferme-'];
        yield 'double tiret' => ['ferme--soleil'];
    }

    public function testGetProducerReturns404ForInactiveProducerFoundBySlug(): void
    {
        $producer = $this->makeProducerWithSlug('ferme-fermee');
        $producer->setIsActive(false);
        $this->em->flush();

        // * Cohérent avec la liste : un producteur désactivé n'est pas listé, son lien direct non plus
        $this->client->request('GET', '/api/producers/ferme-fermee');

        self::assertResponseStatusCodeSame(404);
    }

    public function testListProducerReviewsBySlug(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('reviewed-by-slug'), $country);
        $producer->setSlug('ferme-notee');
        $this->em->flush();

        $client = $this->makeUser('slug-client');
        $this->makeReview($client, $producer, $this->makeClientRequest($client), 5);
        $this->em->flush();

        $this->client->request('GET', '/api/producers/ferme-notee/reviews');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(1, $data['count']);
        self::assertCount(1, $data['reviews']);
    }

    public function testListProducerReviewsReturns404ForUnknownSlug(): void
    {
        $this->client->request('GET', '/api/producers/ferme-inconnue/reviews');

        self::assertResponseStatusCodeSame(404);
    }

    // ─────────────── Pagination (?page= & ?pageSize=, en-tête X-Total-Count) ───────────────

    public function testListProducersReturnsTheFirstPageByDefaultWithTheTotalInAHeader(): void
    {
        // * 15 producteurs actifs : la page par défaut en renvoie 12, mais le total annoncé est 15.
        $this->makeNumberedProducers($this->makeCountry(), 15);

        $names = $this->farmNamesFrom('/api/producers');

        self::assertCount(12, $names);
        self::assertSame('Ferme 01', $names[0]);
        self::assertSame('Ferme 12', $names[11]);
        self::assertSame('15', $this->client->getResponse()->headers->get('X-Total-Count'));
    }

    public function testListProducersReturnsTheRequestedPage(): void
    {
        $this->makeNumberedProducers($this->makeCountry(), 15);

        $names = $this->farmNamesFrom('/api/producers?page=2&pageSize=10');

        self::assertSame(['Ferme 11', 'Ferme 12', 'Ferme 13', 'Ferme 14', 'Ferme 15'], $names);
        self::assertSame('15', $this->client->getResponse()->headers->get('X-Total-Count'));
    }

    public function testListProducersPagesCoverEveryProducerExactlyOnce(): void
    {
        $this->makeNumberedProducers($this->makeCountry(), 11);

        $all = [];
        foreach ([1, 2, 3] as $page) {
            $all = array_merge($all, $this->farmNamesFrom('/api/producers?pageSize=5&page='.$page));
        }

        self::assertCount(11, $all);
        self::assertCount(11, array_unique($all));
    }

    public function testListProducersPaginationIsStableForProducersWithTheSameName(): void
    {
        // * Même nom, mêmes notes : sans critère de départage unique (pp.id), l'ordre pourrait varier d'une page à
        // * l'autre et un producteur apparaître deux fois, ou jamais.
        $this->makeNumberedProducers($this->makeCountry(), 6, 'Ferme jumelle');

        $ids = [];
        foreach ([1, 2, 3] as $page) {
            $this->client->request('GET', '/api/producers?pageSize=2&page='.$page);
            self::assertResponseIsSuccessful();
            $ids = array_merge($ids, array_column(json_decode($this->client->getResponse()->getContent(), true), 'id'));
        }

        self::assertCount(6, $ids);
        self::assertCount(6, array_unique($ids));
    }

    public function testListProducersTotalCountIgnoresThePageAndFollowsTheFilters(): void
    {
        $country = $this->makeCountry();
        $this->makeNumberedProducers($country, 5);
        $pending = $this->makeProducerProfile($this->makeUser('pending-page'), $country, VerificationStatus::Pending, 'Ferme en attente');
        $inactive = $this->makeProducerProfile($this->makeUser('inactive-page'), $country, VerificationStatus::Verified, 'Ferme désactivée');
        $inactive->setIsActive(false);
        $this->em->flush();

        // * Sans filtre : 5 vérifiés + 1 en attente = 6 (le producteur désactivé n'est jamais compté).
        $this->farmNamesFrom('/api/producers?pageSize=2');
        self::assertSame('6', $this->client->getResponse()->headers->get('X-Total-Count'));

        // * Avec verifiedOnly : 5, même en page 3 de 2 éléments (1 seul résultat sur cette page).
        $names = $this->farmNamesFrom('/api/producers?verifiedOnly=true&pageSize=2&page=3');
        self::assertCount(1, $names);
        self::assertSame('5', $this->client->getResponse()->headers->get('X-Total-Count'));
        self::assertNotContains($pending->getFarmName(), $names);
    }

    public function testListProducersBeyondTheLastPageReturnsAnEmptyListWithTheTotal(): void
    {
        $this->makeNumberedProducers($this->makeCountry(), 3);

        $names = $this->farmNamesFrom('/api/producers?page=5');

        self::assertSame([], $names);
        self::assertSame('3', $this->client->getResponse()->headers->get('X-Total-Count'));
    }

    #[DataProvider('invalidPagination')]
    public function testListProducersRejectsInvalidPagination(string $query): void
    {
        $this->client->request('GET', '/api/producers?'.$query);

        self::assertResponseStatusCodeSame(400);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPagination(): iterable
    {
        yield 'page à zéro' => ['page=0'];
        yield 'page négative' => ['page=-1'];
        yield 'page non numérique' => ['page=abc'];
        yield 'pageSize à zéro' => ['pageSize=0'];
        yield 'pageSize au-dessus du maximum' => ['pageSize=51'];
        yield 'pageSize non numérique' => ['pageSize=abc'];
    }

    public function testGetProducerReturnsFirstPublicPhotoAsPhotoUrl(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('with-photo'), $country, farmName: 'Ferme avec photo');
        $this->makeProducerPhoto($producer, '/images/producers/privee.jpg', isPublic: false);
        $this->makeProducerPhoto($producer, '/images/producers/ferme.jpg');
        $this->em->flush();

        $this->client->request('GET', '/api/producers/'.$producer->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        // * Une photo non publique ne doit jamais servir de photo de couverture.
        self::assertSame('/images/producers/ferme.jpg', $data['photoUrl']);
    }

    public function testGetProducerReturnsNullPhotoUrlWithoutPublicPhoto(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('no-photo'), $country, farmName: 'Ferme sans photo');
        $this->makeProducerPhoto($producer, '/images/producers/privee.jpg', isPublic: false);
        $this->em->flush();

        $this->client->request('GET', '/api/producers/'.$producer->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('photoUrl', $data);
        self::assertNull($data['photoUrl']);
    }

    public function testGetProducerReturnsActiveProductsWithTheirSeason(): void
    {
        $country = $this->makeCountry();
        $category = $this->makeCategory('Légumes');
        $producer = $this->makeProducerProfile($this->makeUser('with-products'), $country, farmName: 'Ferme maraîchère');

        $tomatoes = $this->makeProduct($category, 'Tomates');
        $tomatoes->setIsActive(true);
        $tomatoes->setSeasonStartMonth(6);
        $tomatoes->setSeasonEndMonth(9);
        $honey = $this->makeProduct($category, 'Miel');
        $honey->setIsActive(true);
        $stopped = $this->makeProduct($category, 'Retiré de la vente');
        $stopped->setIsActive(true);
        $discontinued = $this->makeProduct($category, 'Produit désactivé');
        $discontinued->setIsActive(false);

        $this->makeProducerProduct($producer, $tomatoes);
        $this->makeProducerProduct($producer, $honey);
        $this->makeProducerProduct($producer, $stopped, isActive: false);
        $this->makeProducerProduct($producer, $discontinued);
        $this->em->flush();

        $this->client->request('GET', '/api/producers/'.$producer->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        // * Triés par nom ; la ligne producteur inactive et le produit désactivé sont exclus.
        self::assertSame(['Miel', 'Tomates'], array_column($data['products'], 'name'));
        $tomatoesRow = $data['products'][1];
        self::assertSame(6, $tomatoesRow['seasonStartMonth']);
        self::assertSame(9, $tomatoesRow['seasonEndMonth']);
        // * Saison inconnue : null (le front n'affiche alors aucune période).
        self::assertNull($data['products'][0]['seasonStartMonth']);
        self::assertNull($data['products'][0]['seasonEndMonth']);
    }

    public function testGetProducerReturnsEmptyProductsWhenNoneSold(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('no-products'), $country);
        $this->em->flush();

        $this->client->request('GET', '/api/producers/'.$producer->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame([], $data['products']);
    }

    public function testGetProducerReturnsPickupAndDeliveryFromSettings(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('with-settings'), $country);
        $settings = new ProducerSetting();
        $settings->setProducer($producer);
        $settings->setPickupEnabled(true);
        $this->em->persist($settings);
        $this->em->flush();

        $this->client->request('GET', '/api/producers/'.$producer->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($data['pickupAvailable']);
        self::assertFalse($data['deliveryAvailable'], 'la livraison n\'a pas été activée');
    }

    public function testGetProducerOmitsPickupAndDeliveryWithoutSettings(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('no-settings'), $country);
        $this->em->flush();

        $this->client->request('GET', '/api/producers/'.$producer->getId()->toRfc4122());

        // * Sans ligne producer_settings : « inconnu », pas « false » (qui s'afficherait « Non proposé »).
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayNotHasKey('pickupAvailable', $data);
        self::assertArrayNotHasKey('deliveryAvailable', $data);
    }

    public function testListProducerReviewsReturnsFirstNameAndInitialOnly(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('reviewed'), $country);
        $this->em->flush();

        $client = $this->makeUser('named-client');
        $client->setFirstName('Camille');
        $client->setLastName('renaud');
        $this->makeReview($client, $producer, $this->makeClientRequest($client), 5);
        $this->em->flush();

        $this->client->request('GET', '/api/producers/'.$producer->getId()->toRfc4122().'/reviews');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        // * Jamais le nom complet ni l'e-mail : prénom + initiale du nom en majuscule.
        self::assertSame('Camille R.', $data['reviews'][0]['authorName']);
        self::assertStringNotContainsString('renaud', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('@test.local', $this->client->getResponse()->getContent());
    }

    public function testListProducerReviewsReturnsNullAuthorNameWithoutFirstName(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('reviewed-anon'), $country);
        $this->em->flush();

        $client = $this->makeUser('unnamed-client');
        $this->makeReview($client, $producer, $this->makeClientRequest($client), 4);
        $this->em->flush();

        $this->client->request('GET', '/api/producers/'.$producer->getId()->toRfc4122().'/reviews');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNull($data['reviews'][0]['authorName']);
    }

    public function testFeaturedProducersRejectsInvalidLocation(): void
    {
        $this->client->request('GET', '/api/producers/featured?latitude=999&longitude=1');

        self::assertResponseStatusCodeSame(400);
    }

    // ─────────────── Tris ───────────────

    public function testListProducersDefaultSortPutsVerifiedFirst(): void
    {
        $country = $this->makeCountry();
        // * Noms choisis pour que l'ordre alphabétique soit l'inverse de l'ordre attendu : seul le tri par
        // * pertinence (vérifiés d'abord) peut placer « Zzz » avant « Aaa ».
        $verified = $this->makeProducerProfile($this->makeUser('verified'), $country, VerificationStatus::Verified, 'Zzz vérifiée');
        $pending = $this->makeProducerProfile($this->makeUser('pending'), $country, VerificationStatus::Pending, 'Aaa en attente');
        $this->em->flush();

        $names = $this->farmNamesFrom('/api/producers');

        self::assertLessThan(
            array_search($pending->getFarmName(), $names, true),
            array_search($verified->getFarmName(), $names, true),
        );
    }

    public function testListProducersSortsByNewest(): void
    {
        $country = $this->makeCountry();
        $older = $this->makeProducerProfile($this->makeUser('older'), $country, farmName: 'Aaa ancienne');
        $newer = $this->makeProducerProfile($this->makeUser('newer'), $country, farmName: 'Zzz récente');
        $this->em->flush();
        $this->setOwnerCreatedAt($older, '-10 days');
        $this->setOwnerCreatedAt($newer, '-1 day');

        $names = $this->farmNamesFrom('/api/producers?sort=newest');

        self::assertLessThan(
            array_search($older->getFarmName(), $names, true),
            array_search($newer->getFarmName(), $names, true),
            'le producteur le plus récent doit venir en premier, malgré l\'ordre alphabétique inverse',
        );
    }

    public function testListProducersSortsByPopularityOverTheRecentWindowOnly(): void
    {
        $country = $this->makeCountry();
        $mostViewed = $this->makeProducerProfile($this->makeUser('most'), $country, farmName: 'Zzz très vue');
        $barelyViewed = $this->makeProducerProfile($this->makeUser('barely'), $country, farmName: 'Aaa peu vue');
        $neverViewed = $this->makeProducerProfile($this->makeUser('never'), $country, farmName: 'Bbb jamais vue');
        $this->em->flush();
        $this->recordProfileViews($mostViewed, 50);
        $this->recordProfileViews($barelyViewed, 1);
        // * 1000 vues il y a 60 jours : hors de la fenêtre de 30 jours, elles ne doivent pas compter.
        $this->recordProfileViews($barelyViewed, 1000, daysAgo: 60);

        $names = $this->farmNamesFrom('/api/producers?sort=popularity');

        $positions = array_map(static fn (ProducerProfile $p) => array_search($p->getFarmName(), $names, true), [$mostViewed, $barelyViewed, $neverViewed]);
        self::assertLessThan($positions[1], $positions[0], 'la plus vue passe avant la peu vue');
        self::assertLessThan($positions[2], $positions[1], 'sans aucune vue, le producteur vient en dernier');
    }

    public function testListProducersFallsBackToRelevanceForUnknownOrUnusableSort(): void
    {
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('any'), $country, farmName: 'Ferme quelconque');
        $this->em->flush();

        foreach ([
            '/api/producers?sort=distance',                                       // « distance » sans position
            '/api/producers?sort='.rawurlencode("name'; DROP TABLE x;--"),        // valeur inconnue : jamais du SQL
        ] as $uri) {
            self::assertContains($producer->getFarmName(), $this->farmNamesFrom($uri), $uri);
        }
    }

    // ─────────────── Aides ───────────────

    /** Producteur actif avec le slug donné ; un seul par test (makeCountry). */
    private function makeProducerWithSlug(string $slug): ProducerProfile
    {
        $producer = $this->makeProducerProfile($this->makeUser('slug'), $this->makeCountry(), farmName: 'Ferme du Soleil');
        $producer->setSlug($slug);
        $this->em->flush();

        return $producer;
    }

    /**
     * Crée $count producteurs actifs et vérifiés. Sans $farmName, ils s'appellent « Ferme 01 », « Ferme 02 »… :
     * l'ordre par défaut (nom) est alors prévisible. Avec $farmName, ils portent tous le même nom.
     *
     * @return list<ProducerProfile>
     */
    private function makeNumberedProducers(object $country, int $count, ?string $farmName = null): array
    {
        $producers = [];
        for ($i = 1; $i <= $count; ++$i) {
            $producers[] = $this->makeProducerProfile(
                $this->makeUser('numbered'.$i),
                $country,
                VerificationStatus::Verified,
                $farmName ?? sprintf('Ferme %02d', $i),
            );
        }
        $this->em->flush();

        return $producers;
    }

    /** @return string[] noms des fermes renvoyés par GET $uri, dans l'ordre de la réponse (qui doit être un 200) */
    private function farmNamesFrom(string $uri): array
    {
        $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();

        return array_column(json_decode($this->client->getResponse()->getContent(), true), 'farmName');
    }

    private function makeSeasonalProduct(Category $category, string $name, ?int $startMonth, ?int $endMonth): Product
    {
        $product = $this->makeProduct($category, $name);
        $product->setSeasonStartMonth($startMonth);
        $product->setSeasonEndMonth($endMonth);

        return $product;
    }

    /** Même fuseau que le contrôleur (Europe/Paris) : sinon le test échouerait la nuit du 31 au 1er du mois. */
    private function currentMonth(): int
    {
        return (int) (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('n');
    }

    private function nextMonth(int $month): int
    {
        return ($month % 12) + 1;
    }

    /**
     * Modifie la date de création du COMPTE propriétaire du producteur (identity.users.created_at), en SQL :
     * le constructeur de l'entité la fixe toujours à « maintenant ». producer_profiles n'a pas de created_at.
     */
    private function setOwnerCreatedAt(ProducerProfile $producer, string $relativeDate): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE identity.users SET created_at = :createdAt
             WHERE id = (SELECT owner_user_id FROM producer.producer_profiles WHERE id = :id)',
            [
                'createdAt' => (new \DateTimeImmutable($relativeDate))->format('Y-m-d H:i:sP'),
                'id' => $producer->getId()->toRfc4122(),
            ],
        );
    }

    /** Insère des vues de profil pour un jour donné (aujourd'hui moins $daysAgo) dans analytics.producer_daily_metrics. */
    private function recordProfileViews(ProducerProfile $producer, int $views, int $daysAgo = 0): void
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO analytics.producer_daily_metrics (producer_id, metric_date, profile_views)
             VALUES (:id, CURRENT_DATE - CAST(:daysAgo AS integer), :views)',
            ['id' => $producer->getId()->toRfc4122(), 'daysAgo' => $daysAgo, 'views' => $views],
        );
    }
}