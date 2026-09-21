<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\LabelCrudController;
use App\Entity\Identity\User;
use App\Entity\Producer\ProducerLabel;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Teste l'écran "Labels" du back-office (maquette "Labels et certifications") : liste avec type, producteurs
 * associés et statut, création, désactivation (retrait de la liste publique) et édition des traductions.
 */
final class LabelCrudControllerTest extends ApiTestCase
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

    private function url(string $action, ?object $label = null): string
    {
        $generator = self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(LabelCrudController::class)
            ->setAction($action);
        if (null !== $label) {
            $generator->setEntityId($label->getId());
        }

        return $generator->generateUrl();
    }

    public function testIndexShowsTypeProducerCountAndStatus(): void
    {
        $this->loginAsAdmin();
        $bio = $this->makeLabel('bio', 'Bio');
        $bio->setRequiresDocument(true);
        $local = $this->makeLabel('local', 'Local');
        $local->setRequiresDocument(false);
        $local->setIsActive(false);

        $producer = $this->makeProducerProfile($this->makeUser('producer'), $this->makeCountry());
        $producerLabel = new ProducerLabel();
        $producerLabel->setProducer($producer);
        $producerLabel->setLabel($bio);
        $this->em->persist($producerLabel);
        $this->em->flush();

        $this->client->request('GET', $this->url(Action::INDEX));

        self::assertResponseIsSuccessful();
        $text = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Labels et certifications', $text);
        self::assertStringContainsString('Certification', $text);
        self::assertStringContainsString('Pratique', $text);
        self::assertStringContainsString('1 producteur', $text);
        self::assertStringContainsString('0 producteur', $text);
        self::assertStringContainsString('Actif', $text);
        self::assertStringContainsString('Inactif', $text);
    }

    public function testCreatingLabelSucceeds(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', $this->url(Action::NEW));
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[method="post"]')->last()->form();
        $values = $form->getPhpValues();
        $rootKey = array_key_first($values);
        $values[$rootKey]['name'] = 'HVE';
        $values[$rootKey]['code'] = 'hve';
        $values[$rootKey]['description'] = 'Haute valeur environnementale.';
        $values[$rootKey]['requiresDocument'] = '1';
        $values[$rootKey]['isActive'] = '1';

        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT name, requires_document, is_active FROM catalog.labels WHERE code = 'hve'"
        );
        self::assertNotFalse($row);
        self::assertSame('HVE', $row['name']);
        self::assertTrue((bool) $row['requires_document']);
        self::assertTrue((bool) $row['is_active']);
    }

    public function testDeactivatingLabelRemovesItFromPublicList(): void
    {
        $this->loginAsAdmin();
        $label = $this->makeLabel('aoc', 'AOP/AOC');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->url(Action::EDIT, $label));
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form#edit-Label-form')->form();
        $values = $form->getPhpValues();
        $rootKey = array_key_first($values);
        unset($values[$rootKey]['isActive']);

        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/labels');
        $codes = array_column(json_decode($this->client->getResponse()->getContent(), true), 'code');
        self::assertNotContains('aoc', $codes);
    }

    public function testDeleteIsOnlyOfferedForUnusedLabels(): void
    {
        $this->loginAsAdmin();
        $used = $this->makeLabel('bio', 'Bio');
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $this->makeCountry());
        $producerLabel = new ProducerLabel();
        $producerLabel->setProducer($producer);
        $producerLabel->setLabel($used);
        $this->em->persist($producerLabel);
        $this->em->flush();

        $this->client->request('GET', $this->url(Action::DETAIL, $used));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('action-delete', $this->client->getResponse()->getContent());

        $unused = $this->makeLabel('local', 'Local');
        $this->em->flush();

        $this->client->request('GET', $this->url(Action::DETAIL, $unused));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('action-delete', $this->client->getResponse()->getContent());
    }

    public function testEditingTranslationsSucceeds(): void
    {
        $this->loginAsAdmin();
        $label = $this->makeLabel('bio', 'Bio');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->url('editTranslations', $label));
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[method="post"]')->form();
        $values = $form->getPhpValues();
        $rootKey = array_key_first($values);
        $values[$rootKey]['en']['name'] = 'Organic';
        $values[$rootKey]['en']['description'] = 'Product from organic farming.';

        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT name, description FROM catalog.label_translations WHERE label_id = :id AND locale = 'en'",
            ['id' => $label->getId()->toRfc4122()]
        );
        self::assertNotFalse($row);
        self::assertSame('Organic', $row['name']);
        self::assertSame('Product from organic farming.', $row['description']);
    }
}
