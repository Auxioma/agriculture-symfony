<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\ClientRequestCrudController;
use App\Entity\Identity\User;
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

    public function testCreatingRequestFromBackofficeIsForbidden(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', $this->urlFor(Action::NEW));

        self::assertResponseStatusCodeSame(403);
    }
}
