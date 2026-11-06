<?php

/**
 * Données de démo du compte client client@test.com pour son espace : six demandes dans des statuts variés (en attente, réponses reçues,
 * conversation ouverte, accord trouvé, archivée, expirée), les réponses et devis de plusieurs producteurs (nouvelle, vue, acceptée,
 * refusée), les conversations qui vont avec (messages lus et non lus), un avis publié, des notifications (lues et non lues, avec la
 * demande ou la conversation concernée), des favoris (producteurs, produits, catégorie), deux adresses et deux recherches sauvegardées.
 *
 * À lancer SEULE, sur une base de dev déjà remplie, sans la vider :
 *   php bin/console doctrine:fixtures:load --append --group=demo-client
 * Elle n'a volontairement aucune dépendance : avec un groupe, le chargeur ajouterait aussi les dépendances (UserFixtures créerait de
 * nouveaux comptes aléatoires). Tout passe par des requêtes sur le dépôt, et chaque bloc ne s'ajoute que s'il manque (la relancer ne
 * duplique rien). Sans producteurs de démo (Ferme Dupont, Ferme Adam...) elle ne fait rien. Le compte client est créé s'il n'existe pas
 * (mot de passe ClientDemo123!), jamais modifié sinon.
 */

namespace App\DataFixtures;

use App\Entity\Catalog\Category;
use App\Entity\Catalog\Country;
use App\Entity\Catalog\Currency;
use App\Entity\Catalog\Product;
use App\Entity\Catalog\Unit;
use App\Entity\Engagement\Favorite;
use App\Entity\Engagement\SavedSearch;
use App\Entity\Identity\User;
use App\Entity\Identity\UserAddress;
use App\Entity\Matching\ClientRequest;
use App\Entity\Matching\ProducerReply;
use App\Entity\Messaging\Conversation;
use App\Entity\Messaging\Message;
use App\Entity\Messaging\MessageRead;
use App\Entity\Notification\Notification;
use App\Entity\Producer\ProducerProfile;
use App\Entity\Trust\Review;
use App\Enum\NeedType;
use App\Enum\ReplyStatus;
use App\Enum\RequestStatus;
use App\Enum\ReviewStatus;
use App\Enum\UserStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

class DemoClientFixtures extends Fixture implements FixtureGroupInterface
{
    public const CLIENT_EMAIL = 'client@test.com';

    // * [produit, quantité, unité, ville, position "lon lat", statut, il y a (jours), expire dans (jours), message]
    private const REQUESTS = [
        ['Tomates anciennes', '20', 'kg', 'Lyon', '4.8357 45.7640', RequestStatus::ConversationOpen, 6, 24, 'Pour un repas de famille samedi, des tomates de plein champ si possible.'],
        ['Miel de fleurs', '6', 'unite', 'Avignon', '4.8055 43.9493', RequestStatus::RepliesReceived, 2, 28, 'Des pots de 500 g, pour des cadeaux.'],
        ['Pommes Gala', '40', 'kg', 'Vienne', '4.8745 45.5250', RequestStatus::Sent, 1, 3, 'Pour une association, livraison possible ?'],
        ['Fromage de chèvre', '3', 'kg', 'Bordeaux', '-0.5792 44.8378', RequestStatus::DealFound, 25, 5, 'Du frais et du demi-sec.'],
        ['Pommes de terre', '50', 'kg', 'Nantes', '-1.5536 47.2184', RequestStatus::Archived, 60, -30, 'Variété à chair ferme.'],
        ['Courges butternut', '15', 'kg', 'Rennes', '-1.6778 48.1173', RequestStatus::Expired, 45, -15, null],
    ];

    // * Réponses de producteurs, par demande (même ordre que REQUESTS) :
    // * [ferme, statut, prix, quantité dispo, texte, [messages : [qui (p = producteur, c = client), il y a (heures), texte, lu par le client]]]
    private const REPLIES = [
        0 => [
            ['Ferme Adam', ReplyStatus::Accepted, '4.50', '30', 'Bonjour, disponibles dès cette semaine, récolte du matin.', [
                ['c', 118, 'Bonjour, parfait, je prends les 20 kg. Retrait samedi matin possible ?', true],
                ['p', 96, 'Oui, samedi 9 h à la ferme, je vous prépare les cagettes.', true],
                ['p', 95, 'Pensez à apporter des cagettes si vous en avez.', false],
            ]],
            ['Ferme Dupont', ReplyStatus::Seen, '3.80', '25', 'Prix valable jusqu’à samedi, retrait à la ferme.', []],
            ['Ferme Lemoine', ReplyStatus::Declined, '5.20', '20', 'Je peux livrer à Lyon le jeudi.', [
                ['c', 100, 'Merci, mais je préfère récupérer sur place.', true],
                ['p', 99, 'Pas de souci, bonne récolte à vous !', true],
            ]],
        ],
        1 => [
            ['Ferme Dupont', ReplyStatus::Sent, '9.50', '24', 'Miel de printemps, pots de 500 g, retrait à la ferme ou envoi en colis.', []],
            ['Ferme Pottier', ReplyStatus::Sent, null, null, 'Bonjour, je peux vous en proposer, appelez-moi pour en parler.', []],
        ],
        3 => [
            ['Ferme Traore', ReplyStatus::Accepted, '14.00', '4', 'Frais et demi-sec disponibles, au marché le samedi ou à la ferme.', [
                ['c', 590, 'Je prends 2 kg de frais et 1 kg de demi-sec.', true],
                ['p', 588, 'C’est noté, je vous les mets de côté.', true],
            ]],
        ],
    ];

    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
    }

    public static function getGroups(): array
    {
        return ['demo-client'];
    }

    public function load(ObjectManager $manager): void
    {
        $producers = $this->producers($manager, ['Ferme Adam', 'Ferme Dupont', 'Ferme Lemoine', 'Ferme Pottier', 'Ferme Traore']);
        $country = $manager->getRepository(Country::class)->findOneBy(['code' => 'FR']);
        if (\count($producers) < 5 || null === $country) {
            return;
        }

        $client = $this->client($manager);
        $manager->flush();

        foreach (self::REQUESTS as $index => $row) {
            $this->request($manager, $client, $producers, $country, $index, $row);
        }

        $this->favorites($manager, $client, $producers);
        $this->addresses($manager, $client);
        $this->savedSearches($manager, $client);
        $manager->flush();
    }

    /**
     * @param array<string, ProducerProfile> $producers
     * @param array<int, mixed>              $row
     */
    private function request(ObjectManager $manager, User $client, array $producers, Country $country, int $index, array $row): void
    {
        [$product, $quantity, $unitCode, $city, $position, $status, $daysAgo, $expiresIn, $message] = $row;
        // * bloc par bloc : relancer la fixture n'ajoute que les demandes qui manquent
        if (null !== $manager->getRepository(ClientRequest::class)->findOneBy(['client' => $client, 'customProduct' => $product])) {
            return;
        }

        $created = new \DateTimeImmutable("-$daysAgo days");
        $unit = $manager->getRepository(Unit::class)->findOneBy(['code' => $unitCode]);
        $euro = $manager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);

        $request = new ClientRequest();
        $request->setClient($client);
        $request->setCustomProduct($product);
        $request->setNeedType(NeedType::OneShot);
        $request->setQuantity($quantity);
        $request->setUnit($unit);
        $request->setCurrency($euro);
        $request->setCountry($country);
        $request->setCity($city);
        $request->setLocation('SRID=4326;POINT('.$position.')');
        $request->setMessage($message);
        $request->setStatus($status);
        $request->setDesiredDate($created->modify('+10 days'));
        $request->setExpiresAt(new \DateTimeImmutable("$expiresIn days"));
        $this->backdate($request, $created);
        $manager->persist($request);

        $requestId = $request->getId()->toRfc4122();
        $this->notify($manager, $client, 'request_sent', 'Demande envoyée', 'Votre demande a bien été envoyée et est en cours de traitement.', ['requestId' => $requestId], $created, $daysAgo > 1);

        if ($this->expiresSoon($status, $expiresIn)) {
            $this->notify($manager, $client, 'request_expiring_soon', 'Demande expirant bientôt', 'Votre demande arrive bientôt à expiration.', ['requestId' => $requestId], new \DateTimeImmutable('-3 hours'), false);
        }

        foreach (self::REPLIES[$index] ?? [] as [$farm, $replyStatus, $price, $available, $text, $messages]) {
            $producer = $producers[$farm];
            $sentAt = $created->modify('+'.(3 + $index).' hours');

            $reply = new ProducerReply();
            $reply->setRequest($request);
            $reply->setProducer($producer);
            $reply->setStatus($replyStatus);
            $reply->setReplyText($text);
            if (null !== $price) {
                $reply->setPriceAmount($price);
                $reply->setPriceUnit($unit);
                $reply->setCurrency($euro);
                $reply->setAvailableQuantity($available);
                $reply->setAvailabilityDate($sentAt->modify('+4 days'));
                $reply->setValidUntil($sentAt->modify('+9 days'));
                $reply->setPickupConditions('Retrait à la ferme');
                $reply->setDeliveryConditions('Livraison possible à moins de 20 km');
            }
            $this->backdate($reply, $sentAt);
            $manager->persist($reply);

            $conversation = $this->conversation($manager, $request, $client, $producer, $reply, $sentAt, $messages);
            $title = null !== $price ? ['quote_received', 'Devis reçu'] : ['producer_replied', 'Un producteur a répondu'];
            $unread = ReplyStatus::Sent === $replyStatus;
            $this->notify($manager, $client, $title[0], $title[1], 'Vous avez une nouvelle réponse à votre demande.', ['requestId' => $requestId, 'conversationId' => $conversation->getId()->toRfc4122()], $sentAt, !$unread);
        }

        // * l'avis du client sur le producteur de la demande conclue
        if (RequestStatus::DealFound === $status) {
            $review = new Review();
            $review->setClient($client);
            $review->setProducer($producers['Ferme Traore']);
            $review->setRequest($request);
            $review->setRating(5);
            $review->setComment('Fromage excellent, accueil très agréable.');
            $review->setStatus(ReviewStatus::Published);
            $this->backdate($review, $created->modify('+4 days'));
            $manager->persist($review);
        }

        $manager->flush();
    }

    /**
     * La conversation d'une réponse : le texte du producteur en premier message (comme ProducerRequestController::replyToRequest()), puis
     * les échanges. Un message du producteur que le client n'a pas lu reste sans MessageRead : c'est ce qui fait le compteur de non lus.
     *
     * @param list<array{0: string, 1: int, 2: string, 3: bool}> $messages
     */
    private function conversation(ObjectManager $manager, ClientRequest $request, User $client, ProducerProfile $producer, ProducerReply $reply, \DateTimeImmutable $sentAt, array $messages): Conversation
    {
        $conversation = new Conversation();
        $conversation->setRequest($request);
        $conversation->setClient($client);
        $conversation->setProducer($producer);
        $this->backdate($conversation, $sentAt);
        $manager->persist($conversation);

        $first = [['p', 0, (string) $reply->getReplyText(), ReplyStatus::Sent !== $reply->getStatus()]];
        $last = $sentAt;
        foreach ([...$first, ...$messages] as [$who, $hours, $text, $read]) {
            // * les heures des échanges sont comptées à rebours depuis maintenant, sauf le premier message (celui de la réponse)
            $at = 0 === $hours ? $sentAt : new \DateTimeImmutable("-$hours hours");
            $sender = 'p' === $who ? $producer->getOwner() : $client;

            $message = new Message();
            $message->setConversation($conversation);
            $message->setSender($sender);
            $message->setContent($text);
            $this->backdate($message, $at);
            $manager->persist($message);

            if ('p' === $who && $read) {
                $receipt = new MessageRead();
                $receipt->setMessage($message);
                $receipt->setIdUser($client);
                $receipt->setReadAt($at->modify('+10 minutes'));
                $manager->persist($receipt);
            }
            $last = $at > $last ? $at : $last;
        }
        $conversation->setLastMessageAt($last);

        return $conversation;
    }

    /**
     * @param array<string, ProducerProfile> $producers
     */
    private function favorites(ObjectManager $manager, User $client, array $producers): void
    {
        $targets = [
            ['producer_profile', $producers['Ferme Dupont']->getId()],
            ['producer_profile', $producers['Ferme Adam']->getId()],
            ['producer_profile', $producers['Ferme Lemoine']->getId()],
        ];
        foreach ($manager->getRepository(Product::class)->findBy([], ['name' => 'ASC'], 2) as $product) {
            $targets[] = ['product', $product->getId()];
        }
        $category = $manager->getRepository(Category::class)->findOneBy([], ['name' => 'ASC']);
        if (null !== $category) {
            $targets[] = ['category', $category->getId()];
        }

        foreach ($targets as $i => [$type, $id]) {
            if (null !== $manager->getRepository(Favorite::class)->findOneBy(['idUser' => $client, 'targetType' => $type, 'targetId' => $id])) {
                continue;
            }
            $favorite = new Favorite();
            $favorite->setIdUser($client);
            $favorite->setTargetType($type);
            $favorite->setTargetId($id);
            $this->backdate($favorite, new \DateTimeImmutable('-'.(10 + $i * 3).' days'));
            $manager->persist($favorite);
        }
    }

    private function addresses(ObjectManager $manager, User $client): void
    {
        if ($manager->getRepository(UserAddress::class)->count(['idUser' => $client]) > 0) {
            return;
        }

        foreach ([['Domicile', '12 rue de la République', '69002', 'Lyon', true], ['Bureau', '8 avenue Henri Barbusse', '69100', 'Villeurbanne', false]] as [$label, $line, $postalCode, $city, $default]) {
            $address = new UserAddress();
            $address->setLabel($label);
            $address->setAddress1($line);
            $address->setPostalCode($postalCode);
            $address->setCity($city);
            $address->setCountryCode('FR');
            $address->setIsDefault($default);
            $client->addUserAddress($address);
            $manager->persist($address);
        }
    }

    private function savedSearches(ObjectManager $manager, User $client): void
    {
        if ($manager->getRepository(SavedSearch::class)->count(['idUser' => $client]) > 0) {
            return;
        }

        $category = $manager->getRepository(Category::class)->findOneBy([], ['name' => 'ASC']);
        $searches = [
            ['Producteurs vérifiés autour de Lyon', ['verifiedOnly' => true, 'latitude' => 45.764, 'longitude' => 4.8357, 'radiusKm' => 30, 'pickupAvailable' => true], true],
            ['Livraison de la première catégorie', ['categoryId' => $category?->getId()->toRfc4122(), 'deliveryAvailable' => true], false],
        ];
        foreach ($searches as [$name, $criteria, $alerts]) {
            $search = new SavedSearch();
            $search->setIdUser($client);
            $search->setName($name);
            $search->setCriteria($criteria);
            $search->setNotificationsEnabled($alerts);
            $manager->persist($search);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function notify(ObjectManager $manager, User $client, string $type, string $title, string $body, array $data, \DateTimeImmutable $at, bool $read): void
    {
        $notification = new Notification();
        $notification->setIdUser($client);
        $notification->setType($type);
        $notification->setTitle($title);
        $notification->setBody($body);
        $notification->setData($data);
        if ($read) {
            $notification->setReadAt($at->modify('+1 hour'));
        }
        $this->backdate($notification, $at);
        $manager->persist($notification);
    }

    /**
     * @param list<string> $farmNames
     *
     * @return array<string, ProducerProfile>
     */
    private function producers(ObjectManager $manager, array $farmNames): array
    {
        $found = [];
        foreach ($farmNames as $name) {
            $producer = $manager->getRepository(ProducerProfile::class)->findOneBy(['farmName' => $name]);
            if (null !== $producer) {
                $found[$name] = $producer;
            }
        }

        return $found;
    }

    private function client(ObjectManager $manager): User
    {
        $client = $manager->getRepository(User::class)->findOneBy(['email' => self::CLIENT_EMAIL]);
        if (null !== $client) {
            return $client;
        }

        $client = new User();
        $client->setEmail(self::CLIENT_EMAIL);
        $client->setPasswordHash($this->passwordHasher->hashPassword($client, 'ClientDemo123!'));
        $client->setRoles([User::ROLE_CLIENT]);
        $client->setFirstName('Camille');
        $client->setLastName('Martin');
        $client->setStatus(UserStatus::Active);
        $manager->persist($client);

        return $client;
    }

    // * createdAt n'a pas de setter (posé à la création) : on antidate par réflexion pour des dates variées.
    private function backdate(object $entity, \DateTimeImmutable $date): void
    {
        (new \ReflectionProperty($entity, 'createdAt'))->setValue($entity, $date);
    }

    // * une demande « en attente » qui expire dans moins de cinq jours : celle qui reçoit le rappel « Demande expirant bientôt »
    private function expiresSoon(RequestStatus $status, int $expiresIn): bool
    {
        return RequestStatus::Sent === $status && $expiresIn > 0 && $expiresIn <= 5;
    }
}
