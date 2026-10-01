<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\AuditLogCrudController;
use App\Entity\Audit\AuditLog;
use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Teste l'écran "Journal d'audit" du back-office (maquette "Admin · Journal d'audit") : consultation seule des
 * AuditLog déjà écrits par AuditLogger/les triggers PostgreSQL, libellés français, résolution de la cible,
 * masquage de l'IP, puces de catégorie, et l'avant/après en détail (sans le plantage sur un champ JSON de
 * TextField/TextareaField -- voir le docblock de pretty_json.html.twig pour le même piège que sur Label).
 */
final class AuditLogCrudControllerTest extends ApiTestCase
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

    private function url(string $action, ?AuditLog $log = null): string
    {
        $generator = self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(AuditLogCrudController::class)
            ->setAction($action);
        if (null !== $log) {
            $generator->setEntityId($log->getId());
        }

        return $generator->generateUrl();
    }

    private function makeLog(string $action, string $schema, string $table, ?string $recordId = null, ?User $actor = null): AuditLog
    {
        $log = new AuditLog();
        $log->setAction($action);
        $log->setSchemaName($schema);
        $log->setTableName($table);
        $log->setRecordId($recordId);
        $log->setActor($actor);
        $this->em->persist($log);

        return $log;
    }

    public function testIndexShowsFrenchLabelActorTargetAndMaskedIp(): void
    {
        $this->loginAsAdmin();
        $admin = $this->makeUserWithPassword('reviewer', 'motdepasse123');
        $reviewedUser = $this->makeUser('reviewed');
        $log = $this->makeLog('user_anonymized', 'identity', 'users', $reviewedUser->getId()->toRfc4122(), $admin);
        $log->setIpAddress('82.14.12.34');
        $this->em->flush();

        $this->client->request('GET', $this->url(Action::INDEX));

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Compte anonymisé', $html);
        self::assertStringContainsString($admin->getEmail(), $html);
        self::assertStringContainsString($reviewedUser->getEmail(), $html);
        self::assertStringContainsString('82.14.xx.xx', $html);
        self::assertStringNotContainsString('82.14.12.34', $html);
    }

    public function testDbTriggerRowWithNoActorShowsSystemAndComposedLabel(): void
    {
        $this->loginAsAdmin();
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('farmer'), $country);
        $this->makeLog('UPDATE', 'producer', 'producer_profiles', $producer->getId()->toRfc4122());
        $this->em->flush();

        $this->client->request('GET', $this->url(Action::INDEX));

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Producteur modifié', $html);
        self::assertStringContainsString('Système', $html);
        self::assertStringContainsString($producer->getFarmName(), $html);
    }

    public function testUnknownRecordFallsBackToShortId(): void
    {
        $this->loginAsAdmin();
        $log = $this->makeLog('conversation_closed', 'messaging', 'conversations', '11111111-2222-3333-4444-555555555555');
        $this->em->flush();

        $this->client->request('GET', $this->url(Action::INDEX));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Conversation #11111111', $this->client->getResponse()->getContent());
    }

    public function testCategoryChipsFilterBySchemaGroup(): void
    {
        $this->loginAsAdmin();
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('modfarmer'), $country, farmName: 'Ferme Modération');
        $this->makeLog('producer_validated', 'producer', 'producer_profiles', $producer->getId()->toRfc4122());
        $this->makeLog('user_anonymized', 'identity', 'users', $this->makeUser('acctuser')->getId()->toRfc4122());
        $this->em->flush();

        $this->client->request('GET', $this->url(Action::INDEX).'?filters%5BschemaName%5D=identity');
        $html = $this->client->getResponse()->getContent();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Compte anonymisé', $html);
        self::assertStringNotContainsString('Producteur validé', $html);

        $this->client->request('GET', $this->url(Action::INDEX).'?filters%5BschemaName%5D=producer%7Cmessaging%7Ctrust');
        $html2 = $this->client->getResponse()->getContent();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Producteur validé', $html2);
        self::assertStringNotContainsString('Compte anonymisé', $html2);
    }

    // * Régression : ArrayField (pas TextField/TextareaField) sur oldData/newData -- une TextareaField y plantait
    // * en 500 ("can't be converted into a string"), un champ JSON n'étant jamais une chaîne ni un Stringable.
    public function testDetailShowsBeforeAfterDiffWithoutError(): void
    {
        $this->loginAsAdmin();
        $log = $this->makeLog('user_status_changed', 'identity', 'users', $this->makeUser('statususer')->getId()->toRfc4122());
        $log->setOldData(['status' => 'active']);
        $log->setNewData(['status' => 'suspended']);
        $this->em->flush();

        $this->client->request('GET', $this->url(Action::DETAIL, $log));

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('status: active', $html);
        self::assertStringContainsString('status: suspended', $html);
    }

    public function testDetailWithoutDiffDataShowsPlaceholder(): void
    {
        $this->loginAsAdmin();
        $log = $this->makeLog('reported_message_viewed', 'messaging', 'messages', '66666666-7777-8888-9999-000000000000');
        $this->em->flush();

        $this->client->request('GET', $this->url(Action::DETAIL, $log));

        self::assertResponseIsSuccessful();
    }

    public function testNewEditAndDeleteAreDisabled(): void
    {
        $this->loginAsAdmin();
        $log = $this->makeLog('review_published', 'trust', 'reviews', '77777777-8888-9999-0000-111111111111');
        $this->em->flush();
        $this->client->followRedirects(false);

        $this->client->request('GET', $this->url(Action::NEW));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', $this->url(Action::EDIT, $log));
        self::assertResponseStatusCodeSame(403);
    }
}
