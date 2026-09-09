<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\UserCrudController;
use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Teste le module "Utilisateurs" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf :
 * "Recherche, filtres, rôles, suspension, suppression, anonymisation, historique").
 */
final class UserCrudControllerTest extends ApiTestCase
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

    // * Réutilise identity.anonymize_user(), déjà branchée par le RGPD self-service (AuthController::
    // * deleteMyAccount) -- même fonction SQL, déclenchée ici par un admin sur n'importe quel compte.
    public function testAnonymizeActionAnonymizesTargetUser(): void
    {
        $this->loginAsAdmin();
        $target = $this->makeUser('target');
        $this->em->flush();

        $url = self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(UserCrudController::class)
            ->setAction('anonymizeUser')
            ->setEntityId($target->getId())
            ->generateUrl();

        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status, email FROM identity.users WHERE id = :id',
            ['id' => $target->getId()->toRfc4122()]
        );
        self::assertSame('deleted', $row['status']);
        self::assertStringContainsString('@anonymized.local', $row['email']);

        $audit = $this->em->getConnection()->fetchAssociative(
            "SELECT record_id FROM audit.audit_logs WHERE action = 'user_anonymized' AND table_name = 'users'"
        );
        self::assertNotFalse($audit);
        self::assertSame($target->getId()->toRfc4122(), $audit['record_id']);
    }

    // * Vérifie le formulaire d'édition de bout en bout : status et roles sont mappés en ChoiceField (pas
    // * TextField/ArrayField comme dans une première version) précisément parce qu'ils doivent survivre à un
    // * vrai aller-retour de formulaire -- ce test aurait échoué (TypeError sur setStatus()) sans ce choix.
    public function testEditingUserRolesAndStatusSucceeds(): void
    {
        $this->loginAsAdmin();
        $target = $this->makeUser('target');
        $this->em->flush();

        $editUrl = self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(UserCrudController::class)
            ->setAction(Action::EDIT)
            ->setEntityId($target->getId())
            ->generateUrl();

        $crawler = $this->client->request('GET', $editUrl);
        self::assertResponseIsSuccessful();

        // * id="edit-{EntityName}-form" (ici "edit-User-form") identifie sans ambiguïté le vrai formulaire
        // * d'édition, par opposition à la barre de recherche (GET) et à la modale de suppression générique.
        $form = $crawler->filter('form#edit-User-form')->form();
        $values = $form->getPhpValues();
        $rootKey = array_key_first($values);

        $values[$rootKey]['status'] = 'suspended';
        $values[$rootKey]['roles'] = [User::ROLE_CLIENT, User::ROLE_PRODUCER];

        // * loginAsAdmin() a activé followRedirects(true) : la réponse ici est déjà celle de la page suivante
        // * (la liste), le redirect post-sauvegarde ayant été suivi automatiquement -- donc 200, pas 302.
        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());

        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status, roles FROM identity.users WHERE id = :id',
            ['id' => $target->getId()->toRfc4122()]
        );
        self::assertSame('suspended', $row['status']);
        self::assertStringContainsString('ROLE_PRODUCER', $row['roles']);

        // * Couvre updateEntity() : le "blocage compte" doit être journalisé même via le formulaire générique,
        // * pas seulement via MessageCrudController::blockSender().
        $audit = $this->em->getConnection()->fetchAssociative(
            "SELECT old_data, new_data FROM audit.audit_logs WHERE action = 'user_status_changed' AND record_id = :id",
            ['id' => $target->getId()->toRfc4122()]
        );
        self::assertNotFalse($audit);
        self::assertStringContainsString('suspended', $audit['new_data']);
    }

    // * disable(Action::NEW) doit être appliqué côté serveur (ForbiddenActionException), pas seulement
    // * masquer le bouton -- les utilisateurs s'inscrivent via l'API publique, jamais depuis le back-office.
    public function testCreatingUserFromBackofficeIsForbidden(): void
    {
        $this->loginAsAdmin();

        $url = self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(UserCrudController::class)
            ->setAction('new')
            ->generateUrl();

        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(403);
    }
}
