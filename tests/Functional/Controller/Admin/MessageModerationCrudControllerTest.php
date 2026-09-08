<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\ConversationCrudController;
use App\Controller\Admin\MessageCrudController;
use App\Entity\Identity\User;
use App\Entity\Messaging\Conversation;
use App\Entity\Messaging\Message;
use App\Entity\Trust\Report;
use App\Enum\ConversationStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * Teste le module "Messages" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf §13 :
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
     * @return array{0: Conversation, 1: Message, 2: User} [conversation signalée, son message, l'expéditeur]
     */
    private function makeReportedConversationWithMessage(): array
    {
        $country = $this->makeCountry();
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

        return [$conversation, $message, $client];
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
    }
}
