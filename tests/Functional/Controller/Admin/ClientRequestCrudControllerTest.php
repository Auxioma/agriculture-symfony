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
 * Teste le module "Demandes clients" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf §13 :
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

    // * "archivage" et "signalement" (§13) sont juste des valeurs de RequestStatus (Archived/Reported) --
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

    public function testCreatingRequestFromBackofficeIsForbidden(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', $this->urlFor(Action::NEW));

        self::assertResponseStatusCodeSame(403);
    }
}
