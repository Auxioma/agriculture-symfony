<?php

namespace App\Command;

use App\Entity\Billing\Subscription;
use App\Entity\Identity\User;
use App\Entity\Matching\ClientRequest;
use App\Entity\Matching\ProducerReply;
use App\Enum\RequestStatus;
use App\Enum\SubscriptionStatus;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cahier fonctionnel -- notifications "Demande expirant bientôt" (client + producteur engagé) et
 * "Abonnement bientôt renouvelé" (producteur). Prévue pour tourner une fois par jour via une tâche
 * planifiée (.github/workflows/reminders.yml) -- pas de symfony/scheduler : seulement une dépendance
 * transitive, jamais installée comme package direct, une Command classique déclenchée par cron reste
 * plus simple et n'ajoute aucune dépendance.
 *
 * Idempotente par construction : avant d'envoyer un rappel, on vérifie qu'aucune notification du même
 * type pour le même enregistrement n'existe déjà, en relisant Notification.data (JSON) -- évite d'ajouter
 * un champ "reminderSentAt" sur ClientRequest/Subscription juste pour ça.
 */

#[AsCommand(name: 'app:send-expiry-reminders', description: "Envoie les rappels d'expiration (demandes, abonnements)")]
final class SendExpiryRemindersCommand extends Command
{
    private const REQUEST_REMINDER_WINDOW = '+2 days';
    private const SUBSCRIPTION_REMINDER_WINDOW = '+7 days';
    private const ACTIVE_REQUEST_STATUSES = [
        RequestStatus::Sent, RequestStatus::WaitingReplies, RequestStatus::RepliesReceived, RequestStatus::ConversationOpen,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notificationService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $requestReminders = $this->remindExpiringRequests();
        $subscriptionReminders = $this->remindRenewingSubscriptions();
        $this->em->flush();

        $io->success(sprintf(
            "%d rappel(s) de demande expirant bientôt, %d rappel(s) d'abonnement bientôt renouvelé.",
            $requestReminders, $subscriptionReminders
        ));

        return Command::SUCCESS;
    }

    private function remindExpiringRequests(): int
    {
        $now = new \DateTimeImmutable();
        $threshold = new \DateTimeImmutable(self::REQUEST_REMINDER_WINDOW);

        $requests = $this->em->createQueryBuilder()
            ->select('r')->from(ClientRequest::class, 'r')
            ->where('r.expiresAt IS NOT NULL')
            ->andWhere('r.expiresAt BETWEEN :now AND :threshold')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('now', $now)
            ->setParameter('threshold', $threshold)
            ->setParameter('statuses', self::ACTIVE_REQUEST_STATUSES)
            ->getQuery()->getResult();

        $count = 0;
        foreach ($requests as $request) {
            $requestId = $request->getId()->toRfc4122();

            if (!$this->alreadyNotified($request->getClient(), 'request_expiring_soon', 'requestId', $requestId)) {
                $this->notificationService->notify(
                    $request->getClient(), 'request_expiring_soon', 'Demande expirant bientôt',
                    'Votre demande arrive bientôt à expiration.', ['requestId' => $requestId]
                );
                ++$count;
            }

            // * Le producteur n'est concerné que s'il s'est déjà engagé (une réponse envoyée) -- sinon la
            // * demande ne lui dit encore rien.
            $notifiedProducers = [];
            foreach ($this->em->getRepository(ProducerReply::class)->findBy(['request' => $request]) as $reply) {
                $producer = $reply->getProducer();
                $producerId = $producer->getId()->toRfc4122();
                if (isset($notifiedProducers[$producerId])) {
                    continue;
                }
                $notifiedProducers[$producerId] = true;

                if (!$this->alreadyNotified($producer->getOwner(), 'request_expiring_soon', 'requestId', $requestId)) {
                    $this->notificationService->notify(
                        $producer->getOwner(), 'request_expiring_soon', 'Demande expirant bientôt',
                        'Une demande à laquelle vous avez répondu arrive bientôt à expiration.', ['requestId' => $requestId]
                    );
                    ++$count;
                }
            }
        }

        return $count;
    }

    private function remindRenewingSubscriptions(): int
    {
        $now = new \DateTimeImmutable();
        $threshold = new \DateTimeImmutable(self::SUBSCRIPTION_REMINDER_WINDOW);

        $subscriptions = $this->em->createQueryBuilder()
            ->select('s')->from(Subscription::class, 's')
            ->where('s.status = :active')
            ->andWhere('s.cancelAtPeriodEnd = false')
            ->andWhere('s.currentPeriodEnd BETWEEN :now AND :threshold')
            ->setParameter('active', SubscriptionStatus::Active)
            ->setParameter('now', $now)
            ->setParameter('threshold', $threshold)
            ->getQuery()->getResult();

        $count = 0;
        foreach ($subscriptions as $subscription) {
            $owner = $subscription->getProducer()->getOwner();
            $subscriptionId = $subscription->getId()->toRfc4122();

            if (!$this->alreadyNotified($owner, 'subscription_renewing_soon', 'subscriptionId', $subscriptionId)) {
                $this->notificationService->notify(
                    $owner, 'subscription_renewing_soon', 'Abonnement bientôt renouvelé',
                    'Votre abonnement sera renouvelé prochainement.', ['subscriptionId' => $subscriptionId]
                );
                ++$count;
            }
        }

        return $count;
    }

    private function alreadyNotified(User $recipient, string $type, string $dataKey, string $recordId): bool
    {
        $exists = $this->em->getConnection()->fetchOne(
            "SELECT 1 FROM notification.notifications WHERE user_id = :userId AND type = :type AND data->>:key = :recordId LIMIT 1",
            ['userId' => $recipient->getId()->toRfc4122(), 'type' => $type, 'key' => $dataKey, 'recordId' => $recordId]
        );

        return false !== $exists;
    }
}