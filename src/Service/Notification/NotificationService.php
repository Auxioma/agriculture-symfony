<?php

namespace App\Service\Notification;

use App\Entity\Identity\User;
use App\Entity\Notification\Notification;
use App\Entity\Notification\NotificationDelivery;
use Doctrine\ORM\EntityManagerInterface;

final readonly class NotificationService
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    // * Ne flush pas : le contrôleur appelant décide du moment (même convention que le reste de l'API
    // * un seul flush() en fin de méthode, après toutes les opérations).
    public function notify(User $recipient, string $type, string $title, string $body, array $data = []): Notification
    {
        $notification = new Notification();
        $notification->setIdUser($recipient);
        $notification->setType($type);
        $notification->setTitle($title);
        $notification->setBody($body);
        $notification->setData($data);
        $this->em->persist($notification);

        // * Canal "in_app" seulement pour ce round -- l'envoi email réel (via NotificationTemplate +
        // * Mailer) est prévu pour le round 2, en même temps que le branchement sur les déclencheurs.
        $delivery = new NotificationDelivery();
        $delivery->setNotification($notification);
        $delivery->setChannel('in_app');
        $delivery->setStatus('sent');
        $delivery->setSentAt(new \DateTimeImmutable());
        $this->em->persist($delivery);

        return $notification;
    }
}