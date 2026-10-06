<?php

/**
 * Pages légales publiques (GET /api/legal/{code}, page "Mentions légales" du front). Le contenu est du texte
 * simple : chaque section = une ligne "## Titre" puis son paragraphe, séparées par une ligne vide (c'est ce que
 * lit LegalComponent côté Angular). Revoir (forme juridique,
 * siège, adresse e-mail) : il manque aussi des mentions obligatoires (directeur de publication, hébergeur
 * nommé...). Seules les mentions légales et les CGU sont écrites pour l'instant, la confidentialité reste à faire.
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

    private const TERMS = <<<'TXT'
        ## Objet

        Les présentes conditions générales d'utilisation régissent l'accès et l'utilisation de TrouveMoi Agri, plateforme de mise en relation entre producteurs agricoles et clients. Elles s'appliquent à tout visiteur, client ou producteur inscrit sur le site.

        ## Description du service

        TrouveMoi Agri permet de rechercher des producteurs, déposer une demande de prix ou de devis et échanger via la messagerie. La plateforme ne propose ni panier d'achat, ni paiement des produits agricoles : la transaction finale s'organise directement entre le client et le producteur.

        ## Inscription et comptes

        L'inscription est gratuite pour les clients (particuliers et professionnels) et pour les producteurs. Chaque utilisateur s'engage à fournir des informations exactes et à jour, et à ne créer qu'un seul compte par personne ou par exploitation.

        ## Obligations des producteurs

        Le producteur reste seul responsable de ses prix, de la qualité de ses produits, de ses pratiques agricoles, de ses factures et du respect des obligations sanitaires en vigueur. TrouveMoi Agri ne garantit pas automatiquement la conformité des produits proposés.

        ## Obligations des clients

        Le client s'engage à formuler des demandes sincères et à ne pas détourner la messagerie à des fins commerciales étrangères à la plateforme. Tout comportement abusif peut faire l'objet d'un signalement et d'une suspension de compte.

        ## Abonnement et paiement

        L'accès aux demandes clients par les producteurs est soumis à un abonnement mensuel ou annuel, sans commission sur les ventes. Le prix, la date de renouvellement et les factures sont accessibles à tout moment ; l'annulation est possible avant chaque échéance.

        ## Responsabilité

        TrouveMoi Agri facilite la mise en relation mais n'intervient pas dans la transaction entre client et producteur. La responsabilité de la plateforme ne saurait être engagée en cas de litige portant sur le produit, le prix ou les conditions convenues entre les parties.

        ## Modification des CGU

        TrouveMoi Agri peut modifier les présentes conditions à tout moment. Les utilisateurs seront informés de toute modification substantielle ; la poursuite de l'utilisation du service vaut acceptation des nouvelles conditions.
        TXT;

    public function load(ObjectManager $manager): void
    {
        $pages = [
            ['mentions-legales', 'Mentions légales', self::LEGAL_NOTICE],
            ['cgu', "Conditions générales d'utilisation", self::TERMS],
        ];

        foreach ($pages as [$code, $title, $content]) {
            $page = new LegalPage();
            $page->setCode($code);
            $page->setLocale('fr');
            $page->setVersion(1);
            $page->setTitle($title);
            $page->setContent($content);
            $page->setIsActive(true);
            $page->setPublishedAt(new \DateTimeImmutable());
            $manager->persist($page);
        }

        $manager->flush();
    }
}
