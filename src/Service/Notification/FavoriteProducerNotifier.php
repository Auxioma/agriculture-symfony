<?php

namespace App\Service\Notification;

use App\Entity\Engagement\Favorite;
use App\Entity\Producer\ProducerProduct;
use App\Entity\Producer\ProducerProfile;
use App\Enum\UserStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Cahier fonctionnel, notifications client : « Producteur favori avec nouvelle disponibilité ». Prévient les clients qui ont un producteur
 * en favori (Favorite, type producer_profile) quand il met un produit à disposition : un produit ajouté actif, ou un produit remis actif
 * (voir ProducerProductController).
 *
 * Une seule notification par abonné et par producteur toutes les 24 h, même si le producteur ajoute dix produits d'affilée : celui qui vient
 * d'en être prévenu ne l'est pas à nouveau (relu dans Notification.data, comme SendExpiryRemindersCommand : pas de champ de plus). Un abonné
 * qui a coupé ce type dans Paramètres n'en reçoit pas (NotificationService::notify le décide).
 *
 * ! Envoi dans la requête du producteur, sans file de messages (aucune n'est installée) : au plus MAX_FOLLOWERS abonnés par ajout. Au-delà
 * ! il faudrait une tâche en arrière-plan (cahier V2, « Relances automatiques »).
 */
final readonly class FavoriteProducerNotifier
{
    public const TYPE = 'favorite_producer_available';
    public const FAVORITE_TARGET = 'producer_profile';
    public const MAX_FOLLOWERS = 200;
    // * « toutes les 24 h » : l'abonné prévenu depuis moins longtemps ne l'est pas à nouveau
    private const QUIET_PERIOD = '-24 hours';

    public function __construct(
        private EntityManagerInterface $em,
        private NotificationService $notifications,
    ) {
    }

    /**
     * Ne flush pas (comme NotificationService) : l'appelant le fait avec le reste de son action.
     *
     * @return int le nombre d'abonnés prévenus
     */
    public function notifyNewAvailability(ProducerProfile $producer, ProducerProduct $producerProduct): int
    {
        // * un producteur désactivé n'est plus sur le site, et un produit inactif n'est pas « disponible »
        if (!$producer->isActive() || !$producerProduct->isActive()) {
            return 0;
        }

        $producerId = $producer->getId()->toRfc4122();
        $since = new \DateTimeImmutable(self::QUIET_PERIOD);
        $favorites = $this->em->createQueryBuilder()
            ->select('f', 'u')
            ->from(Favorite::class, 'f')
            ->join('f.idUser', 'u')
            ->where('f.targetType = :type')
            ->andWhere('f.targetId = :producerId')
            ->setParameter('type', self::FAVORITE_TARGET)
            ->setParameter('producerId', $producer->getId(), UuidType::NAME)
            ->orderBy('f.createdAt', 'ASC')
            ->addOrderBy('f.id', 'ASC')
            ->setMaxResults(self::MAX_FOLLOWERS)
            ->getQuery()
            ->getResult();

        $sent = 0;
        foreach ($favorites as $favorite) {
            $follower = $favorite->getIdUser();
            // * le producteur qui a mis sa propre fiche en favori n'a pas à être prévenu de ce qu'il vient de faire
            if ($follower === $producer->getOwner() || UserStatus::Deleted === $follower->getStatus()) {
                continue;
            }
            if ($this->recentlyNotified($follower->getId()->toRfc4122(), $producerId, $since)) {
                continue;
            }

            $notification = $this->notifications->notify(
                $follower,
                self::TYPE,
                'Nouveauté chez un producteur favori',
                \sprintf('%s propose maintenant %s.', $producer->getFarmName(), $producerProduct->getProduct()->getName()),
                ['producerId' => $producerId, 'producerSlug' => $producer->getSlug(), 'productName' => $producerProduct->getProduct()->getName()]
            );
            // * null : l'abonné a coupé ce type dans Paramètres, rien n'est parti
            if (null !== $notification) {
                ++$sent;
            }
        }

        return $sent;
    }

    private function recentlyNotified(string $userId, string $producerId, \DateTimeImmutable $since): bool
    {
        return false !== $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM notification.notifications WHERE user_id = :userId AND type = :type AND data->>\'producerId\' = :producerId AND created_at > :since LIMIT 1',
            ['userId' => $userId, 'type' => self::TYPE, 'producerId' => $producerId, 'since' => $since],
            ['since' => Types::DATETIMETZ_IMMUTABLE]
        );
    }
}
