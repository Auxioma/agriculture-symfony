<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\ProducerProfileCrudController;
use App\Entity\Identity\User;
use App\Enum\VerificationStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Teste le module "Validation producteurs" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf
 * "Documents, labels, vérification, validation, refus, demande de complement") et la notification
 * "Profil validé" prévue par le cahier des charges.
 */

final class ProducerProfileCrudControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;
    use RowActionTestTrait;

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

    // * Les boutons sont des formulaires POST avec jeton (CsrfPostActionTrait) : on les envoie comme le navigateur, depuis la
    // * liste (Valider, Refuser) ou depuis la fiche (Demander un complément, qui n'existe que là).
    public function testValidateActionSetsStatusAndNotifiesOwner(): void
    {
        $this->loginAsAdmin();
        $country = $this->makeCountry();
        $owner = $this->makeUser('producer');
        $producer = $this->makeProducerProfile($owner, $country, VerificationStatus::Pending);
        $this->em->flush();

        $this->postRowAction(ProducerProfileCrudController::class, $producer->getId()->toRfc4122(), 'validate');

        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT verification_status FROM producer.producer_profiles WHERE id = :id',
            ['id' => $producer->getId()->toRfc4122()]
        );
        self::assertSame('verified', $row['verification_status']);

        $notification = $this->em->getConnection()->fetchAssociative(
            "SELECT type FROM notification.notifications WHERE user_id = :userId AND type = 'profile_validated'",
            ['userId' => $owner->getId()->toRfc4122()]
        );
        self::assertNotFalse($notification);

        $audit = $this->em->getConnection()->fetchAssociative(
            "SELECT record_id FROM audit.audit_logs WHERE action = 'producer_validated' AND table_name = 'producer_profiles'"
        );
        self::assertNotFalse($audit);
        self::assertSame($producer->getId()->toRfc4122(), $audit['record_id']);
    }

    public function testRejectActionSetsStatusAndNotifiesOwner(): void
    {
        $this->loginAsAdmin();
        $country = $this->makeCountry();
        $owner = $this->makeUser('producer');
        $producer = $this->makeProducerProfile($owner, $country, VerificationStatus::Pending);
        $this->em->flush();

        $this->postRowAction(ProducerProfileCrudController::class, $producer->getId()->toRfc4122(), 'reject');

        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT verification_status FROM producer.producer_profiles WHERE id = :id',
            ['id' => $producer->getId()->toRfc4122()]
        );
        self::assertSame('rejected', $row['verification_status']);

        $notification = $this->em->getConnection()->fetchAssociative(
            "SELECT type FROM notification.notifications WHERE user_id = :userId AND type = 'profile_rejected'",
            ['userId' => $owner->getId()->toRfc4122()]
        );
        self::assertNotFalse($notification);
    }

    // * Le statut ne bouge pas : "demande de complément" ne fait pas partie des transitions
    // * draft/pending/verified/rejected/suspended, seule la notification informe le producteur.
    public function testRequestMoreInfoNotifiesOwnerWithoutChangingStatus(): void
    {
        $this->loginAsAdmin();
        $country = $this->makeCountry();
        $owner = $this->makeUser('producer');
        $producer = $this->makeProducerProfile($owner, $country, VerificationStatus::Pending);
        $this->em->flush();

        $this->postRowAction(ProducerProfileCrudController::class, $producer->getId()->toRfc4122(), 'request-more-info', Action::DETAIL);

        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT verification_status FROM producer.producer_profiles WHERE id = :id',
            ['id' => $producer->getId()->toRfc4122()]
        );
        self::assertSame('pending', $row['verification_status']);

        $notification = $this->em->getConnection()->fetchAssociative(
            "SELECT type FROM notification.notifications WHERE user_id = :userId AND type = 'profile_more_info_needed'",
            ['userId' => $owner->getId()->toRfc4122()]
        );
        self::assertNotFalse($notification);
    }

    /**
     * @return array{0: \App\Entity\Producer\ProducerProfile, 1: User}
     */
    private function makePendingProducer(): array
    {
        $owner = $this->makeUser('producer');
        $producer = $this->makeProducerProfile($owner, $this->makeCountry(), VerificationStatus::Pending);
        $this->em->flush();

        return [$producer, $owner];
    }

    private function verificationStatus(string $producerId): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT verification_status FROM producer.producer_profiles WHERE id = :id', ['id' => $producerId]);
    }

    private function notificationCount(User $owner): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM notification.notifications WHERE user_id = :id', ['id' => $owner->getId()->toRfc4122()]);
    }

    // * La fiche d'un producteur plantait en 500 à chaque ouverture : le champ "Labels" était une AssociationField vers
    // * ProducerLabel, dont la clé primaire est composée, ce qu'EasyAdmin refuse. Le bouton "Demander un complément" n'existe que
    // * sur cette fiche : il était donc inatteignable.
    public function testProducerDetailPageOpensAndListsItsLabels(): void
    {
        $this->loginAsAdmin();
        [$producer] = $this->makePendingProducer();
        $producerLabel = new \App\Entity\Producer\ProducerLabel();
        $producerLabel->setProducer($producer);
        $producerLabel->setLabel($this->makeLabel('bio', 'Agriculture biologique'));
        $this->em->persist($producerLabel);
        $this->em->flush();

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(ProducerProfileCrudController::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($producer->getId())
            ->generateUrl());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Agriculture biologique', $crawler->text());
    }

    // * Avant : trois liens GET, sans jeton, qui prévenaient le producteur par notification à chaque appel.
    public function testProducerActionsRefuseGetAndABadToken(): void
    {
        $this->loginAsAdmin();
        [$producer, $owner] = $this->makePendingProducer();
        $id = $producer->getId()->toRfc4122();

        $urls = [
            'validate' => $this->rowActionUrl(ProducerProfileCrudController::class, $id, 'validate'),
            'reject' => $this->rowActionUrl(ProducerProfileCrudController::class, $id, 'reject'),
            'request-more-info' => $this->rowActionUrl(ProducerProfileCrudController::class, $id, 'request-more-info', Action::DETAIL),
        ];

        foreach ($urls as $name => $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(405, $name.' en GET');

            $this->client->request('POST', $this->withForgedToken($url));
            self::assertResponseStatusCodeSame(403, $name.' avec un faux jeton');

            $this->client->request('POST', $this->withoutToken($url));
            self::assertResponseStatusCodeSame(403, $name.' sans jeton');
        }

        self::assertSame('pending', $this->verificationStatus($id));
        self::assertSame(0, $this->notificationCount($owner));
    }

    // * Le bouton est caché selon le statut, mais un POST direct passait : un profil déjà validé était revalidé, le
    // * producteur recevait une deuxième notification.
    public function testValidatingAnAlreadyVerifiedProfileIsRefused(): void
    {
        $this->loginAsAdmin();
        [$producer, $owner] = $this->makePendingProducer();
        $id = $producer->getId()->toRfc4122();
        $url = $this->rowActionUrl(ProducerProfileCrudController::class, $id, 'validate');

        $this->client->request('POST', $url);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->notificationCount($owner));

        $this->client->request('POST', $url);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->notificationCount($owner), 'Pas de seconde notification.');
        self::assertSame('verified', $this->verificationStatus($id));
    }

    public function testRejectingAnAlreadyRejectedProfileIsRefused(): void
    {
        $this->loginAsAdmin();
        [$producer, $owner] = $this->makePendingProducer();
        $id = $producer->getId()->toRfc4122();
        $url = $this->rowActionUrl(ProducerProfileCrudController::class, $id, 'reject');

        $this->client->request('POST', $url);
        self::assertResponseIsSuccessful();

        $this->client->request('POST', $url);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->notificationCount($owner));
    }

    public function testAskingForMoreInfoIsRefusedOnceTheProfileIsNoLongerPending(): void
    {
        $this->loginAsAdmin();
        [$producer, $owner] = $this->makePendingProducer();
        $id = $producer->getId()->toRfc4122();
        $url = $this->rowActionUrl(ProducerProfileCrudController::class, $id, 'request-more-info', Action::DETAIL);
        $this->em->getConnection()->executeStatement("UPDATE producer.producer_profiles SET verification_status = 'verified' WHERE id = :id", ['id' => $id]);

        $this->client->request('POST', $url);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->notificationCount($owner));
    }

    // * disable(Action::NEW, Action::DELETE) doit être appliqué côté serveur -- les profils producteur sont
    // * créés par le producteur lui-même via l'inscription, jamais depuis le back-office.
    public function testCreatingProducerFromBackofficeIsForbidden(): void
    {
        $this->loginAsAdmin();

        $url = self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(ProducerProfileCrudController::class)
            ->setAction(Action::NEW)
            ->generateUrl();

        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(403);
    }
}
