<?php

/**
 * Articles de FAQ publics (cahier fonctionnel, "FAQ rassurante" de l'accueil). FaqArticle existait deja
 * dans le schema mais aucune fixture ni controleur ne l'exploitait -- voir FaqController pour la lecture
 * publique (GET /api/faq). Contenu reel base sur les points deja confirmes du cahier (gratuite cote
 * client, pas de commission, verification producteur), pas du lorem ipsum.
 */

namespace App\DataFixtures;

use App\Entity\Content\FaqArticle;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class FaqFixtures extends Fixture
{
    private const ARTICLES = [
        [
            'Déposer une demande est-il vraiment gratuit ?',
            "Oui, déposer une demande est entièrement gratuit pour les clients. Seuls les producteurs paient un abonnement pour recevoir et répondre aux demandes qualifiées.",
        ],
        [
            'Comment savoir si un producteur est fiable ?',
            'Chaque producteur vérifié affiche un badge après validation de son profil par notre équipe, et vous pouvez consulter les avis laissés par d\'autres clients.',
        ],
        [
            'La plateforme prend-elle une commission sur mes achats ?',
            "Non, TrouveMoi Agri ne prélève aucune commission sur les ventes. Le prix et le paiement se négocient directement entre vous et le producteur.",
        ],
        [
            "Puis-je échanger avec le producteur avant de m'engager ?",
            'Oui, une messagerie intégrée vous permet de discuter directement avec le producteur dès que votre demande a été envoyée.',
        ],
    ];

    public function load(ObjectManager $manager): void
    {
        foreach (self::ARTICLES as $position => [$question, $answer]) {
            $article = new FaqArticle();
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
