<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\SupportReplyTemplateCrudController;
use App\Entity\Identity\User;
use App\Entity\Support\SupportReplyTemplate;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Teste le module "Modèles de réponse" du back-office Support (cahier fonctionnel, "Support :
 * ... modèles de réponse ...") -- CRUD simple, distinct de QuickReply (producteur).
 */
final class SupportReplyTemplateCrudControllerTest extends ApiTestCase
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

    public function testCreateTemplateSucceeds(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(SupportReplyTemplateCrudController::class)
            ->setAction(Action::NEW)
            ->generateUrl());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[method="post"]')->last()->form();
        $values = $form->getPhpValues();
        $rootKey = array_key_first($values);

        $values[$rootKey]['title'] = 'Délai de livraison';
        $values[$rootKey]['content'] = 'Le délai habituel est de 3 à 5 jours ouvrés.';
        $values[$rootKey]['position'] = '1';
        $values[$rootKey]['isActive'] = '1';

        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT title, content, is_active FROM support.reply_templates WHERE title = 'Délai de livraison'"
        );
        self::assertNotFalse($row);
        self::assertSame('Le délai habituel est de 3 à 5 jours ouvrés.', $row['content']);
        self::assertTrue((bool) $row['is_active']);
    }

    public function testListIndexShowsExistingTemplate(): void
    {
        $this->loginAsAdmin();

        $template = new SupportReplyTemplate();
        $template->setTitle('Retard de réponse');
        $template->setContent('Nous revenons vers vous rapidement.');
        $template->setPosition(2);
        $this->em->persist($template);
        $this->em->flush();

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(SupportReplyTemplateCrudController::class)
            ->setAction(Action::INDEX)
            ->generateUrl());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Retard de réponse', $this->client->getResponse()->getContent());
    }
}
