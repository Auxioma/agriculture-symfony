<?php

/**
 * Tickets support et signalements de démo -- alimentent les compteurs "tickets non résolus" et
 * "signalements ouverts" de DashboardController::index(). Statuts/priorités repris tels quels des listes
 * autorisées par TicketCrudController::STATUSES/PRIORITIES (Ticket::$status/$priority sont de simples
 * chaînes, pas des enums -- voir le docblock de Ticket).
 */

namespace App\DataFixtures;

use App\Entity\Identity\User;
use App\Entity\Messaging\Conversation;
use App\Entity\Support\Ticket;
use App\Entity\Trust\Report;
use App\Enum\ReportStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;

class SupportFixtures extends Fixture implements DependentFixtureInterface
{
    private const TICKET_STATUSES = ['open', 'in_progress', 'resolved', 'closed'];
    private const TICKET_PRIORITIES = ['basse', 'moyenne', 'haute', 'critique'];

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        for ($i = 0; $i < UserFixtures::CLIENT_COUNT; ++$i) {
            $client = $this->getReference(UserFixtures::CLIENT_REFERENCE_PREFIX.$i, User::class);

            $ticket = new Ticket();
            $ticket->setIdUser($client);
            $ticket->setSubject($faker->sentence(6));
            $ticket->setStatus(self::TICKET_STATUSES[$i % \count(self::TICKET_STATUSES)]);
            $ticket->setPriority(self::TICKET_PRIORITIES[$i % \count(self::TICKET_PRIORITIES)]);
            $manager->persist($ticket);
        }

        // * Un signalement sur les conversations marquées Reported par ConversationFixtures (une sur cinq).
        for ($i = 0; $i < UserFixtures::CLIENT_COUNT; $i += 5) {
            $reporter = $this->getReference(UserFixtures::CLIENT_REFERENCE_PREFIX.$i, User::class);
            $conversation = $this->getReference(ConversationFixtures::CONVERSATION_REFERENCE_PREFIX.$i, Conversation::class);

            $report = new Report();
            $report->setReporter($reporter);
            $report->setTargetType('conversation');
            $report->setTargetId($conversation->getId());
            $report->setReason('contenu_inapproprie');
            $report->setMessage($faker->sentence(12));
            $report->setStatus(ReportStatus::Open);
            $manager->persist($report);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [UserFixtures::class, ConversationFixtures::class];
    }
}
