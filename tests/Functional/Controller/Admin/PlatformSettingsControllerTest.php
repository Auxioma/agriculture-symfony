<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\Catalog\Currency;
use App\Entity\Content\LegalPage;
use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste l'écran "Paramètres de la plateforme" du back-office : valeurs par défaut sans rien en base,
 * enregistrement, validation, journalisation, et effet de la langue par défaut sur les routes publiques.
 */
final class PlatformSettingsControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private function loginAsAdmin(): User
    {
        $admin = $this->makeUserWithPassword('admin', 'motdepasse123');
        $admin->setRoles([User::ROLE_ADMIN]);

        $currency = new Currency();
        $currency->setCode('EUR');
        $currency->setName('Euro');
        $currency->setSymbol('€');
        $currency->setIsActive(true);
        $this->em->persist($currency);
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
     * @param array<string, string|int> $overrides
     */
    private function submitSettings(array $overrides): void
    {
        $crawler = $this->client->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[method="post"]')->last()->form();
        $values = $form->getPhpValues();
        $rootKey = array_key_first($values);
        foreach ($overrides as $name => $value) {
            $values[$rootKey][$name] = $value;
        }

        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
    }

    public function testFormShowsDefaultsWhenNothingIsStored(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/admin/settings');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Paramètres de la plateforme', $this->client->getResponse()->getContent());
        self::assertSame('TrouveMoi Agri', $crawler->filter('input[name$="[platform_name]"]')->attr('value'));
        self::assertSame('support@trouvemoi.com', $crawler->filter('input[name$="[support_email]"]')->attr('value'));
        self::assertSame('60', $crawler->filter('input[name$="[session_minutes]"]')->attr('value'));
    }

    public function testSavingPersistsValuesAndLogsTheChange(): void
    {
        $this->loginAsAdmin();

        $this->submitSettings([
            'platform_name' => 'Agri Direct',
            'support_email' => 'aide@agri-direct.test',
            'default_locale' => 'en',
            'session_minutes' => '90',
        ]);
        self::assertResponseIsSuccessful();

        $rows = $this->em->getConnection()->fetchAllKeyValue('SELECT setting_key, value FROM content.platform_settings');
        self::assertSame('Agri Direct', $rows['platform_name']);
        self::assertSame('aide@agri-direct.test', $rows['support_email']);
        self::assertSame('en', $rows['default_locale']);
        self::assertSame('EUR', $rows['default_currency']);
        self::assertSame('90', $rows['session_minutes']);

        $logged = $this->em->getConnection()->fetchOne("SELECT count(*) FROM audit.audit_logs WHERE action = 'platform_settings_updated'");
        self::assertSame(1, (int) $logged);
    }

    public function testInvalidValuesAreRejectedAndNothingIsStored(): void
    {
        $this->loginAsAdmin();

        $this->submitSettings(['support_email' => 'pas-un-email', 'session_minutes' => '2']);

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM content.platform_settings'));
    }

    public function testDefaultLocaleAppliesToPublicRoutesWithoutLocaleParameter(): void
    {
        $this->loginAsAdmin();
        foreach (['fr' => 'Mentions légales', 'en' => 'Legal notice'] as $locale => $title) {
            $page = new LegalPage();
            $page->setCode('mentions');
            $page->setLocale($locale);
            $page->setTitle($title);
            $page->setContent('Contenu');
            $page->setIsActive(true);
            $this->em->persist($page);
        }
        $this->em->flush();

        $this->client->request('GET', '/api/legal');
        self::assertSame(['Mentions légales'], array_column(json_decode($this->client->getResponse()->getContent(), true), 'title'));

        $this->submitSettings(['default_locale' => 'en']);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/legal');
        self::assertSame(['Legal notice'], array_column(json_decode($this->client->getResponse()->getContent(), true), 'title'));
    }
}
