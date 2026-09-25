<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\TicketCrudController;
use App\Entity\Identity\User;
use App\Entity\Support\SupportReplyTemplate;
use App\Entity\Support\Ticket;
use App\Entity\Support\TicketAttachment;
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

    private function detailUrl(Ticket $ticket): string
    {
        return self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(TicketCrudController::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($ticket->getId())
            ->generateUrl();
    }

    /**
     * @return array{0: Ticket, 1: TicketMessage}
     */
    private function makeTicketWithMessage(User $author, string $content = 'Bonjour, besoin d\'aide.'): array
    {
        $ticket = new Ticket();
        $ticket->setIdUser($author);
        $ticket->setSubject('Question facturation');
        $ticket->setStatus('open');
        $ticket->setPriority('haute');
        $this->em->persist($ticket);

        $message = new TicketMessage();
        $message->setTicket($ticket);
        $message->setSender($author);
        $message->setContent($content);
        $this->em->persist($message);

        return [$ticket, $message];
    }

    // * Ticket::$idUser est le demandeur : tout autre auteur d'un message est l'équipe support -- le fil doit les
    // * distinguer, dans l'ordre chronologique.
    public function testDetailThreadDistinguishesRequesterFromSupportInOrder(): void
    {
        $admin = $this->loginAsAdmin();
        $author = $this->makeUser('requester');
        [$ticket] = $this->makeTicketWithMessage($author, 'Première question');
        $this->em->flush();

        $reply = new TicketMessage();
        $reply->setTicket($ticket);
        // * Les requêtes HTTP du client (connexion) détachent l'objet renvoyé par loginAsAdmin() : on le recharge.
        $reply->setSender($this->em->find(User::class, $admin->getId()));
        $reply->setContent('Voici la réponse du support');
        $this->em->persist($reply);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->detailUrl($ticket));

        self::assertResponseIsSuccessful();
        $messages = $crawler->filter('.tm-message');
        self::assertCount(2, $messages);
        self::assertStringContainsString('Première question', $messages->eq(0)->text());
        self::assertStringContainsString('Demandeur', $messages->eq(0)->text());
        self::assertStringContainsString('Voici la réponse du support', $messages->eq(1)->text());
        self::assertStringContainsString('Support', $messages->eq(1)->text());
        self::assertSelectorTextContains('.tm-ticket-summary', 'Haute');
    }

    public function testMessageContentIsEscaped(): void
    {
        $this->loginAsAdmin();
        [$ticket] = $this->makeTicketWithMessage($this->makeUser('xss'), '<script>alert(1)</script>');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->detailUrl($ticket));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.tm-message script'));
        self::assertStringContainsString('<script>alert(1)</script>', $crawler->filter('.tm-message-body')->text());
    }

    private function attachTo(TicketMessage $message, string $content, string $name): TicketAttachment
    {
        $attachment = new TicketAttachment();
        $attachment->setTicketMessage($message);
        $attachment->setFileName($name);
        $attachment->setMimeType('application/pdf');
        $attachment->setFileSize(strlen($content));
        $key = sprintf('%s/%s', $message->getTicket()->getId()->toRfc4122(), $attachment->getId()->toRfc4122());
        self::getContainer()->get('ticket_attachments.storage')->write($key, $content);
        $attachment->setFileUrl($key);
        $this->em->persist($attachment);

        return $attachment;
    }

    public function testAttachmentsAreListedAndDownloadableAsAttachment(): void
    {
        $this->loginAsAdmin();
        [$ticket, $message] = $this->makeTicketWithMessage($this->makeUser('withfile'));
        $first = $this->attachTo($message, 'contenu-pdf-1', 'facture.pdf');
        $second = $this->attachTo($message, 'contenu-pdf-2', 'justificatif.pdf');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->detailUrl($ticket));
        $links = $crawler->filter('.tm-message-attachments a');
        self::assertCount(2, $links);
        self::assertSame('facture.pdf', trim($links->eq(0)->text()));

        // * Un lien par fichier, chacun vers SON fichier (le générateur d'URL est partagé : une fuite d'état d'un
        // * lien à l'autre ferait pointer les deux sur la même pièce jointe).
        self::assertStringContainsString($first->getId()->toRfc4122(), $links->eq(0)->attr('href'));
        self::assertStringContainsString($second->getId()->toRfc4122(), $links->eq(1)->attr('href'));

        $this->client->request('GET', $links->eq(1)->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('justificatif.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame('contenu-pdf-2', $this->client->getInternalResponse()->getContent());
    }

    private function submitReply(Ticket $ticket, string $content): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', $this->detailUrl($ticket));
        $form = $crawler->filter('#ticket-reply-form')->form();
        $form['content'] = $content;

        return $this->client->submit($form);
    }

    /**
     * @return array{status: string, assigned_to_id: ?string, messages: int}
     */
    private function ticketState(Ticket $ticket): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status, assigned_to_id, (SELECT count(*) FROM support.ticket_messages WHERE ticket_id = t.id) AS messages
             FROM support.tickets t WHERE t.id = :id',
            ['id' => $ticket->getId()->toRfc4122()]
        );
        self::assertNotFalse($row);

        return ['status' => $row['status'], 'assigned_to_id' => $row['assigned_to_id'], 'messages' => (int) $row['messages']];
    }

    public function testReplyAddsAgentMessageTakesTicketAndMovesItInProgress(): void
    {
        $admin = $this->loginAsAdmin();
        [$ticket] = $this->makeTicketWithMessage($this->makeUser('asker'));
        $this->em->flush();

        $crawler = $this->submitReply($ticket, "Bonjour,\nnous corrigeons cela.");

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', 'Réponse envoyée');
        $messages = $crawler->filter('.tm-message');
        self::assertCount(2, $messages);
        self::assertStringContainsString('nous corrigeons cela', $messages->eq(1)->text());
        self::assertStringContainsString('Support', $messages->eq(1)->text());

        $state = $this->ticketState($ticket);
        self::assertSame('in_progress', $state['status']);
        self::assertSame($admin->getId()->toRfc4122(), $state['assigned_to_id']);

        $audit = $this->em->getConnection()->fetchAssociative(
            "SELECT new_data FROM audit.audit_logs WHERE action = 'ticket_replied' AND record_id = :id",
            ['id' => $ticket->getId()->toRfc4122()]
        );
        self::assertNotFalse($audit);
        self::assertStringNotContainsString('nous corrigeons cela', $audit['new_data']);
    }

    public function testReplyKeepsExistingAssigneeAndResolvedStatus(): void
    {
        $this->loginAsAdmin();
        $other = $this->makeUser('otheragent');
        [$ticket] = $this->makeTicketWithMessage($this->makeUser('asker2'));
        $ticket->setAssignedTo($other);
        $ticket->setStatus('resolved');
        $this->em->flush();

        $this->submitReply($ticket, 'Un dernier point.');

        $state = $this->ticketState($ticket);
        self::assertSame('resolved', $state['status']);
        self::assertSame($other->getId()->toRfc4122(), $state['assigned_to_id']);
        self::assertSame(2, $state['messages']);
    }

    public function testClosedTicketHasNoReplyFormAndRejectsAReply(): void
    {
        $this->loginAsAdmin();
        [$ticket] = $this->makeTicketWithMessage($this->makeUser('asker3'));
        $this->em->flush();
        $crawler = $this->client->request('GET', $this->detailUrl($ticket));
        $replyUrl = $crawler->filter('#ticket-reply-form')->form()->getUri();
        $token = $crawler->filter('#ticket-reply-form input[name=_csrf_token]')->attr('value');

        $ticket = $this->em->find(Ticket::class, $ticket->getId());
        $ticket->setStatus('closed');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->detailUrl($ticket));
        self::assertCount(0, $crawler->filter('#ticket-reply-form'));

        $this->client->request('POST', $replyUrl, ['content' => 'Trop tard', '_csrf_token' => $token]);
        self::assertSelectorTextContains('.alert', 'fermé');
        self::assertSame(1, $this->ticketState($ticket)['messages']);
    }

    public function testEmptyReplyIsRejected(): void
    {
        $this->loginAsAdmin();
        [$ticket] = $this->makeTicketWithMessage($this->makeUser('asker4'));
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->detailUrl($ticket));
        $form = $crawler->filter('#ticket-reply-form')->form();
        $this->client->request('POST', $form->getUri(), ['content' => "   \n ", '_csrf_token' => $crawler->filter('#ticket-reply-form input[name=_csrf_token]')->attr('value')]);

        self::assertSelectorTextContains('.alert', 'vide');
        $state = $this->ticketState($ticket);
        self::assertSame(1, $state['messages']);
        self::assertSame('open', $state['status']);
    }

    public function testReplyWithInvalidCsrfTokenIsForbidden(): void
    {
        $this->loginAsAdmin();
        [$ticket] = $this->makeTicketWithMessage($this->makeUser('asker5'));
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->detailUrl($ticket));
        $this->client->request('POST', $crawler->filter('#ticket-reply-form')->form()->getUri(), ['content' => 'Pirate', '_csrf_token' => 'faux']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->ticketState($ticket)['messages']);
    }

    // * RBAC (cahier fonctionnel 22.2) : répondre aux tickets est précisément le périmètre du rôle Support.
    public function testSupportRoleCanReply(): void
    {
        $support = $this->makeUserWithPassword('support', 'motdepasse123');
        $support->setRoles([User::ROLE_SUPPORT]);
        [$ticket] = $this->makeTicketWithMessage($this->makeUser('asker6'));
        $this->em->flush();
        $this->client->followRedirects(true);
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Se connecter', ['_username' => $support->getEmail(), '_password' => 'motdepasse123']);

        $this->submitReply($ticket, 'Réponse du support seul.');

        self::assertSelectorTextContains('.alert', 'Réponse envoyée');
        self::assertSame(2, $this->ticketState($ticket)['messages']);
    }

    private function makeReplyTemplate(string $title, string $content, int $position, bool $active = true): void
    {
        $template = new SupportReplyTemplate();
        $template->setTitle($title);
        $template->setContent($content);
        $template->setPosition($position);
        $template->setIsActive($active);
        $this->em->persist($template);
    }

    // * Seuls les modèles actifs, dans l'ordre d'affichage voulu par l'équipe ; le contenu passe par un attribut
    // * data-* (échappé par Twig, retours à la ligne conservés) et non par une requête au clic.
    public function testReplyTemplatesAreOfferedActiveOnlyInPositionOrder(): void
    {
        $this->loginAsAdmin();
        [$ticket] = $this->makeTicketWithMessage($this->makeUser('tpl'));
        $this->makeReplyTemplate('Second', 'Texte "B"', 20);
        $this->makeReplyTemplate('Inactif', 'Ne doit pas apparaître', 5, false);
        $this->makeReplyTemplate('Premier', "Ligne 1\nLigne 2 <b>gras</b>", 10);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->detailUrl($ticket));

        $options = $crawler->filter('#ticket-reply-template option[data-content]');
        self::assertCount(2, $options);
        self::assertSame('Premier', trim($options->eq(0)->text()));
        self::assertSame("Ligne 1\nLigne 2 <b>gras</b>", $options->eq(0)->attr('data-content'));
        self::assertSame('Second', trim($options->eq(1)->text()));
        self::assertSame('Texte "B"', $options->eq(1)->attr('data-content'));
        self::assertStringNotContainsString('Ne doit pas apparaître', (string) $this->client->getResponse()->getContent());
    }

    public function testNoTemplateSelectorWhenThereAreNoActiveTemplates(): void
    {
        $this->loginAsAdmin();
        [$ticket] = $this->makeTicketWithMessage($this->makeUser('notpl'));
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->detailUrl($ticket));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#ticket-reply-template'));
        self::assertCount(1, $crawler->filter('#ticket-reply-content'));
    }

    public function testAttachmentOfAnotherTicketIsNotServedThroughThisOne(): void
    {
        $this->loginAsAdmin();
        [$ticketA] = $this->makeTicketWithMessage($this->makeUser('a'));
        [, $messageB] = $this->makeTicketWithMessage($this->makeUser('b'));
        $foreign = $this->attachTo($messageB, 'secret-de-b', 'b.pdf');
        $this->em->flush();

        $url = self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(TicketCrudController::class)
            ->setAction('downloadAttachment')
            ->setEntityId($ticketA->getId())
            ->set('attachmentId', $foreign->getId()->toRfc4122())
            ->generateUrl();
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(404);
    }
}
