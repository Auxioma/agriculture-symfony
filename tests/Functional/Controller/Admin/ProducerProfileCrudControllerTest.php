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

    private function urlFor(string $action, string $entityId): string
    {
        return self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(ProducerProfileCrudController::class)
            ->setAction($action)
            ->setEntityId($entityId)
            ->generateUrl();
    }

    public function testValidateActionSetsStatusAndNotifiesOwner(): void
    {
        $this->loginAsAdmin();
        $country = $this->makeCountry();
        $owner = $this->makeUser('producer');
        $producer = $this->makeProducerProfile($owner, $country, VerificationStatus::Pending);
        $this->em->flush();

        // * L'action passée à setAction() doit être le nom de la MÉTHODE liée via linkToCrudAction()
        // * ('validateProducer'), pas la clé de l'Action ('validate') -- EasyAdmin dispatche sur le nom de
        // * méthode ; avec la clé, la requête ne fait rien (200 silencieux vers l'index) sans jamais appeler
        // * le contrôleur, ce qui a fait échouer ce test une première fois de façon très peu explicite.
        $this->client->request('GET', $this->urlFor('validateProducer', $producer->getId()->toRfc4122()));

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

        $this->client->request('GET', $this->urlFor('rejectProducer', $producer->getId()->toRfc4122()));

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

        $this->client->request('GET', $this->urlFor('requestMoreInfo', $producer->getId()->toRfc4122()));

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
