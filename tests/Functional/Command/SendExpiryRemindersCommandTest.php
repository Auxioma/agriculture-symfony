<?php

namespace App\Tests\Functional\Command;

use App\Entity\Matching\ProducerReply;
use App\Enum\ReplyStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Teste app:send-expiry-reminders (cahier fonctionnel : notifications "Demande expirant bientôt" et
 * "Abonnement bientôt renouvelé", absentes tant qu'aucune tâche planifiée n'existait). Vérifie aussi
 * l'idempotence -- la commande est prévue pour tourner une fois par jour sans spammer.
 */
final class SendExpiryRemindersCommandTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private function executeReminderCommand(): CommandTester
    {
        $application = new Application(static::getContainer()->get('kernel'));
        $command = $application->find('app:send-expiry-reminders');
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    public function testNotifiesClientAndEngagedProducerOfExpiringRequest(): void
    {
        $country = $this->makeCountry();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $client = $this->makeUser('client');
        $request = $this->makeClientRequest($client, $product);
        $request->setExpiresAt(new \DateTimeImmutable('+1 day'));

        $producerOwner = $this->makeUser('producer');
        $producer = $this->makeProducerProfile($producerOwner, $country);
        $reply = new ProducerReply();
        $reply->setRequest($request);
        $reply->setProducer($producer);
        $reply->setStatus(ReplyStatus::Sent);
        $this->em->persist($reply);
        $this->em->flush();

        $this->executeReminderCommand();

        $clientNotification = $this->em->getConnection()->fetchAssociative(
            "SELECT data FROM notification.notifications WHERE user_id = :id AND type = 'request_expiring_soon'",
            ['id' => $client->getId()->toRfc4122()]
        );
        self::assertNotFalse($clientNotification);
        self::assertStringContainsString($request->getId()->toRfc4122(), $clientNotification['data']);

        $producerNotification = $this->em->getConnection()->fetchAssociative(
            "SELECT data FROM notification.notifications WHERE user_id = :id AND type = 'request_expiring_soon'",
            ['id' => $producerOwner->getId()->toRfc4122()]
        );
        self::assertNotFalse($producerNotification);
    }

    public function testDoesNotNotifyProducerWhoNeverReplied(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $client = $this->makeUser('client');
        $request = $this->makeClientRequest($client, $product);
        $request->setExpiresAt(new \DateTimeImmutable('+1 day'));
        $this->em->flush();

        $this->executeReminderCommand();

        $count = (int) $this->em->getConnection()->fetchOne(
            "SELECT count(*) FROM notification.notifications WHERE type = 'request_expiring_soon'"
        );
        self::assertSame(1, $count);
    }

    // * Ni trop tôt (hors fenêtre de rappel) ni déjà expirée/annulée -- ACTIVE_REQUEST_STATUSES et le
    // * BETWEEN(now, threshold) doivent tous les deux exclure ces cas.
    public function testDoesNotRemindRequestsOutsideTheWindowOrAlreadyTerminal(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);

        $tooFar = $this->makeClientRequest($this->makeUser('client2'), $product);
        $tooFar->setExpiresAt(new \DateTimeImmutable('+30 days'));

        $alreadyExpired = $this->makeClientRequest($this->makeUser('client3'), $product, status: \App\Enum\RequestStatus::Expired);
        $alreadyExpired->setExpiresAt(new \DateTimeImmutable('+1 day'));

        $this->em->flush();

        $this->executeReminderCommand();

        $count = (int) $this->em->getConnection()->fetchOne(
            "SELECT count(*) FROM notification.notifications WHERE type = 'request_expiring_soon'"
        );
        self::assertSame(0, $count);
    }

    public function testRunningTwiceDoesNotDuplicateTheReminder(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $client = $this->makeUser('client');
        $request = $this->makeClientRequest($client, $product);
        $request->setExpiresAt(new \DateTimeImmutable('+1 day'));
        $this->em->flush();

        $this->executeReminderCommand();
        $this->executeReminderCommand();

        $count = (int) $this->em->getConnection()->fetchOne(
            "SELECT count(*) FROM notification.notifications WHERE type = 'request_expiring_soon' AND user_id = :id",
            ['id' => $client->getId()->toRfc4122()]
        );
        self::assertSame(1, $count);
    }

    public function testNotifiesProducerOfRenewingSubscriptionButNotWhenCancelling(): void
    {
        $country = $this->makeCountry();

        $renewingOwner = $this->makeUser('renewing');
        $renewingProducer = $this->makeProducerProfile($renewingOwner, $country, farmName: 'Ferme renouvelée');
        $this->makeActiveSubscription($renewingProducer, periodEnd: new \DateTimeImmutable('+3 days'));

        $cancellingOwner = $this->makeUser('cancelling');
        $cancellingProducer = $this->makeProducerProfile($cancellingOwner, $country, farmName: 'Ferme résiliée');
        $cancellingSubscription = $this->makeActiveSubscription($cancellingProducer, periodEnd: new \DateTimeImmutable('+3 days'));
        $cancellingSubscription->setCancelAtPeriodEnd(true);

        $this->em->flush();

        $this->executeReminderCommand();

        $renewingNotification = $this->em->getConnection()->fetchAssociative(
            "SELECT type FROM notification.notifications WHERE user_id = :id AND type = 'subscription_renewing_soon'",
            ['id' => $renewingOwner->getId()->toRfc4122()]
        );
        self::assertNotFalse($renewingNotification);

        $cancellingNotification = $this->em->getConnection()->fetchAssociative(
            "SELECT type FROM notification.notifications WHERE user_id = :id AND type = 'subscription_renewing_soon'",
            ['id' => $cancellingOwner->getId()->toRfc4122()]
        );
        self::assertFalse($cancellingNotification);
    }
}
