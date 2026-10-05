<?php

/**
 * Articles de FAQ publics (page "Questions fréquentes" du front et bloc FAQ de l'accueil, voir FaqController
 * pour la lecture publique GET /api/faq). La catégorie sert de colonne sur la page FAQ (Clients / Producteurs),
 * les positions donnent l'ordre. Contenu repris du figma de la page FAQ, les réponses non visibles dessus sont
 * écrites d'après le cahier des charges fonctionnel (paiement hors plateforme, annulation, matching) et le
 * comportement réel du back (annulation d'abonnement en fin de période).
 */

namespace App\DataFixtures;

use App\Entity\Content\FaqArticle;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class FaqFixtures extends Fixture
{
    private const ARTICLES = [
        [
            'Clients',
            'Est-ce gratuit pour les clients ?',
            'Oui, déposer une demande et échanger avec les producteurs est entièrement gratuit pour les clients.',
        ],
        [
            'Clients',
            'Comment se passe le paiement ?',
            'Le paiement des produits se fait directement entre vous et le producteur, hors plateforme : TrouveMoi Agri ne gère ni panier ni paiement des produits agricoles.',
        ],
        [
            'Clients',
            'Puis-je annuler une demande ?',
            'Oui, vous pouvez annuler une demande depuis votre espace « Mes demandes ». Vous pouvez aussi l\'archiver ou la dupliquer.',
        ],
        [
            'Producteurs',
            'Combien coûte l\'abonnement ?',
            'À partir de 9€/mois selon votre plan, sans commission sur vos ventes. Voir tous les tarifs.',
        ],
        [
            'Producteurs',
            'Puis-je annuler à tout moment ?',
            'Oui, l\'annulation est simple et se fait depuis votre espace abonnement. Elle prend effet à la fin de la période déjà payée.',
        ],
        [
            'Producteurs',
            'Comment recevoir plus de demandes ?',
            'Complétez votre profil (produits, disponibilités, photos, labels) et répondez vite : les demandes sont proposées selon la pertinence des produits, la proximité, la qualité du profil et la réactivité.',
        ],
    ];

    public function load(ObjectManager $manager): void
    {
        foreach (self::ARTICLES as $position => [$category, $question, $answer]) {
            $article = new FaqArticle();
            $article->setCategory($category);
            $article->setLocale('fr');
            $article->setQuestion($question);
            $article->setAnswer($answer);
            $article->setPosition($position);
            $article->setIsActive(true);
            $manager->persist($article);
        }

        $manager->flush();
    }
}
