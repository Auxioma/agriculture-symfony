<?php

namespace App\Tests\Functional\Command;

use App\Entity\Identity\User;
use App\Entity\Identity\UserPreference;
use App\Entity\Messaging\Conversation;
use App\Entity\Producer\ProducerProfile;
use App\Enum\ConversationStatus;
use App\Enum\ReviewStatus;
use App\Enum\UserStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Teste app:send-review-reminders (cahier fonctionnel : notification client « Avis à laisser »). Vérifie la fenêtre (échange terminé
 * depuis plus de 7 jours, pas plus de 30), la règle de POST /api/reviews (pas de rappel à qui a déjà laissé un avis), et l'idempotence --
 * la commande est prévue pour tourner une fois par jour sans spammer.
 */
final class SendReviewRemindersCommandTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private function executeReminderCommand(): CommandTester
    {
        $application = new Application(static::getContainer()->get('kernel'));
        $tester = new CommandTester($application->find('app:send-review-reminders'));
        $tester->execute([]);

        return $tester;
    }

    private function makeProducer(): ProducerProfile
    {
        return $this->makeProducerProfile($this->makeUser('producer'.bin2hex(random_bytes(3))), $this->makeCountry('FR', 'France'), farmName: 'Ferme du Soleil');
    }

    private function makeConversation(User $client, ProducerProfile $producer, string $lastActivity, ConversationStatus $status = ConversationStatus::Open): Conversation
    {
        $conversation = new Conversation();
        $conversation->setRequest($this->makeClientRequest($client));
        $conversation->setClient($client);
        $conversation->setProducer($producer);
        $conversation->setStatus($status);
        $conversation->setLastMessageAt(new \DateTimeImmutable($lastActivity));
        $this->em->persist($conversation);

        return $conversation;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reminders(User $user): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            "SELECT data FROM notification.notifications WHERE user_id = :id AND type = 'review_reminder'",
            ['id' => $user->getId()->toRfc4122()]
        );
    }

    public function testRemindsTheClientOfAnExchangeThatEndedMoreThanAWeekAgo(): void
    {
        $client = $this->makeUser('client');
        $producer = $this->makeProducer();
        $conversation = $this->makeConversation($client, $producer, '-10 days');
        $this->em->flush();

        $tester = $this->executeReminderCommand();

        $rows = $this->reminders($client);
        self::assertCount(1, $rows);
        $data = json_decode($rows[0]['data'], true);
        self::assertSame($conversation->getId()->toRfc4122(), $data['conversationId']);
        self::assertSame($conversation->getRequest()->getId()->toRfc4122(), $data['requestId']);
        self::assertSame($producer->getId()->toRfc4122(), $data['producerId']);
        self::assertSame('Ferme du Soleil', $data['producerName']);
        self::assertStringContainsString('1 rappel', $tester->getDisplay());
    }

    public function testIsIdempotent(): void
    {
        $client = $this->makeUser('client');
        $this->makeConversation($client, $this->makeProducer(), '-10 days');
        $this->em->flush();

        $this->executeReminderCommand();
        $second = $this->executeReminderCommand();

        self::assertCount(1, $this->reminders($client));
        self::assertStringContainsString('0 rappel', $second->getDisplay());
    }

    public function testDoesNotRemindBeforeAWeekOrAfterAMonth(): void
    {
        $client = $this->makeUser('client');
        $producer = $this->makeProducer();
        $this->makeConversation($client, $producer, '-2 days');
        $this->makeConversation($client, $producer, '-60 days');
        $this->em->flush();

        $this->executeReminderCommand();

        self::assertSame([], $this->reminders($client));
    }

    public function testUsesTheCreationDateOfAConversationWithoutMessages(): void
    {
        $client = $this->makeUser('client');
        $conversation = new Conversation();
        $conversation->setRequest($this->makeClientRequest($client));
        $conversation->setClient($client);
        $conversation->setProducer($this->makeProducer());
        $this->em->persist($conversation);
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            'UPDATE messaging.conversations SET created_at = :at WHERE id = :id',
            ['at' => (new \DateTimeImmutable('-10 days'))->format('Y-m-d H:i:sP'), 'id' => $conversation->getId()->toRfc4122()]
        );

        $this->executeReminderCommand();

        self::assertCount(1, $this->reminders($client));
    }

    public function testDoesNotRemindWhoAlreadyLeftAReviewForThisProducerAndRequest(): void
    {
        $client = $this->makeUser('client');
        $producer = $this->makeProducer();
        $reviewed = $this->makeConversation($client, $producer, '-10 days');
        $this->makeReview($client, $producer, $reviewed->getRequest(), 5, ReviewStatus::Pending);
        $other = $this->makeConversation($client, $producer, '-11 days');
        $this->em->flush();

        $this->executeReminderCommand();

        // * seule l'autre demande (sans avis) est rappelée
        $rows = $this->reminders($client);
        self::assertCount(1, $rows);
        self::assertSame($other->getId()->toRfc4122(), json_decode($rows[0]['data'], true)['conversationId']);
    }

    public function testDoesNotRemindForAReportedOrArchivedConversation(): void
    {
        $client = $this->makeUser('client');
        $producer = $this->makeProducer();
        $this->makeConversation($client, $producer, '-10 days', ConversationStatus::Reported);
        $this->makeConversation($client, $producer, '-10 days', ConversationStatus::Archived);
        $this->em->flush();

        $this->executeReminderCommand();

        self::assertSame([], $this->reminders($client));
    }

    public function testDoesNotRemindAClosedAccount(): void
    {
        $client = $this->makeUser('client');
        $client->setStatus(UserStatus::Deleted);
        $this->makeConversation($client, $this->makeProducer(), '-10 days');
        $this->em->flush();

        $this->executeReminderCommand();

        self::assertSame([], $this->reminders($client));
    }

    // * Un interrupteur coupé dans Paramètres : rien n'est créé, et le compte rendu de la commande ne compte pas ce rappel.
    public function testDoesNotRemindWhoSwitchedTheReminderOffAndDoesNotCountIt(): void
    {
        $client = $this->makeUser('client');
        $preference = new UserPreference();
        $preference->setIdUser($client);
        $preference->setLocale('fr');
        $preference->setNotificationSettings(['types' => ['review_reminder' => false]]);
        $client->setPreference($preference);
        $this->em->persist($preference);
        $this->makeConversation($client, $this->makeProducer(), '-10 days');
        $this->em->flush();

        $tester = $this->executeReminderCommand();

        self::assertSame([], $this->reminders($client));
        self::assertStringContainsString('0 rappel', $tester->getDisplay());
    }
}
