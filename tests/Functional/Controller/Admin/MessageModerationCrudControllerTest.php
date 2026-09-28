<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\ConversationCrudController;
use App\Controller\Admin\MessageCrudController;
use App\Entity\Catalog\Country;
use App\Entity\Identity\User;
use App\Entity\Messaging\Conversation;
use App\Entity\Messaging\Message;
use App\Entity\Trust\Report;
use App\Enum\ConversationStatus;
use App\Enum\ReportStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * Teste le module "Messages" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf :
 * "Consultation limitée aux cas signalés, masquage, blocage, trace de modération").
 */
final class MessageModerationCrudControllerTest extends ApiTestCase
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

    /**
     * $country facultatif : makeCountry() prend une clé primaire fixe ('FR' par défaut), un deuxième appel dans le
     * même test ferait entrer en collision deux objets PHP pour la même ligne (EntityIdentityCollisionException) --
     * un appelant qui a besoin de plusieurs conversations réutilise donc le même Country.
     *
     * @return array{0: Conversation, 1: Message, 2: User, 3: Report} [conversation signalée, son message, l'expéditeur, le signalement]
     */
    private function makeReportedConversationWithMessage(?Country $country = null): array
    {
        $country ??= $this->makeCountry();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $client = $this->makeUser('client');
        $producerOwner = $this->makeUser('producer');
        $producer = $this->makeProducerProfile($producerOwner, $country);
        $request = $this->makeClientRequest($client, $product);

        $conversation = new Conversation();
        $conversation->setRequest($request);
        $conversation->setClient($client);
        $conversation->setProducer($producer);
        $conversation->setStatus(ConversationStatus::Reported);
        $this->em->persist($conversation);

        $message = new Message();
        $message->setConversation($conversation);
        $message->setSender($client);
        $message->setContent('Contenu signalé à modérer');
        $this->em->persist($message);

        // * Reproduit exactement ce que POST /api/conversations/{id}/report crée réellement (targetType/
        // * targetId polymorphes, pas de FK directe) -- sinon recordModerationAction() ne retrouverait rien.
        $report = new Report();
        $report->setReporter($producerOwner);
        $report->setTargetType('conversation');
        $report->setTargetId($conversation->getId());
        $report->setReason('spam');
        $this->em->persist($report);

        $this->em->flush();

        return [$conversation, $message, $client, $report];
    }

    private function reportStatus(Report $report): string
    {
        $status = $this->em->getConnection()->fetchOne('SELECT status FROM trust.reports WHERE id = :id', ['id' => $report->getId()->toRfc4122()]);
        self::assertNotFalse($status);

        return $status;
    }

    private function conversationStatus(Conversation $conversation): string
    {
        $status = $this->em->getConnection()->fetchOne('SELECT status FROM messaging.conversations WHERE id = :id', ['id' => $conversation->getId()->toRfc4122()]);
        self::assertNotFalse($status);

        return $status;
    }

    public function testIndexOnlyShowsReportedConversations(): void
    {
        $this->loginAsAdmin();
        [, , $reportedClient] = $this->makeReportedConversationWithMessage();

        // * Réutilise le pays déjà persisté par makeReportedConversationWithMessage() -- un second
        // * makeCountry('FR') créerait un second objet PHP pour la même ligne "FR" et ferait planter
        // * l'identity map de Doctrine (EntityIdentityCollisionException) au flush.
        $country = $this->em->getRepository(\App\Entity\Catalog\Country::class)->find('FR');
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $openConversationClient = $this->makeUser('client3');
        $openConversation = new Conversation();
        $openConversation->setRequest($this->makeClientRequest($this->makeUser('client2'), $product));
        $openConversation->setClient($openConversationClient);
        $openConversation->setProducer($this->makeProducerProfile($this->makeUser('producer2'), $country));
        $openConversation->setStatus(ConversationStatus::Open);
        $this->em->persist($openConversation);
        $this->em->flush();

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(ConversationCrudController::class)
            ->setAction(Action::INDEX)
            ->generateUrl());

        self::assertResponseIsSuccessful();
        // * L'email du client est ce que formatValue() affiche réellement pour la colonne "client" -- plus
        // * fiable qu'un fragment d'UUID (EasyAdmin tronque les identifiants affichés, y compris en entier
        // * l'UUID n'apparaît jamais tel quel dans la page).
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($reportedClient->getEmail(), $content);
        self::assertStringNotContainsString($openConversationClient->getEmail(), $content);
    }

    // * Cahier DevOps : "journaliser les actions admin sensibles ... lecture d'une conversation signalée" --
    // * seule la consultation DETAIL doit être tracée (pas l'INDEX, qui ne fait que lister).
    public function testViewingReportedConversationDetailRecordsAuditLog(): void
    {
        $this->loginAsAdmin();
        [$conversation] = $this->makeReportedConversationWithMessage();

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(ConversationCrudController::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($conversation->getId())
            ->generateUrl());

        self::assertResponseIsSuccessful();

        $audit = $this->em->getConnection()->fetchAssociative(
            "SELECT record_id FROM audit.audit_logs WHERE action = 'reported_conversation_viewed' AND table_name = 'conversations'"
        );
        self::assertNotFalse($audit);
        self::assertSame($conversation->getId()->toRfc4122(), $audit['record_id']);
    }

    // * ConversationCrudController::detail() vérifie explicitement le statut : défense en profondeur contre
    // * l'accès direct par URL à une conversation non signalée, même si l'index ne la liste déjà pas.
    public function testDirectAccessToNonReportedConversationIsForbidden(): void
    {
        $this->loginAsAdmin();
        $country = $this->makeCountry();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $openConversation = new Conversation();
        $openConversation->setRequest($this->makeClientRequest($this->makeUser('client'), $product));
        $openConversation->setClient($this->makeUser('client4'));
        $openConversation->setProducer($this->makeProducerProfile($this->makeUser('producer3'), $country));
        $openConversation->setStatus(ConversationStatus::Open);
        $this->em->persist($openConversation);
        $this->em->flush();

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(ConversationCrudController::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($openConversation->getId())
            ->generateUrl());

        self::assertResponseStatusCodeSame(403);
    }

    public function testClosingReportedConversationRecordsAuditLog(): void
    {
        $this->loginAsAdmin();
        [$conversation] = $this->makeReportedConversationWithMessage();

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(ConversationCrudController::class)
            ->setAction('closeConversation')
            ->setEntityId($conversation->getId())
            ->generateUrl());

        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status FROM messaging.conversations WHERE id = :id',
            ['id' => $conversation->getId()->toRfc4122()]
        );
        self::assertSame('closed', $row['status']);

        $audit = $this->em->getConnection()->fetchAssociative(
            "SELECT record_id FROM audit.audit_logs WHERE action = 'conversation_closed' AND table_name = 'conversations'"
        );
        self::assertNotFalse($audit);
        self::assertSame($conversation->getId()->toRfc4122(), $audit['record_id']);
    }

    public function testHideMessageRedactsContentFromApiAndRecordsModerationAction(): void
    {
        $admin = $this->loginAsAdmin();
        [$conversation, $message, $client] = $this->makeReportedConversationWithMessage();

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(MessageCrudController::class)
            ->setAction('hideMessage')
            ->setEntityId($message->getId())
            ->generateUrl());

        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT moderated_at FROM messaging.messages WHERE id = :id',
            ['id' => $message->getId()->toRfc4122()]
        );
        self::assertNotNull($row['moderated_at']);

        $action = $this->em->getConnection()->fetchAssociative(
            "SELECT action_type, admin_id FROM trust.moderation_actions WHERE action_type = 'hide_message'"
        );
        self::assertNotFalse($action);
        self::assertSame($admin->getId()->toRfc4122(), $action['admin_id']);

        $audit = $this->em->getConnection()->fetchAssociative(
            "SELECT record_id FROM audit.audit_logs WHERE action = 'message_hidden' AND table_name = 'messages'"
        );
        self::assertNotFalse($audit);
        self::assertSame($message->getId()->toRfc4122(), $audit['record_id']);

        // * Preuve bout-en-bout que le masquage cache vraiment le contenu côté API, pas seulement en base --
        // * ConversationController::getConversation() a été patché pour ça dans ce même round.
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($client);
        $this->client->request('GET', '/api/conversations/'.$conversation->getId()->toRfc4122(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNull($data['messages'][0]['content']);
        self::assertTrue($data['messages'][0]['moderated']);
    }

    public function testBlockSenderSuspendsUserAndRecordsModerationAction(): void
    {
        $admin = $this->loginAsAdmin();
        [, $message, $client] = $this->makeReportedConversationWithMessage();

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(MessageCrudController::class)
            ->setAction('blockSender')
            ->setEntityId($message->getId())
            ->generateUrl());

        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status FROM identity.users WHERE id = :id',
            ['id' => $client->getId()->toRfc4122()]
        );
        self::assertSame('suspended', $row['status']);

        $action = $this->em->getConnection()->fetchAssociative(
            "SELECT action_type, admin_id FROM trust.moderation_actions WHERE action_type = 'block_user'"
        );
        self::assertNotFalse($action);
        self::assertSame($admin->getId()->toRfc4122(), $action['admin_id']);

        $audit = $this->em->getConnection()->fetchAssociative(
            "SELECT record_id FROM audit.audit_logs WHERE action = 'user_blocked' AND table_name = 'users'"
        );
        self::assertNotFalse($audit);
        self::assertSame($client->getId()->toRfc4122(), $audit['record_id']);
    }

    public function testIndexShowsTheReportStatusBadge(): void
    {
        $this->loginAsAdmin();
        [, , , $report] = $this->makeReportedConversationWithMessage();

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(MessageCrudController::class)
            ->setAction(Action::INDEX)
            ->generateUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table tbody tr', 'Ouvert');
        self::assertSame(ReportStatus::Open->value, $this->reportStatus($report));
    }

    // * Une action de modération (masquer/bloquer) prouve qu'un admin s'occupe du signalement : il doit passer
    // * d'Ouvert à "En cours" tout seul, sans bouton dédié.
    public function testHidingAMessageMovesTheReportFromOpenToInReview(): void
    {
        $this->loginAsAdmin();
        [, $message, , $report] = $this->makeReportedConversationWithMessage();

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(MessageCrudController::class)
            ->setAction('hideMessage')
            ->setEntityId($message->getId())
            ->generateUrl());

        self::assertSame(ReportStatus::InReview->value, $this->reportStatus($report));
    }

    public function testResolvingAReportClosesTheConversationAndRecordsAudit(): void
    {
        $admin = $this->loginAsAdmin();
        [$conversation, $message, , $report] = $this->makeReportedConversationWithMessage();

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(MessageCrudController::class)
            ->setAction('resolveReport')
            ->setEntityId($message->getId())
            ->generateUrl());

        self::assertResponseIsSuccessful();
        self::assertSame(ReportStatus::Resolved->value, $this->reportStatus($report));
        self::assertSame('closed', $this->conversationStatus($conversation));

        $row = $this->em->getConnection()->fetchAssociative('SELECT reviewed_by_id FROM trust.reports WHERE id = :id', ['id' => $report->getId()->toRfc4122()]);
        self::assertSame($admin->getId()->toRfc4122(), $row['reviewed_by_id']);

        $audit = $this->em->getConnection()->fetchAssociative(
            "SELECT old_data, new_data FROM audit.audit_logs WHERE action = 'report_resolved' AND record_id = :id",
            ['id' => $report->getId()->toRfc4122()]
        );
        self::assertNotFalse($audit);
        self::assertStringContainsString('open', $audit['old_data']);
        self::assertStringContainsString('resolved', $audit['new_data']);
    }

    public function testRejectingAReportClosesTheConversationAndRecordsAudit(): void
    {
        $this->loginAsAdmin();
        [$conversation, $message, , $report] = $this->makeReportedConversationWithMessage();

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(MessageCrudController::class)
            ->setAction('rejectReport')
            ->setEntityId($message->getId())
            ->generateUrl());

        self::assertResponseIsSuccessful();
        self::assertSame(ReportStatus::Rejected->value, $this->reportStatus($report));
        self::assertSame('closed', $this->conversationStatus($conversation));
        self::assertNotFalse($this->em->getConnection()->fetchAssociative(
            "SELECT 1 FROM audit.audit_logs WHERE action = 'report_rejected' AND record_id = :id",
            ['id' => $report->getId()->toRfc4122()]
        ));
    }

    // * Une fois tranché, le message quitte entièrement la file de modération : "Résoudre"/"Rejeter" n'ont plus de
    // * sens sur un signalement déjà clos (pas de re-décision), et resolveReport() clôt aussi la conversation, qui
    // * ne correspond donc plus au filtre `c.status = Reported` de createIndexQueryBuilder() -- même mécanisme que
    // * pour les Demandes (RequestQualityAnalyzer) : le traitement vide la file de lui-même.
    public function testDecisionButtonsDisappearAndMessageLeavesTheQueueOnceResolved(): void
    {
        $this->loginAsAdmin();
        [, $message] = $this->makeReportedConversationWithMessage();
        $detailUrl = self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(MessageCrudController::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($message->getId())
            ->generateUrl();

        $beforeCrawler = $this->client->request('GET', $detailUrl);
        self::assertCount(1, $beforeCrawler->selectLink('Résoudre'));
        self::assertCount(1, $beforeCrawler->selectLink('Rejeter'));

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(MessageCrudController::class)
            ->setAction('resolveReport')
            ->setEntityId($message->getId())
            ->generateUrl());

        $indexCrawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(MessageCrudController::class)
            ->setAction(Action::INDEX)
            ->generateUrl());
        self::assertCount(0, $indexCrawler->filter('table tbody tr[data-id]'));

        // * assertMessageIsReported() protège aussi l'accès direct par URL -- même défense en profondeur que
        // * testDirectAccessToNonReportedConversationIsForbidden().
        $this->client->request('GET', $detailUrl);
        self::assertResponseStatusCodeSame(403);
    }

    public function testReportChipsFilterTheListByStatus(): void
    {
        $this->loginAsAdmin();
        $country = $this->makeCountry();
        $this->makeReportedConversationWithMessage($country);
        [, $inReviewMessage] = $this->makeReportedConversationWithMessage($country);
        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(MessageCrudController::class)
            ->setAction('hideMessage')
            ->setEntityId($inReviewMessage->getId())
            ->generateUrl());

        $indexUrl = self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(MessageCrudController::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        $openOnly = $this->client->request('GET', $indexUrl.'?filters[reportStatus]=open');
        self::assertCount(1, $openOnly->filter('table tbody tr[data-id]'));
        self::assertStringContainsString('Ouvert', $openOnly->filter('table tbody tr')->text());

        $inReviewOnly = $this->client->request('GET', $indexUrl.'?filters[reportStatus]=in_review');
        self::assertCount(1, $inReviewOnly->filter('table tbody tr[data-id]'));
        self::assertStringContainsString('En cours', $inReviewOnly->filter('table tbody tr')->text());
    }

    // * Cas limite théorique (le filtre createIndexQueryBuilder() ne devrait jamais l'exposer en pratique) :
    // * une conversation "Reported" sans Report associé -- decideReport() doit échouer proprement, pas planter.
    public function testResolvingWithoutAMatchingReportIs404(): void
    {
        $this->loginAsAdmin();
        $country = $this->makeCountry();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $client = $this->makeUser('orphanclient');
        $producer = $this->makeProducerProfile($this->makeUser('orphanproducer'), $country);
        $conversation = new Conversation();
        $conversation->setRequest($this->makeClientRequest($client, $product));
        $conversation->setClient($client);
        $conversation->setProducer($producer);
        $conversation->setStatus(ConversationStatus::Reported);
        $this->em->persist($conversation);
        $message = new Message();
        $message->setConversation($conversation);
        $message->setSender($client);
        $message->setContent('Signalée sans Report en base');
        $this->em->persist($message);
        $this->em->flush();

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(MessageCrudController::class)
            ->setAction('resolveReport')
            ->setEntityId($message->getId())
            ->generateUrl());

        self::assertResponseStatusCodeSame(404);
    }
}
