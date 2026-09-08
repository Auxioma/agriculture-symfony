<?php

namespace App\Tests\Functional\Controller\Notification;

use App\Entity\Identity\User;
use App\Entity\Notification\Notification;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * Teste GET /api/notifications, POST .../{id}/read et POST .../read-all
 * (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf, round 1 -- lecture/gestion uniquement).
 * NotificationService n'est pas utilisée ici : rien ne l'appelle encore dans le code applicatif (round 2),
 * donc Symfony la retire du conteneur compilé comme service mort -- inaccessible même via getContainer().
 * Les Notification de test sont donc construites directement, comme Label/ProducerLabel dans
 * ProducerControllerTest.
 */
final class NotificationControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private function makeNotification(User $recipient, string $body, ?\DateTimeImmutable $readAt = null): Notification
    {
        $notification = new Notification();
        $notification->setIdUser($recipient);
        $notification->setType('test_notification');
        $notification->setTitle('Titre de test');
        $notification->setBody($body);
        $notification->setReadAt($readAt);
        $this->em->persist($notification);

        return $notification;
    }

    // * makeUser() (hash bidon) ne permet pas de se logger via /api/auth/login -- comme ces tests n'ont pas
    // * besoin de vérifier un vrai mot de passe, on émet nous-même un JWT pour l'utilisateur de fixture.
    private function loginAs(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    public function testListNotificationsReturnsOnlyMine(): void
    {
        $me = $this->makeUser('me');
        $someoneElse = $this->makeUser('other');
        $this->makeNotification($me, 'Votre demande a bien été envoyée.');
        $this->makeNotification($someoneElse, 'Pas pour moi.');
        $this->em->flush();

        $token = $this->loginAs($me);

        $this->client->request('GET', '/api/notifications', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame('Votre demande a bien été envoyée.', $data[0]['body']);
    }

    public function testListNotificationsFiltersUnreadOnly(): void
    {
        $me = $this->makeUser('me');
        $this->makeNotification($me, 'Déjà lu', new \DateTimeImmutable());
        $unread = $this->makeNotification($me, 'Pas encore lu');
        $this->em->flush();

        $token = $this->loginAs($me);

        $this->client->request('GET', '/api/notifications?unreadOnly=true', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame($unread->getId()->toRfc4122(), $data[0]['id']);
    }

    public function testMarkAsReadSucceeds(): void
    {
        $me = $this->makeUser('me');
        $notification = $this->makeNotification($me, 'Un producteur a envoyé un devis.');
        $this->em->flush();
        $id = $notification->getId()->toRfc4122();

        $token = $this->loginAs($me);

        $this->client->request('POST', '/api/notifications/'.$id.'/read', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT read_at FROM notification.notifications WHERE id = :id',
            ['id' => $id]
        );
        self::assertNotNull($row['read_at']);
    }

    public function testMarkAsReadRejectsNotificationOwnedByAnotherUser(): void
    {
        $owner = $this->makeUser('owner');
        $intruder = $this->makeUser('intruder');
        $notification = $this->makeNotification($owner, 'Pas pour toi');
        $this->em->flush();

        $token = $this->loginAs($intruder);

        // * 404 (pas 403) : on ne révèle pas qu'une notification appartenant à quelqu'un d'autre existe --
        // * même logique que findAccessibleConversation() ailleurs dans l'API pour des ressources privées.
        $this->client->request('POST', '/api/notifications/'.$notification->getId()->toRfc4122().'/read', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testMarkAllAsReadMarksEverythingRead(): void
    {
        $me = $this->makeUser('me');
        $this->makeNotification($me, 'Message 1');
        $this->makeNotification($me, 'Message 2');
        $this->em->flush();

        $token = $this->loginAs($me);

        $this->client->request('POST', '/api/notifications/read-all', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseIsSuccessful();
        $unreadCount = (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM notification.notifications WHERE user_id = :userId AND read_at IS NULL',
            ['userId' => $me->getId()->toRfc4122()]
        );
        self::assertSame(0, $unreadCount);
    }
}
