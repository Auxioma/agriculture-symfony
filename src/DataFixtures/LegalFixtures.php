<?php

/**
 * Pages légales publiques (GET /api/legal/{code}, page "Mentions légales" du front). Le contenu est du texte
 * simple : chaque section = une ligne "## Titre" puis son paragraphe, séparées par une ligne vide (c'est ce que
 * lit LegalComponent côté Angular). Revoir (forme juridique,
 * siège, adresse e-mail) : il manque aussi des mentions obligatoires (directeur de publication, hébergeur
 * nommé...). Seules les mentions légales sont écrites pour l'instant, CGU et confidentialité restent à faire.
 */

namespace App\DataFixtures;

use App\Entity\Content\LegalPage;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class LegalFixtures extends Fixture
{
    private const LEGAL_NOTICE = <<<'TXT'
        ## Éditeur du site

        TrouveMoi Agri SAS, plateforme de mise en relation entre producteurs agricoles et clients. Siège social : Lyon, France. Contact : contact@trouvemoi-agri.com.

        ## Hébergement

        Le site est hébergé par un prestataire cloud sécurisé situé dans l'Union Européenne, garantissant la conformité RGPD des données hébergées.

        ## Propriété intellectuelle

        L'ensemble des contenus, marques et éléments graphiques présents sur TrouveMoi Agri sont protégés et ne peuvent être reproduits sans autorisation.

        ## Rôle de la plateforme

        TrouveMoi Agri facilite la mise en relation entre clients et producteurs. La plateforme ne vend pas les produits agricoles et ne garantit pas automatiquement leur conformité ; le producteur reste responsable de ses prix, produits et obligations sanitaires.

        ## Données personnelles

        Conformément au RGPD, vous disposez d'un droit d'accès, de rectification, d'export et de suppression de vos données. Consultez notre politique de confidentialité pour en savoir plus.
        TXT;

    public function load(ObjectManager $manager): void
    {
        $page = new LegalPage();
        $page->setCode('mentions-legales');
        $page->setLocale('fr');
        $page->setVersion(1);
        $page->setTitle('Mentions légales');
        $page->setContent(self::LEGAL_NOTICE);
        $page->setIsActive(true);
        $page->setPublishedAt(new \DateTimeImmutable());
        $manager->persist($page);

        $manager->flush();
    }
}
