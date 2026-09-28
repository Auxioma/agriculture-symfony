<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\ClientRequestCrudController;
use App\Entity\Identity\User;
use App\Entity\Matching\ClientRequest;
use App\Entity\Matching\ProducerReply;
use App\Entity\Messaging\Conversation;
use App\Entity\Messaging\Message;
use App\Enum\RequestStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Teste le module "Demandes clients" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf :
 * "Liste, filtres, statut, doublons, spam, suppression, archivage, signalement").
 */
final class ClientRequestCrudControllerTest extends ApiTestCase
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

    private function urlFor(string $action, ?string $entityId = null): string
    {
        $generator = self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(ClientRequestCrudController::class)
            ->setAction($action);

        if (null !== $entityId) {
            $generator->setEntityId($entityId);
        }

        return $generator->generateUrl();
    }

    public function testIndexListsRequests(): void
    {
        $this->loginAsAdmin();
        $client = $this->makeUser('client');
        $this->makeClientRequest($client);
        $this->em->flush();

        $this->client->request('GET', $this->urlFor(Action::INDEX));

        self::assertResponseIsSuccessful();
    }

    // * "archivage" et "signalement" (cahier fonctionnel) sont juste des valeurs de RequestStatus (Archived/Reported) --
    // * vérifie que le formulaire d'édition les accepte réellement, pas seulement en théorie côté entité.
    public function testEditingStatusToArchivedSucceeds(): void
    {
        $this->loginAsAdmin();
        $client = $this->makeUser('client');
        $request = $this->makeClientRequest($client, status: RequestStatus::Sent);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->urlFor(Action::EDIT, $request->getId()->toRfc4122()));
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form#edit-ClientRequest-form')->form();
        $values = $form->getPhpValues();
        $rootKey = array_key_first($values);
        $values[$rootKey]['status'] = 'archived';

        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status FROM matching.client_requests WHERE id = :id',
            ['id' => $request->getId()->toRfc4122()]
        );
        self::assertSame('archived', $row['status']);
    }

    // * Vérifie que la suppression d'une demande cascade proprement à travers réponse producteur + conversation
    // * + message (tous en orphanRemoval: true côté ORM) sans violation de contrainte de clé étrangère --
    // * c'était le principal risque identifié avant d'activer DELETE sur ce module.
    public function testDeletingRequestCascadesToReplyAndConversation(): void
    {
        $this->loginAsAdmin();
        $country = $this->makeCountry();
        $client = $this->makeUser('client');
        $producerOwner = $this->makeUser('producer');
        $producer = $this->makeProducerProfile($producerOwner, $country);
        $request = $this->makeClientRequest($client);

        $reply = new ProducerReply();
        $reply->setRequest($request);
        $reply->setProducer($producer);
        $this->em->persist($reply);

        $conversation = new Conversation();
        $conversation->setRequest($request);
        $conversation->setClient($client);
        $conversation->setProducer($producer);
        $this->em->persist($conversation);

        $message = new Message();
        $message->setConversation($conversation);
        $message->setSender($client);
        $message->setContent('Bonjour');
        $this->em->persist($message);

        $this->em->flush();
        $requestId = $request->getId()->toRfc4122();

        // * Le token CSRF ('ea-delete') vit en session -- il n'existe qu'une fois une vraie requête HTTP
        // * passée par le firewall admin. On le récupère depuis le formulaire caché de confirmation
        // * (rendu sur chaque page CRUD) plutôt que de générer un token hors contexte de requête, qui
        // * échoue avec SessionNotFoundException (aucune session active dans le process de test lui-même).
        $crawler = $this->client->request('GET', $this->urlFor(Action::DETAIL, $requestId));
        $csrfToken = $crawler->filter('#action-confirmation-form input[name="token"]')->attr('value');

        // * loginAsAdmin() a activé followRedirects(true) : la réponse ici est déjà celle de la page suivante
        // * (la liste), le redirect post-suppression ayant été suivi automatiquement -- donc 200, pas 302.
        $this->client->request('POST', $this->urlFor(Action::DELETE, $requestId), [
            'token' => $csrfToken,
        ]);

        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT 1 FROM matching.client_requests WHERE id = :id',
            ['id' => $requestId]
        );
        self::assertFalse($row);
    }

    // * Category et Country n'ont pas de __toString() : le <select> du filtre (Symfony EntityType) plantait
    // * en tentant de caster l'entité en chaîne pour l'option affichée -- ce test rend vraiment le panneau
    // * de filtres (render-filters), pas seulement la liste, pour exercer ce chemin précis.
    public function testRenderingCategoryAndCountryFiltersSucceeds(): void
    {
        $this->loginAsAdmin();
        $this->makeCategory();
        $this->makeCountry();
        $this->em->flush();

        $this->client->request('GET', $this->urlFor('renderFilters'));

        self::assertResponseIsSuccessful();
    }

    /**
     * Un client avec un doublon (l'original + sa copie), un autre avec un lien suspect, un troisième sans rien à
     * signaler. Retourne les trois clients pour retrouver leurs lignes par email (la colonne "Client" est affichée).
     *
     * @return array{dup: User, spam: User, clean: User}
     */
    private function seedSignals(): array
    {
        $dup = $this->makeUser('dupclient');
        $spam = $this->makeUser('spamclient');
        $clean = $this->makeUser('cleanclient');
        foreach ([[$dup, 'Fromage de chèvre', null], [$dup, 'Fromage de chèvre', null], [$spam, 'Miel', 'Offre sur https://promo.example'], [$clean, 'Pommes', 'Je cherche des pommes']] as [$client, $what, $message]) {
            $request = $this->makeClientRequest($client);
            $request->setCustomProduct($what);
            $request->setCity('Rennes');
            $request->setMessage($message);
            $this->em->flush();
        }

        return ['dup' => $dup, 'spam' => $spam, 'clean' => $clean];
    }

    /**
     * @return list<string> textes des badges de signal (avec infobulle) de la ligne de ce client
     */
    private function signalBadgesOf(\Symfony\Component\DomCrawler\Crawler $crawler, User $client): array
    {
        $badges = [];
        $crawler->filter('table tbody tr')->reduce(static fn ($row) => str_contains($row->text(), $client->getEmail()))
            ->each(static function ($row) use (&$badges): void {
                $badges[] = implode('+', $row->filter('td .badge[title]')->each(static fn ($b) => trim($b->text())));
            });

        return $badges;
    }

    public function testIndexShowsDuplicateAndSpamBadgesOnlyOnFlaggedRequests(): void
    {
        $this->loginAsAdmin();
        $clients = $this->seedSignals();

        $crawler = $this->client->request('GET', $this->urlFor(Action::INDEX));

        self::assertResponseIsSuccessful();
        // * Deux lignes pour le client au doublon : l'original (rien) et la copie plus récente (Doublon).
        self::assertEqualsCanonicalizing(['', 'Doublon'], $this->signalBadgesOf($crawler, $clients['dup']));
        self::assertSame(['Spam · lien'], $this->signalBadgesOf($crawler, $clients['spam']));
        self::assertSame([''], $this->signalBadgesOf($crawler, $clients['clean']));
    }

    public function testFlaggedChipShowsTheCountAndFiltersTheList(): void
    {
        $this->loginAsAdmin();
        $clients = $this->seedSignals();

        $crawler = $this->client->request('GET', $this->urlFor(Action::INDEX));
        $chip = $crawler->filter('.tm-chip')->reduce(static fn ($c) => str_contains($c->text(), 'À vérifier'));
        self::assertCount(1, $chip);
        self::assertStringContainsString('À vérifier (2)', $chip->text());
        self::assertStringNotContainsString('tm-chip-active', (string) $chip->attr('class'));

        $crawler = $this->client->request('GET', $chip->attr('href'));

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('table tbody tr[data-id]'));
        self::assertSame(['Doublon'], $this->signalBadgesOf($crawler, $clients['dup']));
        self::assertSame(['Spam · lien'], $this->signalBadgesOf($crawler, $clients['spam']));
        self::assertSame([], $this->signalBadgesOf($crawler, $clients['clean']));
        $active = $crawler->filter('.tm-chip-active');
        self::assertCount(1, $active);
        self::assertStringContainsString('À vérifier', $active->text());
    }

    public function testFlaggedFilterWithNothingFlaggedListsNothingWithoutError(): void
    {
        $this->loginAsAdmin();
        $this->makeClientRequest($this->makeUser('alone'));
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->urlFor(Action::INDEX).'?filters[quality]=flagged');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('table tbody tr[data-id]'));
        self::assertStringContainsString('À vérifier (0)', $crawler->filter('.tm-chips')->text());
    }

    // * "Toutes" ne doit être active que si AUCUNE puce n'est filtrée : la puce "À vérifier" a sa propre propriété
    // * (quality), différente de celle des autres puces (status).
    public function testAllChipIsActiveOnlyWhenNoChipIsFiltered(): void
    {
        $this->loginAsAdmin();
        $this->seedSignals();
        $activeChips = fn (string $suffix): array => $this->client->request('GET', $this->urlFor(Action::INDEX).$suffix)
            ->filter('.tm-chip-active')->each(static fn ($c) => preg_replace('/\s*\(\d+\)$/', '', trim($c->text())));

        self::assertSame(['Toutes'], $activeChips(''));
        self::assertSame(['Envoyées'], $activeChips('?filters[status]=sent'));
        self::assertSame(['À vérifier'], $activeChips('?filters[quality]=flagged'));
    }

    // * Le contenu de la fenêtre "+ Filtres" n'est pas dans la page d'index : EasyAdmin le charge à la demande (action
    // * renderFilters). Elle passe aussi par configureResponseParameters(), qui ne doit pas s'y interposer.
    public function testFiltersModalStillOffersTheFlaggedChoice(): void
    {
        $this->loginAsAdmin();
        $this->seedSignals();

        $crawler = $this->client->request('GET', $this->urlFor('renderFilters'));

        self::assertResponseIsSuccessful();
        $select = $crawler->filter('select[name="filters[quality]"]');
        self::assertCount(1, $select);
        self::assertSame(['', 'flagged'], $select->filter('option')->each(static fn ($o) => $o->attr('value')));
        self::assertStringContainsString('À vérifier', $select->filter('option[value=flagged]')->text());
    }

    public function testDetailOfAnUnflaggedRequestSaysSoAndListsNoRelatedRequest(): void
    {
        $this->loginAsAdmin();
        $request = $this->makeClientRequest($this->makeUser('alone2'));
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->urlFor(Action::DETAIL, $request->getId()->toRfc4122()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Aucun signal de doublon ou de spam', $crawler->filter('body')->text());
        self::assertStringContainsString('Aucune autre demande de ce client', $crawler->filter('body')->text());
        self::assertCount(0, $crawler->filter('.tm-related-requests li'));
    }

    // * La copie (la plus récente) doit afficher pourquoi elle est signalée et un lien vers l'original qu'elle
    // * recopie ; l'original, lui, n'est pas signalé (RequestQualityAnalyzer ne marque que la copie).
    public function testDetailOfADuplicateExplainsItAndLinksToTheOriginal(): void
    {
        $this->loginAsAdmin();
        $client = $this->makeUser('dupdetail');
        $original = $this->makeClientRequest($client);
        $original->setCustomProduct('Fromage de chèvre');
        $original->setCity('Rennes');
        $this->em->flush();
        // * created_at n'a qu'une précision à la seconde en base (DBAL tronque les microsecondes à l'écriture) :
        // * sans cet écart explicite, les deux demandes tombent souvent dans la même seconde et le départage par
        // * identifiant (RequestQualityAnalyzer::duplicateOriginals()) choisit alors arbitrairement laquelle des
        // * deux est "l'original" -- ce test veut vérifier PRÉCISÉMENT que c'est la plus récente qui est signalée.
        $this->em->getConnection()->executeStatement(
            "UPDATE matching.client_requests SET created_at = created_at - interval '1 hour' WHERE id = :id",
            ['id' => $original->getId()->toRfc4122()]
        );
        $copy = $this->makeClientRequest($client);
        $copy->setCustomProduct('Fromage de chèvre');
        $copy->setCity('Rennes');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->urlFor(Action::DETAIL, $copy->getId()->toRfc4122()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.badge[title]', 'Doublon');
        $originalLink = $crawler->filter('.tm-related-requests a')->first();
        self::assertStringContainsString($original->getId()->toRfc4122(), $originalLink->attr('href'));

        $originalCrawler = $this->client->request('GET', $this->urlFor(Action::DETAIL, $original->getId()->toRfc4122()));
        self::assertCount(0, $originalCrawler->filter('.badge[title]'), 'L\'original ne doit pas lui-même être marqué "Doublon".');
    }

    public function testDetailListsOtherRequestsFromTheSameClientButNotFromAnother(): void
    {
        $this->loginAsAdmin();
        $client = $this->makeUser('related1');
        $other = $this->makeUser('related2');
        // * customProduct/city différents : sinon ces deux demandes du même client seraient elles-mêmes détectées
        // * comme doublon l'une de l'autre (RequestQualityAnalyzer), et apparaîtraient aussi dans la section
        // * "Signalement" -- ce test porte uniquement sur la section "Autres demandes de ce client".
        $current = $this->makeClientRequest($client);
        $current->setCustomProduct('Produit A');
        $sibling = $this->makeClientRequest($client);
        $sibling->setCustomProduct('Produit B');
        $unrelated = $this->makeClientRequest($other);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->urlFor(Action::DETAIL, $current->getId()->toRfc4122()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Aucun signal de doublon ou de spam', $crawler->filter('body')->text());
        $links = $crawler->filter('.tm-related-requests a')->each(static fn ($a) => $a->attr('href'));
        self::assertCount(1, $links);
        self::assertStringContainsString($sibling->getId()->toRfc4122(), $links[0]);
        self::assertStringNotContainsString($unrelated->getId()->toRfc4122(), $links[0]);
        self::assertStringNotContainsString($current->getId()->toRfc4122(), $links[0]);
    }

    private function makeSpamRequest(string $prefix = 'spamqa'): ClientRequest
    {
        $request = $this->makeClientRequest($this->makeUser($prefix));
        $request->setMessage('Offre sur https://promo.example');
        $this->em->flush();

        return $request;
    }

    /**
     * @return array{0: ClientRequest, 1: ClientRequest} [original, copie plus récente -- seule la copie est signalée]
     */
    private function makeDuplicatePair(string $prefix = 'dupqa'): array
    {
        $client = $this->makeUser($prefix);
        $original = $this->makeClientRequest($client);
        $original->setCustomProduct('Fromage de chèvre');
        $original->setCity('Rennes');
        $this->em->flush();
        // * Voir testDetailOfADuplicateExplainsItAndLinksToTheOriginal : sans cet écart, le départage par identifiant
        // * choisirait arbitrairement lequel des deux est "l'original" (created_at à la précision de la seconde).
        $this->em->getConnection()->executeStatement(
            "UPDATE matching.client_requests SET created_at = created_at - interval '1 hour' WHERE id = :id",
            ['id' => $original->getId()->toRfc4122()]
        );
        $copy = $this->makeClientRequest($client);
        $copy->setCustomProduct('Fromage de chèvre');
        $copy->setCity('Rennes');
        $this->em->flush();

        return [$original, $copy];
    }

    private function requestStatus(ClientRequest $request): string
    {
        $status = $this->em->getConnection()->fetchOne('SELECT status FROM matching.client_requests WHERE id = :id', ['id' => $request->getId()->toRfc4122()]);
        self::assertNotFalse($status);

        return $status;
    }

    /**
     * Poste une action qualité avec le vrai jeton CSRF lu dans le premier formulaire d'action de la page (comme
     * TicketCrudControllerTest::postQuickAction()) -- suppose qu'au moins un des deux boutons y est rendu.
     *
     * @param array<string, string> $params
     */
    private function postQualityAction(ClientRequest $request, array $params): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', $this->urlFor(Action::DETAIL, $request->getId()->toRfc4122()));
        $form = $crawler->filter('input[name=operation]')->first()->closest('form');
        self::assertNotNull($form);

        return $this->client->request('POST', $form->form()->getUri(), $params + [
            '_csrf_token' => $form->filter('input[name=_csrf_token]')->attr('value'),
        ]);
    }

    /**
     * Le jeton CSRF ('client_request_quality_action') n'est pas propre à une demande précise : le lire sur la page
     * d'une AUTRE demande signalée fonctionne tout aussi bien -- utile pour poster contre une demande dont la page
     * ne rend elle-même aucun des deux formulaires (aucun signal ne correspond).
     */
    private function anyQualityActionCsrfToken(): string
    {
        $probe = $this->makeSpamRequest('csrfprobe');
        $this->em->flush();
        $crawler = $this->client->request('GET', $this->urlFor(Action::DETAIL, $probe->getId()->toRfc4122()));

        return $crawler->filter('input[name=_csrf_token]')->attr('value');
    }

    public function testMarkButtonsOnlyAppearForTheirMatchingSignal(): void
    {
        $this->loginAsAdmin();
        $spam = $this->makeSpamRequest();
        [, $duplicateCopy] = $this->makeDuplicatePair();
        $clean = $this->makeClientRequest($this->makeUser('cleanqa'));
        $this->em->flush();

        $spamCrawler = $this->client->request('GET', $this->urlFor(Action::DETAIL, $spam->getId()->toRfc4122()));
        self::assertCount(1, $spamCrawler->selectButton('Marquer comme spam'));
        self::assertCount(0, $spamCrawler->selectButton('Marquer comme doublon'));

        $dupCrawler = $this->client->request('GET', $this->urlFor(Action::DETAIL, $duplicateCopy->getId()->toRfc4122()));
        self::assertCount(0, $dupCrawler->selectButton('Marquer comme spam'));
        self::assertCount(1, $dupCrawler->selectButton('Marquer comme doublon'));

        $cleanCrawler = $this->client->request('GET', $this->urlFor(Action::DETAIL, $clean->getId()->toRfc4122()));
        self::assertCount(0, $cleanCrawler->selectButton('Marquer comme spam'));
        self::assertCount(0, $cleanCrawler->selectButton('Marquer comme doublon'));
    }

    public function testMarkingAsSpamCancelsTheRequestAndLogsEventAndAudit(): void
    {
        $admin = $this->loginAsAdmin();
        $spam = $this->makeSpamRequest();

        $crawler = $this->client->submit($this->client->request('GET', $this->urlFor(Action::DETAIL, $spam->getId()->toRfc4122()))
            ->selectButton('Marquer comme spam')->form());

        self::assertSelectorTextContains('.alert', 'marquée comme spam');
        self::assertSame('cancelled', $this->requestStatus($spam));
        // * Une fois annulée, la demande sort de LIVE_STATUSES : RequestQualityAnalyzer ne la signale plus (voir le
        // * docblock de la classe), la section "Signalement" doit donc redevenir "Aucun signal...".
        self::assertStringContainsString('Aucun signal de doublon ou de spam', $crawler->filter('body')->text());
        self::assertCount(0, $crawler->selectButton('Marquer comme spam'));

        $event = $this->em->getConnection()->fetchAssociative(
            'SELECT event_type, actor_id, payload FROM matching.request_events WHERE request_id = :id',
            ['id' => $spam->getId()->toRfc4122()]
        );
        self::assertNotFalse($event);
        self::assertSame('flagged_spam', $event['event_type']);
        self::assertSame($admin->getId()->toRfc4122(), $event['actor_id']);
        self::assertStringContainsString('link', $event['payload']);

        $audit = $this->em->getConnection()->fetchAssociative(
            "SELECT old_data, new_data FROM audit.audit_logs WHERE action = 'client_request_marked_spam' AND record_id = :id",
            ['id' => $spam->getId()->toRfc4122()]
        );
        self::assertNotFalse($audit);
        self::assertStringContainsString('sent', $audit['old_data']);
        self::assertStringContainsString('cancelled', $audit['new_data']);
    }

    public function testMarkingAsDuplicateCancelsTheCopyAndLogsEventAndAudit(): void
    {
        $this->loginAsAdmin();
        [$original, $copy] = $this->makeDuplicatePair();

        $this->client->submit($this->client->request('GET', $this->urlFor(Action::DETAIL, $copy->getId()->toRfc4122()))
            ->selectButton('Marquer comme doublon')->form());

        self::assertSelectorTextContains('.alert', 'marquée comme doublon');
        self::assertSame('cancelled', $this->requestStatus($copy));
        self::assertSame('sent', $this->requestStatus($original), 'L\'original ne doit pas être touché.');

        $event = $this->em->getConnection()->fetchAssociative(
            "SELECT event_type FROM matching.request_events WHERE request_id = :id",
            ['id' => $copy->getId()->toRfc4122()]
        );
        self::assertNotFalse($event);
        self::assertSame('flagged_duplicate', $event['event_type']);
        self::assertNotFalse($this->em->getConnection()->fetchAssociative(
            "SELECT 1 FROM audit.audit_logs WHERE action = 'client_request_marked_duplicate' AND record_id = :id",
            ['id' => $copy->getId()->toRfc4122()]
        ));
    }

    public function testMarkingAsSpamIsRejectedWhenThereIsNoSpamSignal(): void
    {
        $this->loginAsAdmin();
        $clean = $this->makeClientRequest($this->makeUser('cleanqa2'));
        $this->em->flush();
        // * La page de $clean ne rend elle-même aucun des deux formulaires (aucun signal) : le jeton vient d'une
        // * autre demande signalée -- il n'est pas propre à une demande précise.
        $token = $this->anyQualityActionCsrfToken();

        $this->client->request('POST', $this->urlFor('qualityAction', $clean->getId()->toRfc4122()), [
            'operation' => 'mark_spam',
            '_csrf_token' => $token,
        ]);

        self::assertSelectorTextContains('.alert-danger', "n'a aucun signal de spam");
        self::assertSame('sent', $this->requestStatus($clean));
    }

    public function testMarkingAsDuplicateIsRejectedWhenNotFlaggedAsDuplicate(): void
    {
        $this->loginAsAdmin();
        $spam = $this->makeSpamRequest('notdup');

        $this->postQualityAction($spam, ['operation' => 'mark_duplicate']);

        self::assertSelectorTextContains('.alert-danger', "n'est pas signalée comme doublon");
        self::assertSame('sent', $this->requestStatus($spam));
    }

    // * Un POST direct après que le statut a changé entre-temps (tampering ou simple concurrence) doit être
    // * revérifié côté serveur -- l'interface ne montre le bouton que pour une demande encore en circulation, mais
    // * ça n'empêche pas un envoi de formulaire a posteriori.
    public function testMarkingIsRejectedOnceTheRequestIsNoLongerLive(): void
    {
        $this->loginAsAdmin();
        $spam = $this->makeSpamRequest('racequa');
        $crawler = $this->client->request('GET', $this->urlFor(Action::DETAIL, $spam->getId()->toRfc4122()));
        $form = $crawler->selectButton('Marquer comme spam')->form();

        $spam = $this->em->find(ClientRequest::class, $spam->getId());
        $spam->setStatus(RequestStatus::Archived);
        $this->em->flush();

        $this->client->submit($form);

        self::assertSelectorTextContains('.alert-danger', 'encore en circulation');
        self::assertSame('archived', $this->requestStatus($spam));
    }

    public function testUnknownOperationIsRejectedWithBadRequest(): void
    {
        $this->loginAsAdmin();
        $spam = $this->makeSpamRequest('badopqa');

        $this->postQualityAction($spam, ['operation' => 'delete-everything']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('sent', $this->requestStatus($spam));
    }

    public function testQualityActionWithInvalidCsrfTokenIsForbidden(): void
    {
        $this->loginAsAdmin();
        $spam = $this->makeSpamRequest('csrfqa');

        $this->postQualityAction($spam, ['operation' => 'mark_spam', '_csrf_token' => 'faux']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('sent', $this->requestStatus($spam));
    }

    public function testCreatingRequestFromBackofficeIsForbidden(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', $this->urlFor(Action::NEW));

        self::assertResponseStatusCodeSame(403);
    }
}
