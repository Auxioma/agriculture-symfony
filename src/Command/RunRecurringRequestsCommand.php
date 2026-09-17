<?php

namespace App\Command;

use App\Entity\Matching\RecurringRequestRule;
use App\Entity\Matching\RequestMatch;
use App\Service\Notification\NotificationService;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cahier fonctionnel -- "demande récurrente" (NeedType::Recurring) : republie automatiquement, pour chaque
 * RecurringRequestRule active dont $nextRunAt est échu, la ClientRequest associée (ClientRequest::duplicate()),
 * en rejouant exactement le pipeline de ClientRequestController::createRequest() (expiresAt +30 jours,
 * matching.populate_request_matches(), notifications client + producteurs matchés). Prévue pour tourner
 * une fois par jour via une tâche planifiée (.github/workflows/reminders.yml), sur le même principe que
 * SendExpiryRemindersCommand -- pas de symfony/scheduler, une Command classique déclenchée par cron suffit.
 *
 * Une règle dont $endAt est dépassé est désactivée ($isActive = false) sans republier, plutôt que supprimée :
 * l'historique de la règle (fréquence, échéance choisie) reste consultable par le client via GET .../recurring-rule.
 */

#[AsCommand(name: 'app:run-recurring-requests', description: 'Republie automatiquement les demandes récurrentes échues')]
final class RunRecurringRequestsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notificationService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        // ! setParameter() sans type explicite infère Types::DATETIME_IMMUTABLE (sans fuseau horaire) pour un
        // ! \DateTimeImmutable -- Postgres interprète alors la valeur naïve avec le fuseau horaire de la
        // ! session au lieu de la comparer telle quelle à la colonne TIMESTAMPTZ, faussant la comparaison
        // ! de plusieurs heures. DATETIMETZ_IMMUTABLE (même type que la colonne rr.nextRunAt) est nécessaire.
        $dueRules = $this->em->createQueryBuilder()
            ->select('rr')->from(RecurringRequestRule::class, 'rr')
            ->where('rr.isActive = true')
            ->andWhere('rr.nextRunAt <= :now')
            ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)
            ->getQuery()->getResult();

        $republished = 0;
        $deactivated = 0;

        foreach ($dueRules as $rule) {
            if ($rule->getEndAt() !== null && $rule->getEndAt() < $now) {
                $rule->setIsActive(false);
                ++$deactivated;
                continue;
            }

            $this->republish($rule);
            $rule->setNextRunAt($rule->getFrequency()->nextRunAfter($now));
            ++$republished;
        }

        $this->em->flush();

        $io->success(sprintf('%d demande(s) récurrente(s) republiée(s), %d règle(s) désactivée(s) (échéance dépassée).', $republished, $deactivated));

        return Command::SUCCESS;
    }

    // * Flush intermédiaire nécessaire : populate_request_matches() lit matching.client_requests directement
    // * en SQL, la ligne doit donc déjà être en base (même contrainte que ClientRequestController::createRequest()).
    private function republish(RecurringRequestRule $rule): void
    {
        $duplicate = $rule->getRequest()->duplicate();
        $duplicate->setExpiresAt(new \DateTimeImmutable('+30 days'));

        $this->em->persist($duplicate);
        $this->em->flush();

        $this->em->getConnection()->executeStatement(
            'SELECT matching.populate_request_matches(:id)',
            ['id' => $duplicate->getId()->toRfc4122()]
        );

        $this->notificationService->notify(
            $duplicate->getClient(),
            'request_sent',
            'Demande envoyée',
            'Votre demande récurrente a été republiée automatiquement.'
        );

        $matches = $this->em->getRepository(RequestMatch::class)->findBy(['request' => $duplicate]);
        foreach ($matches as $match) {
            $this->notificationService->notify(
                $match->getProducer()->getOwner(),
                'new_relevant_request',
                'Nouvelle demande pertinente',
                'Une nouvelle demande correspond à votre profil.'
            );
        }
    }
}
