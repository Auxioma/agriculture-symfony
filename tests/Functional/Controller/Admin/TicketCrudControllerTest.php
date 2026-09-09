<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\TicketCrudController;
use App\Entity\Identity\User;
use App\Entity\Support\Ticket;
use App\Entity\Support\TicketMessage;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Teste le module "Support" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf :
 * "Tickets, priorités, assignation, modèles de réponse, pièces jointes"). "Modèles de réponse" volontairement
 * exclu : voir le docblock de TicketCrudController.
 */
final class TicketCrudControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private function loginAsAdmin(): User
    {
        $admin = $this->makeUserWithPassword('admin', 'motdepasse123');
        $admin->setRoles([User::ROLE_ADMIN]);
        $this->em->flush();

        $this->client->followRedirects(true);
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Se connecter', [
            '_username' => $admin->getEmail(),
            '_password' => 'motdepasse123',
        ]);

        return $admin;
    }

    public function testCreatingTicketOnBehalfOfAUserSucceeds(): void
    {
        $this->loginAsAdmin();
        $author = $this->makeUser('caller');
        $this->em->flush();

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(TicketCrudController::class)
            ->setAction(Action::NEW)
            ->generateUrl());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[method="post"]')->last()->form();
        $values = $form->getPhpValues();
        $rootKey = array_key_first($values);
        $values[$rootKey]['idUser'] = $author->getId()->toRfc4122();
        $values[$rootKey]['subject'] = 'Colis endommagé à la livraison';
        $values[$rootKey]['status'] = 'open';
        $values[$rootKey]['priority'] = 'haute';

        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT status, priority FROM support.tickets WHERE subject = 'Colis endommagé à la livraison'"
        );
        self::assertNotFalse($row);
        self::assertSame('open', $row['status']);
        self::assertSame('haute', $row['priority']);
    }

    public function testAssigningAndClosingTicketSucceeds(): void
    {
        $this->loginAsAdmin();
        $author = $this->makeUser('caller2');
        $agent = $this->makeUser('agent');

        $ticket = new Ticket();
        $ticket->setIdUser($author);
        $ticket->setSubject('Question sur une commande');
        $ticket->setStatus('open');
        $ticket->setPriority('basse');
        $this->em->persist($ticket);
        $this->em->flush();

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(TicketCrudController::class)
            ->setAction(Action::EDIT)
            ->setEntityId($ticket->getId())
            ->generateUrl());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form#edit-Ticket-form')->form();
        $values = $form->getPhpValues();
        $rootKey = array_key_first($values);
        $values[$rootKey]['status'] = 'resolved';
        $values[$rootKey]['assignedTo'] = $agent->getId()->toRfc4122();

        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status, assigned_to_id FROM support.tickets WHERE id = :id',
            ['id' => $ticket->getId()->toRfc4122()]
        );
        self::assertNotFalse($row);
        self::assertSame('resolved', $row['status']);
        self::assertSame($agent->getId()->toRfc4122(), $row['assigned_to_id']);
    }

    public function testTicketDetailShowsItsMessages(): void
    {
        $this->loginAsAdmin();
        $author = $this->makeUser('caller3');

        $ticket = new Ticket();
        $ticket->setIdUser($author);
        $ticket->setSubject('Problème de paiement');
        $ticket->setStatus('open');
        $ticket->setPriority('critique');
        $this->em->persist($ticket);

        $message = new TicketMessage();
        $message->setTicket($ticket);
        $message->setSender($author);
        $message->setContent('Mon paiement a été prélevé deux fois.');
        $this->em->persist($message);
        $this->em->flush();

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(TicketCrudController::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($ticket->getId())
            ->generateUrl());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Problème de paiement', (string) $crawler->filter('body')->text());
    }
}
