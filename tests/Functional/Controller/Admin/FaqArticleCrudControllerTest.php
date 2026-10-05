<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\FaqArticleCrudController;
use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Teste le module "FAQ" du back-office : une question créée dans l'admin doit ressortir sur GET /api/faq.
 */
final class FaqArticleCrudControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private function loginAsAdmin(): void
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
    }

    public function testCreatedQuestionIsServedByThePublicApi(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(FaqArticleCrudController::class)
            ->setAction(Action::NEW)
            ->generateUrl());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[method="post"]')->last()->form();
        $values = $form->getPhpValues();
        $rootKey = array_key_first($values);

        $values[$rootKey]['question'] = 'Question créée depuis l\'admin ?';
        $values[$rootKey]['answer'] = 'Oui, elle apparaît sur le site.';
        $values[$rootKey]['category'] = 'Producteurs';
        $values[$rootKey]['locale'] = 'fr';
        $values[$rootKey]['position'] = '1';
        $values[$rootKey]['isActive'] = '1';

        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/faq');
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Producteurs', array_column($data, 'category', 'question')['Question créée depuis l\'admin ?'] ?? null);
    }
}
