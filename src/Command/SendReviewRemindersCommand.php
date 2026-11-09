<?php

namespace App\Command;

use App\Entity\Messaging\Conversation;
use App\Entity\Trust\Review;
use App\Enum\ConversationStatus;
use App\Enum\UserStatus;
use App\Service\Notification\NotificationService;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cahier fonctionnel -- notification client « Avis à laisser » (confiance : « Avis après conversation, accord trouvé ou achat déclaré »).
 * Prévue pour tourner une fois par jour, avec les autres rappels (.github/workflows/reminders.yml).
 *
 * Un échange est considéré terminé quand sa dernière activité remonte à plus de 7 jours : le client a eu le temps de conclure avec le
 * producteur. On ne remonte pas au-delà de 30 jours, sinon le premier passage rappellerait tout l'historique. La règle est celle de
 * POST /api/reviews (ReviewController) : un avis suppose une conversation entre ce client, ce producteur et cette demande ; pas de rappel
 * à qui en a déjà laissé un, ni pour une conversation signalée ou archivée.
 *
 * Idempotente par construction, comme SendExpiryRemindersCommand : avant d'envoyer, on vérifie qu'aucune notification du même type n'existe
 * déjà pour cette conversation (relue dans Notification.data) -- le client n'est rappelé qu'une fois par échange.
 *
 * ! DATETIMETZ_IMMUTABLE explicite sur les bornes (voir SendExpiryRemindersCommand) : sans lui Doctrine infère un type sans fuseau et la
 * ! comparaison avec les colonnes TIMESTAMPTZ est fausse de plusieurs heures.
 */
#[AsCommand(name: 'app:send-review-reminders', description: 'Rappelle aux clients de laisser un avis après un échange avec un producteur')]
final class SendReviewRemindersCommand extends Command
{
    public const TYPE = 'review_reminder';
    // * dernière activité de la conversation : plus de 7 jours, moins de 30
    private const QUIET_FOR = '-7 days';
    private const NOT_OLDER_THAN = '-30 days';
    // * un passage ne traite pas plus de conversations : le reste attend le lendemain (la fenêtre de 30 jours laisse le temps)
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notificationService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = $this->remindClients();
        $this->em->flush();

        $io->success(\sprintf('%d rappel(s) d\'avis à laisser.', $count));

        return Command::SUCCESS;
    }

    private function remindClients(): int
    {
        $to = new \DateTimeImmutable(self::QUIET_FOR);
        $from = new \DateTimeImmutable(self::NOT_OLDER_THAN);

        $conversations = $this->em->createQueryBuilder()
            ->select('c')->from(Conversation::class, 'c')
            ->join('c.client', 'client')
            ->where('COALESCE(c.lastMessageAt, c.createdAt) BETWEEN :from AND :to')
            ->andWhere('c.status IN (:statuses)')
            ->andWhere('client.status <> :deleted')
            ->andWhere('NOT EXISTS (SELECT rv.id FROM '.Review::class.' rv WHERE rv.client = c.client AND rv.request = c.request AND rv.producer = c.producer)')
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->setParameter('from', $from, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('to', $to, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('statuses', [ConversationStatus::Open->value, ConversationStatus::Closed->value])
            ->setParameter('deleted', UserStatus::Deleted->value)
            ->setMaxResults(self::BATCH_SIZE)
            ->getQuery()->getResult();

        $count = 0;
        foreach ($conversations as $conversation) {
            $client = $conversation->getClient();
            $conversationId = $conversation->getId()->toRfc4122();
            if ($this->alreadyNotified($client->getId()->toRfc4122(), ['conversationId' => $conversationId])) {
                continue;
            }

            $producer = $conversation->getProducer();
            $sent = $this->notificationService->notify(
                $client, self::TYPE, 'Un avis à laisser',
                \sprintf('Comment s\'est passé votre échange avec %s ? Votre avis aide les autres clients.', $producer->getFarmName()),
                [
                    'requestId' => $conversation->getRequest()->getId()->toRfc4122(),
                    'conversationId' => $conversationId,
                    'producerId' => $producer->getId()->toRfc4122(),
                    'producerName' => $producer->getFarmName(),
                ]
            );
            // * null : le client a coupé ce type dans Paramètres, rien n'est parti
            if (null !== $sent) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param array<string, string> $match clés de Notification.data qui doivent toutes valoir ces valeurs
     */
    private function alreadyNotified(string $userId, array $match): bool
    {
        $sql = 'SELECT 1 FROM notification.notifications WHERE user_id = :userId AND type = :type';
        $params = ['userId' => $userId, 'type' => self::TYPE];
        $i = 0;
        foreach ($match as $key => $value) {
            $sql .= " AND data->>:key$i = :value$i";
            $params["key$i"] = $key;
            $params["value$i"] = $value;
            ++$i;
        }

        return false !== $this->em->getConnection()->fetchOne($sql.' LIMIT 1', $params);
    }
}
