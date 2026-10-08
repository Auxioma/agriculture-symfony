<?php

/**
 * Données de démo du compte producteur agri@test.com (Ferme Dupont, voir UserFixtures/ProducerFixtures) pour le
 * dashboard producteur : 6 demandes ouvertes dont 2 urgentes (les 2 de la maquette), et des messages non lus
 * sur ces 2 demandes urgentes, puis 6 demandes déjà traitées (un refus et 5 devis de statuts variés) pour les
 * pages "Demandes reçues" et "Mes devis". Tout passe par des requêtes sur le dépôt (aucun getReference) pour
 * pouvoir aussi être lancée seule sur une base de dev déjà remplie, sans la vider : les demandes ouvertes ne sont
 * créées que si le producteur n'a aucune correspondance, les traitées une par une (seules celles qui manquent sont
 * ajoutées).
 */

namespace App\DataFixtures;

use App\Entity\Catalog\Country;
use App\Entity\Catalog\Currency;
use App\Entity\Catalog\Unit;
use App\Entity\Identity\User;
use App\Entity\Matching\ClientRequest;
use App\Entity\Matching\ProducerReply;
use App\Entity\Matching\RequestAttachment;
use App\Entity\Matching\RequestMatch;
use App\Entity\Messaging\Conversation;
use App\Entity\Messaging\Message;
use App\Entity\Producer\ProducerProfile;
use App\Enum\NeedType;
use App\Enum\ReplyStatus;
use App\Enum\RequestStatus;
use App\Enum\UserStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class DemoProducerFixtures extends Fixture implements DependentFixtureInterface
{
    // * [produit, quantité, unité, budget max, ville, urgence, distance km, message, messages clients non lus]
    private const REQUESTS = [
        ['Tomates bio', '5', 'kg', '12', 'Lyon', 2, '4', 'Bonjour, je cherche des tomates bio pour samedi.', 2],
        ['Miel de fleurs', '2', 'unite', null, 'Avignon', 1, '9', 'souhaite une réponse rapide', 2],
        ['Pommes Gala', '60', 'kg', '2', 'Vienne', 0, '15', 'Pour une cantine scolaire, livraison possible ?', 0],
        ['Oeufs fermiers', '30', 'unite', null, 'Villeurbanne', 0, '6', 'Plutôt des oeufs de plein air.', 0],
        ['Carottes', '10', 'kg', '1.5', 'Lyon', 0, '3', 'Pour des jus, calibre indifférent.', 0],
        ['Fromage de chèvre', '4', 'unite', '8', 'Saint-Étienne', 0, '55', 'Si possible fermier au lait cru.', 0],
    ];

    // * [produit, quantité, unité, ville, distance km, reçue il y a (jours), réponse du producteur, prix de son devis]
    // * Sans prix = refus poli (pas un devis) ; avec prix, le statut (vue, acceptée, refusée, expirée) est en réalité
    // * posé côté client (cahier 8.2) : on le fixe ici pour que la page "Mes devis" montre chaque badge.
    private const TREATED_REQUESTS = [
        ['Haricots verts', '8', 'kg', 'Lyon', '5', 3, ReplyStatus::Sent, '3.5'],
        ['Courgettes', '15', 'kg', 'Vienne', '14', 6, ReplyStatus::Declined, null],
        ['Poireaux', '12', 'kg', 'Bron', '8', 8, ReplyStatus::Seen, '2.2'],
        ['Radis', '30', 'unite', 'Lyon', '3', 10, ReplyStatus::Accepted, '1.5'],
        ['Fraises', '6', 'kg', 'Vienne', '14', 14, ReplyStatus::Declined, '9'],
        ['Salades', '20', 'unite', 'Villeurbanne', '6', 25, ReplyStatus::Expired, '0.9'],
    ];

    // * [prénom, nom] des clients de démo, dans l'ordre de création (les 6 premiers pour les demandes ouvertes)
    private const CLIENT_NAMES = [
        ['Camille', 'Roux'], ['Lucas', 'Martin'], ['Sarah', 'Klein'], ['Julien', 'Bernard'],
        ['Emma', 'Petit'], ['Hugo', 'Durand'], ['Léa', 'Moreau'], ['Noah', 'Simon'],
        ['Marc', 'Dubois'], ['Inès', 'Garcia'], ['Paul', 'Lefèvre'], ['Chloé', 'Faure'],
    ];

    public function load(ObjectManager $manager): void
    {
        $owner = $manager->getRepository(User::class)->findOneBy(['email' => UserFixtures::PRODUCER_DEMO_EMAIL]);
        $producer = $owner?->getProducerProfile();
        if (null === $producer) {
            return;
        }

        $this->openRequests($manager, $producer);
        $this->treatedRequests($manager, $producer);
        $manager->flush();
    }

    private function openRequests(ObjectManager $manager, ProducerProfile $producer): void
    {
        if ($manager->getRepository(RequestMatch::class)->count(['producer' => $producer]) > 0) {
            return;
        }

        $country = $manager->getRepository(Country::class)->findOneBy(['code' => 'FR']);
        $currency = $manager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);

        foreach (self::REQUESTS as $i => [$product, $quantity, $unitCode, $budget, $city, $urgency, $distance, $message, $unread]) {
            $client = $this->demoClient($manager, $i);

            $request = new ClientRequest();
            $request->setClient($client);
            $request->setCustomProduct($product);
            // * une cantine = un client professionnel
            $request->setNeedType('Pommes Gala' === $product ? NeedType::Professional : NeedType::OneShot);
            $request->setQuantity($quantity);
            $request->setUnit($manager->getRepository(Unit::class)->findOneBy(['code' => $unitCode]));
            $request->setBudgetMax($budget);
            $request->setCurrency($budget ? $currency : null);
            $request->setCountry($country);
            $request->setCity($city);
            $request->setUrgencyLevel($urgency);
            $request->setMessage($message);
            $request->setStatus(RequestStatus::Sent);
            $request->setExpiresAt(new \DateTimeImmutable('+30 days'));
            if (0 === $i) {
                // * la plus complète (celle de la maquette du détail) : date, code postal, retrait, pièce jointe
                $request->setDesiredDate(new \DateTimeImmutable('+16 days'));
                $request->setPostalCode('69003');
                $request->setPickupWanted(true);
                $attachment = new RequestAttachment();
                $attachment->setRequest($request);
                $attachment->setFileName('photo-parcelle.jpg');
                $manager->persist($attachment);
            }
            $manager->persist($request);

            $match = new RequestMatch();
            $match->setRequest($request);
            $match->setProducer($producer);
            $match->setScore('0.90');
            $match->setDistanceKm($distance);
            $manager->persist($match);

            if ($unread > 0) {
                $this->conversation($manager, $request, $client, $producer, $unread);
            }
        }
    }

    private function treatedRequests(ObjectManager $manager, ProducerProfile $producer): void
    {
        $country = $manager->getRepository(Country::class)->findOneBy(['code' => 'FR']);
        $euro = $manager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);

        foreach (self::TREATED_REQUESTS as $i => [$product, $quantity, $unitCode, $city, $distance, $daysAgo, $replyStatus, $price]) {
            $received = new \DateTimeImmutable("-$daysAgo days");
            $client = $this->demoClient($manager, \count(self::REQUESTS) + $i);
            // * ligne par ligne : relancer la fixture sur une base déjà remplie n'ajoute que les demandes manquantes
            if (null !== $manager->getRepository(ClientRequest::class)->findOneBy(['client' => $client, 'customProduct' => $product])) {
                continue;
            }

            $request = new ClientRequest();
            $request->setClient($client);
            $request->setCustomProduct($product);
            $request->setNeedType(NeedType::OneShot);
            $request->setQuantity($quantity);
            $request->setUnit($manager->getRepository(Unit::class)->findOneBy(['code' => $unitCode]));
            $request->setCountry($country);
            $request->setCity($city);
            $request->setStatus(RequestStatus::RepliesReceived);
            $request->setExpiresAt(new \DateTimeImmutable('+30 days'));
            $manager->persist($request);

            $match = new RequestMatch();
            $match->setRequest($request);
            $match->setProducer($producer);
            $match->setScore('0.80');
            $match->setDistanceKm($distance);
            $this->backdate($match, $received);
            $manager->persist($match);

            $reply = new ProducerReply();
            $reply->setRequest($request);
            $reply->setProducer($producer);
            $reply->setStatus($replyStatus);
            $reply->setReplyText(ReplyStatus::Sent === $replyStatus ? 'Bonjour, j’en ai de disponible, je vous appelle.' : null);
            if (null !== $price) {
                $reply->setPriceAmount($price);
                $reply->setPriceUnit($request->getUnit());
                $reply->setCurrency($euro);
            }
            $this->backdate($reply, $received->modify('+2 hours'));
            $manager->persist($reply);
        }
    }

    // * createdAt n'a pas de setter (posé à la création) : on antidate par réflexion pour des dates variées.
    private function backdate(object $entity, \DateTimeImmutable $date): void
    {
        (new \ReflectionProperty($entity, 'createdAt'))->setValue($entity, $date);
    }

    private function demoClient(ObjectManager $manager, int $index): User
    {
        $email = sprintf('demo.client%d@example.org', $index + 1);
        $client = $manager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null !== $client) {
            return $client;
        }

        $client = new User();
        $client->setEmail($email);
        $client->setPasswordHash('!compte-de-demo-sans-connexion');
        $client->setRoles([User::ROLE_CLIENT]);
        [$firstName, $lastName] = self::CLIENT_NAMES[$index];
        $client->setFirstName($firstName);
        $client->setLastName($lastName);
        $client->setStatus(UserStatus::Active);
        $manager->persist($client);

        return $client;
    }

    private function conversation(ObjectManager $manager, ClientRequest $request, User $client, ProducerProfile $producer, int $unread): void
    {
        $conversation = new Conversation();
        $conversation->setRequest($request);
        $conversation->setClient($client);
        $conversation->setProducer($producer);
        $conversation->setLastMessageAt(new \DateTimeImmutable('-1 hour'));
        $manager->persist($conversation);

        // * Les messages du client restent "non lus" pour le producteur (aucun MessageRead) ; sa propre réponse,
        // * elle, ne compte jamais dans ses non lus.
        for ($n = 1; $n <= $unread; ++$n) {
            $message = new Message();
            $message->setConversation($conversation);
            $message->setSender($client);
            $message->setContent(1 === $n ? (string) $request->getMessage() : 'Avez-vous des nouvelles ?');
            $manager->persist($message);
        }

        $reply = new Message();
        $reply->setConversation($conversation);
        $reply->setSender($producer->getOwner());
        $reply->setContent('Bonjour, je regarde ça et je reviens vers vous.');
        $manager->persist($reply);
    }

    public function getDependencies(): array
    {
        return [ProducerFixtures::class, ConversationFixtures::class, CatalogFixtures::class, UserFixtures::class];
    }
}
