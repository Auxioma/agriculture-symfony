<?php

namespace App\Tests\Functional\Service;

use App\Entity\Identity\User;
use App\Service\Notification\NotificationService;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

/**
 * Teste l'envoi email réel des notifications (cahier fonctionnel, canaux : "MVP : email et notifications
 * in-app"). NotificationService::notify() créait déjà la notification in-app ; cette suite couvre
 * spécifiquement le nouveau canal email, y compris son isolement en cas d'échec du transport.
 */
final class NotificationServiceTest extends ApiTestCase
{
    use EntityFactoryTrait;
    use MailerAssertionsTrait;

    public function testNotifySendsARealEmailAndRecordsBothDeliveries(): void
    {
        $recipient = $this->makeUser('recipient');
        $this->em->flush();

        $service = static::getContainer()->get(NotificationService::class);
        $notification = $service->notify($recipient, 'request_sent', 'Demande envoyée', 'Votre demande a bien été envoyée.');
        $this->em->flush();

        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertEmailAddressContains($email, 'To', $recipient->getEmail());
        self::assertEmailSubjectContains($email, 'Demande envoyée');
        self::assertEmailTextBodyContains($email, 'Votre demande a bien été envoyée.');

        $deliveries = $this->em->getConnection()->fetchAllAssociative(
            'SELECT channel, status, destination FROM notification.notification_deliveries WHERE notification_id = :id ORDER BY channel',
            ['id' => $notification->getId()->toRfc4122()]
        );
        self::assertCount(2, $deliveries);
        self::assertSame('email', $deliveries[0]['channel']);
        self::assertSame('sent', $deliveries[0]['status']);
        self::assertSame($recipient->getEmail(), $deliveries[0]['destination']);
        self::assertSame('in_app', $deliveries[1]['channel']);
        self::assertSame('sent', $deliveries[1]['status']);
    }

    // * Un transport qui échoue (ex. SMTP en panne) ne doit jamais faire remonter d'exception jusqu'à
    // * l'appelant -- sinon créer une demande, valider un producteur, etc. échouerait à cause d'un simple
    // * souci d'envoi d'email. On force l'échec via un faux transport qui jette systématiquement.
    public function testFailedEmailDeliveryIsRecordedWithoutThrowing(): void
    {
        $recipient = $this->makeUser('recipient');
        $this->em->flush();

        $failingMailer = new class implements \Symfony\Component\Mailer\MailerInterface {
            public function send(\Symfony\Component\Mime\RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
            {
                throw new \Symfony\Component\Mailer\Exception\TransportException('SMTP indisponible (simulation de test).');
            }
        };

        $service = new NotificationService(
            $this->em,
            $failingMailer,
            static::getContainer()->get('logger'),
        );

        $notification = $service->notify($recipient, 'request_sent', 'Demande envoyée', 'Corps du message.');
        $this->em->flush();

        $delivery = $this->em->getConnection()->fetchAssociative(
            "SELECT status, failed_at FROM notification.notification_deliveries WHERE notification_id = :id AND channel = 'email'",
            ['id' => $notification->getId()->toRfc4122()]
        );
        self::assertSame('failed', $delivery['status']);
        self::assertNotNull($delivery['failed_at']);
    }
}
