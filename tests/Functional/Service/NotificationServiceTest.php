<?php

namespace App\Tests\Functional\Service;

use App\Entity\Identity\User;
use App\Entity\Identity\UserPreference;
use App\Enum\UserStatus;
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

    public function testADeletedAccountGetsTheInAppNotificationButNoEmail(): void
    {
        $recipient = $this->makeUser('supprime');
        $recipient->setStatus(UserStatus::Deleted);
        $this->em->flush();

        $notification = static::getContainer()->get(NotificationService::class)->notify($recipient, 'new_message', 'Nouveau message', 'Vous avez reçu un nouveau message.');
        $this->em->flush();

        // * l'adresse d'un compte supprimé (deleted+...@anonymized.local) ne mène nulle part : un e-mail reviendrait en erreur
        self::assertEmailCount(0);
        $channels = $this->em->getConnection()->fetchFirstColumn('SELECT channel FROM notification.notification_deliveries WHERE notification_id = :id', ['id' => $notification->getId()->toRfc4122()]);
        self::assertSame(['in_app'], $channels);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function typesTheUserCanSwitchOff(): iterable
    {
        yield 'nouvelle demande pertinente' => ['new_relevant_request'];
        yield 'demande expirant bientôt' => ['request_expiring_soon'];
        yield 'nouveau message' => ['new_message'];
        yield 'paiement échoué' => ['payment_failed'];
        yield 'abonnement bientôt renouvelé' => ['subscription_renewing_soon'];
        yield 'profil validé' => ['profile_validated'];
        yield 'nouvel avis client' => ['new_review'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('typesTheUserCanSwitchOff')]
    public function testASwitchedOffTypeKeepsTheInAppNotificationButSendsNoEmail(string $type): void
    {
        $recipient = $this->makeUser('farmer');
        $preference = new UserPreference();
        $preference->setIdUser($recipient);
        $preference->setLocale('fr');
        $preference->setNotificationSettings(['email' => [$type => false]]);
        $recipient->setPreference($preference);
        $this->em->persist($preference);
        $this->em->flush();

        $notification = static::getContainer()->get(NotificationService::class)->notify($recipient, $type, 'Titre', 'Corps du message.');
        $this->em->flush();

        self::assertEmailCount(0);
        $channels = $this->em->getConnection()->fetchFirstColumn('SELECT channel FROM notification.notification_deliveries WHERE notification_id = :id', ['id' => $notification->getId()->toRfc4122()]);
        self::assertSame(['in_app'], $channels);
    }

    public function testOtherTypesAndOtherSwitchesStillSendTheEmail(): void
    {
        $recipient = $this->makeUser('farmer');
        $preference = new UserPreference();
        $preference->setIdUser($recipient);
        $preference->setLocale('fr');
        $preference->setNotificationSettings(['email' => ['payment_failed' => false]]);
        $recipient->setPreference($preference);
        $this->em->persist($preference);
        $this->em->flush();
        $service = static::getContainer()->get(NotificationService::class);

        // * un autre interrupteur, et un type que l'écran ne propose pas de couper : les deux partent
        $service->notify($recipient, 'new_relevant_request', 'Nouvelle demande', 'Une demande vous correspond.');
        $service->notify($recipient, 'profile_rejected', 'Profil non validé', "Votre profil producteur n'a pas été validé.");
        $this->em->flush();

        self::assertEmailCount(2);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function typesTheUserCannotSwitchOff(): iterable
    {
        yield 'refus du profil' => ['profile_rejected'];
        yield 'complément de profil demandé' => ['profile_more_info_needed'];
        yield 'réponse du support' => ['support_reply'];
        yield 'invitation d’équipe' => ['team_invitation'];
        yield 'abonnement en double annulé' => ['subscription_duplicate_cancelled'];
        yield 'notification de client' => ['producer_replied'];
    }

    // * même si une valeur "false" traîne en base pour ce type (écrite à la main, ou par une version plus ancienne de l'écran)
    #[\PHPUnit\Framework\Attributes\DataProvider('typesTheUserCannotSwitchOff')]
    public function testATypeTheScreenDoesNotOfferAlwaysSendsTheEmail(string $type): void
    {
        $recipient = $this->makeUser('farmer');
        $preference = new UserPreference();
        $preference->setIdUser($recipient);
        $preference->setLocale('fr');
        $preference->setNotificationSettings(['email' => [$type => false]]);
        $recipient->setPreference($preference);
        $this->em->persist($preference);
        $this->em->flush();

        static::getContainer()->get(NotificationService::class)->notify($recipient, $type, 'Titre', 'Corps du message.');
        $this->em->flush();

        self::assertEmailCount(1);
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
