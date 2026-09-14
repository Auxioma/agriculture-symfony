<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\LegalPageCrudController;
use App\Entity\Content\LegalPage;
use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Teste le module "Pages légales" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf), en
 * particulier la désactivation automatique de l'ancienne version lors de la publication d'une nouvelle
 * (LegalPageCrudController::deactivateOtherVersions()).
 */
final class LegalPageCrudControllerTest extends ApiTestCase
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

    public function testPublishingNewVersionDeactivatesThePreviousOne(): void
    {
        $this->loginAsAdmin();

        $previous = new LegalPage();
        $previous->setCode('cgu');
        $previous->setLocale('fr');
        $previous->setVersion(1);
        $previous->setTitle('Conditions générales v1');
        $previous->setContent('Ancien contenu');
        $previous->setIsActive(true);
        $previous->setPublishedAt(new \DateTimeImmutable('-30 days'));
        $this->em->persist($previous);
        $this->em->flush();

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(LegalPageCrudController::class)
            ->setAction(Action::NEW)
            ->generateUrl());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[method="post"]')->last()->form();
        $values = $form->getPhpValues();
        $rootKey = array_key_first($values);

        $values[$rootKey]['code'] = 'cgu';
        $values[$rootKey]['locale'] = 'fr';
        $values[$rootKey]['version'] = '2';
        $values[$rootKey]['title'] = 'Conditions générales v2';
        $values[$rootKey]['content'] = 'Nouveau contenu';
        $values[$rootKey]['isActive'] = '1';

        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseIsSuccessful();

        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT version, is_active FROM content.legal_pages WHERE code = 'cgu' AND locale = 'fr' ORDER BY version ASC"
        );
        self::assertCount(2, $rows);
        self::assertSame(1, (int) $rows[0]['version']);
        self::assertFalse((bool) $rows[0]['is_active'], 'La v1 doit avoir été désactivée par la publication de la v2.');
        self::assertSame(2, (int) $rows[1]['version']);
        self::assertTrue((bool) $rows[1]['is_active']);
    }
}
