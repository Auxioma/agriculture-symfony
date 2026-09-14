<?php

namespace App\Tests\Functional\Controller\Content;

use App\Entity\Content\LegalPage;
use App\Tests\ApiTestCase;

/**
 * Teste GET /api/legal et GET /api/legal/{code} (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf,
 * "Front office public" et "Contraintes légales et RGPD") -- routes publiques, pas de token requis.
 */
final class LegalControllerTest extends ApiTestCase
{
    private function makeLegalPage(string $code, string $locale, bool $isActive, string $title = 'Titre'): LegalPage
    {
        $page = new LegalPage();
        $page->setCode($code);
        $page->setLocale($locale);
        $page->setTitle($title);
        $page->setContent('Contenu de '.$code);
        $page->setIsActive($isActive);
        if ($isActive) {
            $page->setPublishedAt(new \DateTimeImmutable());
        }
        $this->em->persist($page);

        return $page;
    }

    public function testListLegalPagesReturnsOnlyActiveOnesForLocale(): void
    {
        $this->makeLegalPage('cgu', 'fr', true, 'Conditions générales');
        $this->makeLegalPage('cgu', 'en', true, 'Terms of use');
        $this->makeLegalPage('mentions-legales', 'fr', false, 'Ancienne version');
        $this->em->flush();

        // *Aucun header Authorization : cette route doit être accessible sans authentification.
        $this->client->request('GET', '/api/legal');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $codes = array_column($data, 'code');
        self::assertContains('cgu', $codes);
        self::assertNotContains('mentions-legales', $codes, 'Version inactive, ne doit pas apparaître.');

        $this->client->request('GET', '/api/legal?locale=en');
        $dataEn = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $dataEn);
        self::assertSame('Terms of use', $dataEn[0]['title']);
    }

    public function testGetLegalPageReturnsContent(): void
    {
        $this->makeLegalPage('cgu', 'fr', true, 'Conditions générales');
        $this->em->flush();

        $this->client->request('GET', '/api/legal/cgu');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Contenu de cgu', $data['content']);
    }

    public function testGetLegalPageReturns404ForUnknownCode(): void
    {
        $this->client->request('GET', '/api/legal/inexistant');

        self::assertResponseStatusCodeSame(404);
    }

    public function testGetLegalPageReturns404ForInactiveVersion(): void
    {
        $this->makeLegalPage('mentions-legales', 'fr', false);
        $this->em->flush();

        $this->client->request('GET', '/api/legal/mentions-legales');

        self::assertResponseStatusCodeSame(404);
    }
}
