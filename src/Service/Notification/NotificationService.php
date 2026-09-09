<?php

namespace App\Service\Notification;

use App\Entity\Identity\User;
use App\Entity\Notification\Notification;
use App\Entity\Notification\NotificationDelivery;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final readonly class NotificationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    // * Ne flush pas : le contrôleur appelant décide du moment (même convention que le reste de l'API --
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

        $inAppDelivery = new NotificationDelivery();
        $inAppDelivery->setNotification($notification);
        $inAppDelivery->setChannel('in_app');
        $inAppDelivery->setStatus('sent');
        $inAppDelivery->setSentAt(new \DateTimeImmutable());
        $this->em->persist($inAppDelivery);

        $this->sendEmail($notification, $recipient, $title, $body);

        return $notification;
    }

    // * Cahier fonctionnel : "MVP : email et notifications in-app". Même mécanisme que AuthController::
    // * forgotPassword() (Email brut, pas de template Twig) pour rester cohérent avec ce qui existe déjà.
    // * Un échec d'envoi (SMTP en panne, etc.) est capturé et journalisé plutôt que jeté : une notification
    // * ne doit jamais faire échouer l'action métier qui la déclenche (créer une demande, valider un
    // * producteur...).
    private function sendEmail(Notification $notification, User $recipient, string $title, string $body): void
    {
        $delivery = new NotificationDelivery();
        $delivery->setNotification($notification);
        $delivery->setChannel('email');
        $delivery->setDestination($recipient->getEmail());

        try {
            $this->mailer->send(
                (new Email())
                    ->to($recipient->getEmail())
                    ->subject($title)
                    ->text($body)
            );
            $delivery->setStatus('sent');
            $delivery->setSentAt(new \DateTimeImmutable());
        } catch (TransportExceptionInterface $e) {
            $delivery->setStatus('failed');
            $delivery->setFailedAt(new \DateTimeImmutable());
            $this->logger->error("Échec d'envoi de l'email de notification", [
                'type' => $notification->getType(),
                'recipient' => $recipient->getEmail(),
                'exception' => $e->getMessage(),
            ]);
        }

        $this->em->persist($delivery);
    }
}