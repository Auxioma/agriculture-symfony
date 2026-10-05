<?php

namespace App\Tests\Functional\Controller\Content;

use App\Entity\Content\FaqArticle;
use App\Tests\ApiTestCase;

/**
 * Teste GET /api/faq (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf, "FAQ rassurante" de
 * l'accueil) -- route publique, pas de token requis.
 */
final class FaqControllerTest extends ApiTestCase
{
    private function makeFaqArticle(string $locale, bool $isActive, string $question, ?int $position = null): FaqArticle
    {
        $article = new FaqArticle();
        $article->setLocale($locale);
        $article->setQuestion($question);
        $article->setAnswer('Réponse à '.$question);
        $article->setPosition($position);
        $article->setIsActive($isActive);
        $this->em->persist($article);

        return $article;
    }

    public function testListFaqArticlesReturnsOnlyActiveOnesForLocale(): void
    {
        $this->makeFaqArticle('fr', true, 'Question FR active', 1);
        $this->makeFaqArticle('en', true, 'Question EN active', 1);
        $this->makeFaqArticle('fr', false, 'Question FR inactive', 2);
        $this->em->flush();

        // * Aucun header Authorization : cette route doit être accessible sans authentification.
        $this->client->request('GET', '/api/faq');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $questions = array_column($data, 'question');
        self::assertContains('Question FR active', $questions);
        self::assertNotContains('Question FR inactive', $questions, 'Article inactif, ne doit pas apparaître.');
        self::assertNotContains('Question EN active', $questions, 'Autre locale, ne doit pas apparaître.');
    }

    public function testListFaqArticlesExposesCategoryForTheFrontColumns(): void
    {
        $this->makeFaqArticle('fr', true, 'Question client', 1)->setCategory('Clients');
        $this->makeFaqArticle('fr', true, 'Question sans catégorie', 2);
        $this->em->flush();

        $this->client->request('GET', '/api/faq');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $byQuestion = array_column($data, 'category', 'question');
        self::assertSame('Clients', $byQuestion['Question client']);
        self::assertNull($byQuestion['Question sans catégorie']);
    }

    public function testListFaqArticlesOrdersByPosition(): void
    {
        $this->makeFaqArticle('fr', true, 'Deuxième question', 2);
        $this->makeFaqArticle('fr', true, 'Première question', 1);
        $this->em->flush();

        $this->client->request('GET', '/api/faq');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Première question', $data[0]['question']);
        self::assertSame('Deuxième question', $data[1]['question']);
    }
}
