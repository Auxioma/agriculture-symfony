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
            'SELECT status, assigned_to_id, closed_at FROM support.tickets WHERE id = :id',
            ['id' => $ticket->getId()->toRfc4122()]
        );
        self::assertNotFalse($row);
        self::assertSame('resolved', $row['status']);
        self::assertSame($agent->getId()->toRfc4122(), $row['assigned_to_id']);
        // * Le formulaire "Modifier" doit aussi renseigner closedAt (TicketCrudController::updateEntity()).
        self::assertNotNull($row['closed_at']);
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

    private function submitReply(Ticket $ticket, string $content, ?string $filePath = null): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', $this->detailUrl($ticket));
        $form = $crawler->filter('#ticket-reply-form')->form();
        $form['content'] = $content;
        if (null !== $filePath) {
            $field = $form['attachment'];
            self::assertInstanceOf(\Symfony\Component\DomCrawler\Field\FileFormField::class, $field);
            $field->upload($filePath);
        }

        return $this->client->submit($form);
    }

    /**
     * Fichier temporaire commençant par la signature "%PDF-" : le type est lu sur le contenu (pas sur l'extension
     * ni sur ce que déclare le navigateur), il faut donc un vrai début de PDF pour être accepté comme tel.
     */
    private function tempFile(string $name, string $content, int $padTo = 0): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.bin2hex(random_bytes(4)).'-'.$name;
        file_put_contents($path, $content.str_repeat(' ', max(0, $padTo - strlen($content))));
        register_shutdown_function(static fn () => @unlink($path));

        return $path;
    }

    /**
     * @return array{file_name: string, mime_type: string, file_size: int, file_url: string, content: ?string}|null
     */
    private function attachmentOf(Ticket $ticket): ?array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT a.file_name, a.mime_type, a.file_size, a.file_url, m.content
             FROM support.ticket_attachments a JOIN support.ticket_messages m ON m.id = a.ticket_message_id
             WHERE m.ticket_id = :id',
            ['id' => $ticket->getId()->toRfc4122()]
        );

        return false === $row ? null : $row;
    }

    public function testAgentReplyWithAnAttachmentStoresItAndItDownloads(): void
    {
        $this->loginAsAdmin();
        [$ticket] = $this->makeTicketWithMessage($this->makeUser('withattach'));
        $this->em->flush();
        $pdf = $this->tempFile('devis.pdf', "%PDF-1.4\n% devis de test\n");

        $crawler = $this->submitReply($ticket, 'Voici le devis demandé.', $pdf);

        self::assertSelectorTextContains('.alert', 'Réponse envoyée');
        $attachment = $this->attachmentOf($ticket);
        self::assertNotNull($attachment);
        self::assertStringEndsWith('devis.pdf', $attachment['file_name']);
        self::assertSame('application/pdf', $attachment['mime_type']);
        self::assertSame(filesize($pdf), (int) $attachment['file_size']);
        self::assertSame('Voici le devis demandé.', $attachment['content']);
        self::assertTrue(self::getContainer()->get('ticket_attachments.storage')->fileExists($attachment['file_url']));

        $link = $crawler->filter('.tm-message-attachments a');
        self::assertCount(1, $link);
        $this->client->request('GET', $link->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('%PDF-1.4', $this->client->getInternalResponse()->getContent());

        $audit = $this->em->getConnection()->fetchOne(
            "SELECT new_data FROM audit.audit_logs WHERE action = 'ticket_replied' AND record_id = :id",
            ['id' => $ticket->getId()->toRfc4122()]
        );
        self::assertStringContainsString('"attachment_id"', $audit);
    }

    public function testAgentReplyMayCarryOnlyAFile(): void
    {
        $this->loginAsAdmin();
        [$ticket] = $this->makeTicketWithMessage($this->makeUser('fileonly'));
        $this->em->flush();

        $this->submitReply($ticket, '', $this->tempFile('photo.pdf', "%PDF-1.4\n"));

        self::assertSelectorTextContains('.alert', 'Réponse envoyée');
        $attachment = $this->attachmentOf($ticket);
        self::assertNotNull($attachment);
        self::assertNull($attachment['content']);
        self::assertSame(2, $this->ticketState($ticket)['messages']);
    }

    public function testAgentAttachmentWithUnsupportedTypeOrTooLargeIsRejected(): void
    {
        $this->loginAsAdmin();
        [$ticket] = $this->makeTicketWithMessage($this->makeUser('badfile'));
        $this->em->flush();

        $this->submitReply($ticket, 'Texte OK', $this->tempFile('notes.txt', 'juste du texte'));
        self::assertSelectorTextContains('.alert-danger', 'Format non supporté');

        $this->submitReply($ticket, 'Texte OK', $this->tempFile('gros.pdf', "%PDF-1.4\n", 10 * 1024 * 1024 + 1));
        self::assertSelectorTextContains('.alert-danger', 'trop volumineux');

        self::assertNull($this->attachmentOf($ticket));
        self::assertSame(1, $this->ticketState($ticket)['messages']);
        self::assertSame('open', $this->ticketState($ticket)['status']);
    }

    /**
     * @return array{status: string, priority: ?string, assigned_to_id: ?string, closed_at: ?string, messages: int}
     */
    private function ticketState(Ticket $ticket): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status, priority, assigned_to_id, closed_at, (SELECT count(*) FROM support.ticket_messages WHERE ticket_id = t.id) AS messages
             FROM support.tickets t WHERE t.id = :id',
            ['id' => $ticket->getId()->toRfc4122()]
        );
        self::assertNotFalse($row);

        return [
            'status' => $row['status'],
            'priority' => $row['priority'],
            'assigned_to_id' => $row['assigned_to_id'],
            'closed_at' => $row['closed_at'],
            'messages' => (int) $row['messages'],
        ];
    }

    /**
     * Poste une action rapide avec le vrai jeton CSRF et la vraie URL lus dans le premier formulaire d'action de la page.
     *
     * @param array<string, string> $params
     */
    private function postQuickAction(Ticket $ticket, array $params): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', $this->detailUrl($ticket));
        $form = $crawler->filter('input[name=operation]')->first()->closest('form');
        self::assertNotNull($form);

        return $this->client->request('POST', $form->form()->getUri(), $params + [
            '_csrf_token' => $form->filter('input[name=_csrf_token]')->attr('value'),
        ]);
    }

    private function makeOpenTicket(string $prefix, string $status = 'open', string $priority = 'moyenne'): Ticket
    {
        [$ticket] = $this->makeTicketWithMessage($this->makeUser($prefix));
        $ticket->setStatus($status);
        $ticket->setPriority($priority);

        return $ticket;
    }

    public function testResolveButtonMarksTicketResolvedStampsClosedAtAndIsAudited(): void
    {
        $this->loginAsAdmin();
        $ticket = $this->makeOpenTicket('resolve', 'in_progress');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->detailUrl($ticket));
        self::assertCount(0, $crawler->selectButton('Rouvrir le ticket'));
        $crawler = $this->client->submit($crawler->selectButton('Marquer comme résolu')->form());

        self::assertSelectorTextContains('.alert', 'résolu');
        $state = $this->ticketState($ticket);
        self::assertSame('resolved', $state['status']);
        self::assertNotNull($state['closed_at']);
        self::assertCount(0, $crawler->selectButton('Marquer comme résolu'));
        self::assertCount(1, $crawler->selectButton('Rouvrir le ticket'));

        $audit = $this->em->getConnection()->fetchAssociative(
            "SELECT old_data, new_data FROM audit.audit_logs WHERE action = 'ticket_status_changed' AND record_id = :id",
            ['id' => $ticket->getId()->toRfc4122()]
        );
        self::assertNotFalse($audit);
        self::assertStringContainsString('in_progress', $audit['old_data']);
        self::assertStringContainsString('resolved', $audit['new_data']);
    }

    public function testReopenGoesInProgressWhenAssignedAndOpenOtherwiseAndClearsClosedAt(): void
    {
        $this->loginAsAdmin();
        $assigned = $this->makeOpenTicket('reopen1', 'resolved');
        $assigned->setAssignedTo($this->makeUser('owner'));
        $assigned->setClosedAt(new \DateTimeImmutable('-1 day'));
        $unassigned = $this->makeOpenTicket('reopen2', 'closed');
        $unassigned->setClosedAt(new \DateTimeImmutable('-1 day'));
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->detailUrl($assigned));
        $this->client->submit($crawler->selectButton('Rouvrir le ticket')->form());
        $crawler = $this->client->request('GET', $this->detailUrl($unassigned));
        $this->client->submit($crawler->selectButton('Rouvrir le ticket')->form());

        $a = $this->ticketState($assigned);
        $u = $this->ticketState($unassigned);
        self::assertSame('in_progress', $a['status']);
        self::assertNull($a['closed_at']);
        self::assertSame('open', $u['status']);
        self::assertNull($u['closed_at']);
    }

    public function testAssignToMeTakesOverAndDisappearsOnceMine(): void
    {
        $admin = $this->loginAsAdmin();
        $ticket = $this->makeOpenTicket('assign');
        $ticket->setAssignedTo($this->makeUser('previous'));
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->detailUrl($ticket));
        $crawler = $this->client->submit($crawler->selectButton('Me l\'assigner')->form());

        self::assertSelectorTextContains('.alert', 'assigné à vous');
        self::assertSame($admin->getId()->toRfc4122(), $this->ticketState($ticket)['assigned_to_id']);
        self::assertCount(0, $crawler->selectButton('Me l\'assigner'));
        self::assertNotFalse($this->em->getConnection()->fetchAssociative(
            "SELECT 1 FROM audit.audit_logs WHERE action = 'ticket_assigned' AND record_id = :id",
            ['id' => $ticket->getId()->toRfc4122()]
        ));
    }

    public function testPriorityCanBeChangedFromTheDetailPage(): void
    {
        $this->loginAsAdmin();
        $ticket = $this->makeOpenTicket('prio', 'open', 'basse');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->detailUrl($ticket));
        $form = $crawler->selectButton('Appliquer')->form();
        $form['priority'] = 'critique';
        $this->client->submit($form);

        self::assertSelectorTextContains('.alert', 'Priorité mise à jour');
        self::assertSame('critique', $this->ticketState($ticket)['priority']);
    }

    // * Un ticket fermé est définitif (l'API n'y accepte plus de message) : seule "Rouvrir" est proposée, et un POST
    // * direct sur les autres opérations est refusé côté serveur, sans rien modifier.
    public function testClosedTicketOffersOnlyReopenAndRejectsOtherOperations(): void
    {
        $this->loginAsAdmin();
        $ticket = $this->makeOpenTicket('closed', 'closed', 'basse');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->detailUrl($ticket));
        self::assertCount(1, $crawler->selectButton('Rouvrir le ticket'));
        self::assertCount(0, $crawler->selectButton('Marquer comme résolu'));
        self::assertCount(0, $crawler->selectButton('Me l\'assigner'));
        self::assertCount(0, $crawler->selectButton('Appliquer'));

        foreach ([['operation' => 'resolve'], ['operation' => 'assign_me'], ['operation' => 'priority', 'priority' => 'critique']] as $params) {
            $this->postQuickAction($ticket, $params);
            self::assertSelectorExists('.alert-danger');
        }
        $state = $this->ticketState($ticket);
        self::assertSame('closed', $state['status']);
        self::assertSame('basse', $state['priority']);
        self::assertNull($state['assigned_to_id']);
    }

    public function testResolvingAnAlreadyResolvedTicketIsRejected(): void
    {
        $this->loginAsAdmin();
        $ticket = $this->makeOpenTicket('twice', 'resolved');
        $this->em->flush();

        $this->postQuickAction($ticket, ['operation' => 'resolve']);

        self::assertSelectorExists('.alert-danger');
        self::assertSame('resolved', $this->ticketState($ticket)['status']);
    }

    public function testUnknownPriorityIsRejectedAndUnknownOperationIs400(): void
    {
        $this->loginAsAdmin();
        $ticket = $this->makeOpenTicket('bad', 'open', 'moyenne');
        $this->em->flush();

        $this->postQuickAction($ticket, ['operation' => 'priority', 'priority' => 'urgentissime']);
        self::assertSelectorExists('.alert-danger');
        self::assertSame('moyenne', $this->ticketState($ticket)['priority']);

        $this->postQuickAction($ticket, ['operation' => 'supprimer-tout']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testQuickActionWithInvalidCsrfTokenIsForbidden(): void
    {
        $this->loginAsAdmin();
        $ticket = $this->makeOpenTicket('csrf');
        $this->em->flush();

        $this->postQuickAction($ticket, ['operation' => 'resolve', '_csrf_token' => 'faux']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('open', $this->ticketState($ticket)['status']);
    }

    public function testSupportRoleCanUseQuickActions(): void
    {
        $support = $this->makeUserWithPassword('supportqa', 'motdepasse123');
        $support->setRoles([User::ROLE_SUPPORT]);
        $ticket = $this->makeOpenTicket('qa');
        $this->em->flush();
        $this->client->followRedirects(true);
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Se connecter', ['_username' => $support->getEmail(), '_password' => 'motdepasse123']);

        $crawler = $this->client->request('GET', $this->detailUrl($ticket));
        $this->client->submit($crawler->selectButton('Marquer comme résolu')->form());

        self::assertSame('resolved', $this->ticketState($ticket)['status']);
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

    // * Redirections non suivies ici : assertEmailCount()/getMailerMessage() ne voient que la dernière requête, et la
    // * page détail chargée après la redirection n'envoie évidemment aucun email.
    public function testReplyNotifiesTheRequesterInAppAndByEmailWithoutLeakingTheReplyText(): void
    {
        $this->loginAsAdmin();
        $requester = $this->makeUser('notified');
        [$ticket] = $this->makeTicketWithMessage($requester);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->detailUrl($ticket));
        $form = $crawler->filter('#ticket-reply-form')->form();
        $form['content'] = 'Information confidentielle : votre IBAN se termine par 1234.';
        $this->client->followRedirects(false);
        $this->client->submit($form);

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'to', $requester->getEmail());
        self::assertEmailTextBodyContains($email, 'Question facturation');
        self::assertEmailTextBodyNotContains($email, 'IBAN');

        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT title, data FROM notification.notifications WHERE user_id = :id AND type = 'support_reply'",
            ['id' => $requester->getId()->toRfc4122()]
        );
        self::assertNotFalse($row);
        self::assertStringContainsString($ticket->getId()->toRfc4122(), $row['data']);
        self::assertStringNotContainsString('IBAN', $row['data'].$row['title']);
    }

    public function testNoNotificationWhenTheRequesterIsTheAgentOrDeletedOrTheReplyIsRejected(): void
    {
        $admin = $this->loginAsAdmin();
        $deleted = $this->makeUser('gone');
        $deleted->setStatus(\App\Enum\UserStatus::Deleted);
        [$deletedTicket] = $this->makeTicketWithMessage($deleted);
        [$ownTicket] = $this->makeTicketWithMessage($this->em->find(User::class, $admin->getId()));
        [$emptyTicket] = $this->makeTicketWithMessage($this->makeUser('emptyreply'));
        $this->em->flush();

        $this->submitReply($deletedTicket, 'Réponse à un compte supprimé.');
        $this->submitReply($ownTicket, 'Note sur mon propre ticket.');
        $this->submitReply($emptyTicket, '   ');

        $count = $this->em->getConnection()->fetchOne("SELECT count(*) FROM notification.notifications WHERE type = 'support_reply'");
        self::assertSame(0, (int) $count);
        self::assertSame(2, $this->ticketState($deletedTicket)['messages']);
        self::assertSame(2, $this->ticketState($ownTicket)['messages']);
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

    // * Les noms de fichiers des utilisateurs français portent des accents ("relevé"), des espaces, des "%" : la
    // * livraison ne doit jamais planter (HeaderUtils::makeDisposition refuse un nom non ASCII sans repli).
    public function testAttachmentWithAccentedOrOddFileNameStillDownloads(): void
    {
        $this->loginAsAdmin();
        [$ticket, $message] = $this->makeTicketWithMessage($this->makeUser('accents'));
        $this->attachTo($message, 'contenu-accentué', 'relevé bancaire 100%.pdf');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->detailUrl($ticket));
        $this->client->request('GET', $crawler->filter('.tm-message-attachments a')->first()->attr('href'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString("filename*=utf-8''relev%C3%A9%20bancaire", (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame('contenu-accentué', $this->client->getInternalResponse()->getContent());
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
