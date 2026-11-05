<?php

namespace App\Tests\Functional\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Les actions de ligne (Valider, Anonymiser, Masquer...) sont des petits formulaires POST dont l'adresse porte un jeton
 * CSRF (CsrfPostActionTrait). On part donc de la liste, comme le navigateur : on lit l'adresse du formulaire, jeton compris,
 * au lieu de la fabriquer à la main. À utiliser tant que le bouton est affiché (un bouton masqué n'a pas de formulaire) :
 * pour tester une action refusée selon l'état, on lit l'adresse d'abord, on change l'état ensuite, on envoie en dernier.
 */
trait RowActionTestTrait
{
    /**
     * @param class-string $controller
     * @param string       $pathEnd    fin de l'adresse de l'action, celle de #[AdminRoute] : "validate", "block-sender"...
     * @param string       $page       page qui affiche le bouton : la liste, ou la fiche (Action::DETAIL) pour les boutons qui n'y sont qu'elle
     */
    private function rowActionUrl(string $controller, string $entityId, string $pathEnd, string $page = Action::INDEX): string
    {
        $generator = self::getContainer()->get(AdminUrlGenerator::class)
            ->setController($controller)
            ->setAction($page);
        if (Action::DETAIL === $page) {
            $generator->setEntityId($entityId);
        }

        $crawler = $this->client->request('GET', $generator->generateUrl());
        // * Le "?" colle la fin du chemin : "reject" ne doit pas retomber sur "reject-report".
        $forms = $crawler->filter(\sprintf('form[action*="%s/%s?"]', $entityId, $pathEnd));
        self::assertCount(1, $forms, \sprintf('Pas de formulaire "%s" pour cette ligne sur la liste.', $pathEnd));

        return (string) $forms->attr('action');
    }

    /**
     * @param class-string $controller
     */
    private function postRowAction(string $controller, string $entityId, string $pathEnd, string $page = Action::INDEX): void
    {
        $this->client->request('POST', $this->rowActionUrl($controller, $entityId, $pathEnd, $page));
    }

    // * Même adresse, jeton remplacé : celle d'un attaquant qui ne connaît pas le jeton de la session.
    private function withForgedToken(string $url): string
    {
        $forged = preg_replace('/([?&]_token=)[^&]+/', '${1}faux', $url);
        self::assertNotNull($forged);
        self::assertNotSame($url, $forged, 'Le jeton aurait dû être dans l\'adresse.');

        return $forged;
    }

    private function withoutToken(string $url): string
    {
        $stripped = preg_replace('/([?&])_token=[^&]*&?/', '$1', $url);
        self::assertNotNull($stripped);

        return mb_rtrim($stripped, '?&');
    }
}
