<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\AuditLogCrudController;
use App\Controller\Admin\CategoryCrudController;
use App\Controller\Admin\ClientRequestCrudController;
use App\Controller\Admin\ConversationCrudController;
use App\Controller\Admin\CouponCrudController;
use App\Controller\Admin\InvoiceCrudController;
use App\Controller\Admin\LabelCrudController;
use App\Controller\Admin\LegalPageCrudController;
use App\Controller\Admin\MessageCrudController;
use App\Controller\Admin\PaymentCrudController;
use App\Controller\Admin\PlanPriceCrudController;
use App\Controller\Admin\ProducerProfileCrudController;
use App\Controller\Admin\ProducerReplyCrudController;
use App\Controller\Admin\ProductCrudController;
use App\Controller\Admin\ReviewCrudController;
use App\Controller\Admin\SubscriptionCrudController;
use App\Controller\Admin\SubscriptionPlanCrudController;
use App\Controller\Admin\SupportReplyTemplateCrudController;
use App\Controller\Admin\TicketCrudController;
use App\Controller\Admin\UnitCrudController;
use App\Controller\Admin\UserCrudController;
use App\Controller\Admin\VerificationDocumentCrudController;
use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Teste le RBAC admin (cahier DevOps, "Autorisation : RBAC côté Symfony" ; cahier fonctionnel 22.2, "Le
 * support accède uniquement aux éléments nécessaires"). La partition écran par écran est documentée dans
 * DashboardController::configureMenuItems() et security.yaml (role_hierarchy) -- ce test la vérifie
 * effectivement appliquée, pas seulement masquée dans le menu.
 */
final class RbacTest extends ApiTestCase
{
    use EntityFactoryTrait;

    /** @var list<class-string> */
    private const ADMIN_ONLY_CONTROLLERS = [
        ProducerProfileCrudController::class,
        ClientRequestCrudController::class,
        ProducerReplyCrudController::class,
        ReviewCrudController::class,
        CategoryCrudController::class,
        LabelCrudController::class,
        SubscriptionCrudController::class,
        InvoiceCrudController::class,
        LegalPageCrudController::class,
        VerificationDocumentCrudController::class,
        ProductCrudController::class,
        UnitCrudController::class,
        SubscriptionPlanCrudController::class,
        PlanPriceCrudController::class,
        CouponCrudController::class,
        PaymentCrudController::class,
        AuditLogCrudController::class,
    ];

    /** @var list<class-string> */
    private const SUPPORT_ACCESSIBLE_CONTROLLERS = [
        UserCrudController::class,
        ConversationCrudController::class,
        MessageCrudController::class,
        TicketCrudController::class,
        SupportReplyTemplateCrudController::class,
    ];

    /**
     * @param list<string> $roles
     */
    private function loginAs(string $emailPrefix, array $roles): User
    {
        $user = $this->makeUserWithPassword($emailPrefix, 'motdepasse123');
        $user->setRoles($roles);
        $this->em->flush();

        $this->client->followRedirects(true);
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Se connecter', [
            '_username' => $user->getEmail(),
            '_password' => 'motdepasse123',
        ]);

        return $user;
    }

    private function indexUrl(string $controller): string
    {
        return self::getContainer()->get(AdminUrlGenerator::class)
            ->setController($controller)
            ->setAction(Action::INDEX)
            ->generateUrl();
    }

    public function testSupportOnlyIsForbiddenFromAdminOnlyScreens(): void
    {
        $this->loginAs('support', [User::ROLE_SUPPORT]);

        foreach (self::ADMIN_ONLY_CONTROLLERS as $controller) {
            $this->client->request('GET', $this->indexUrl($controller));
            self::assertResponseStatusCodeSame(403, "ROLE_SUPPORT devrait recevoir 403 sur {$controller}.");
        }
    }

    public function testSupportOnlyCanReachDashboardAndItsOwnScreens(): void
    {
        $this->loginAs('support2', [User::ROLE_SUPPORT]);

        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful('ROLE_SUPPORT devrait accéder au tableau de bord.');

        foreach (self::SUPPORT_ACCESSIBLE_CONTROLLERS as $controller) {
            $this->client->request('GET', $this->indexUrl($controller));
            self::assertResponseIsSuccessful("ROLE_SUPPORT devrait accéder à {$controller}.");
        }
    }

    public function testSupportOnlyIsForbiddenFromReportingAndSettings(): void
    {
        $this->loginAs('support3', [User::ROLE_SUPPORT]);

        $this->client->request('GET', '/admin/reporting');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/settings');
        self::assertResponseStatusCodeSame(403);
    }

    // * role_hierarchy (security.yaml) : ROLE_ADMIN hérite de ROLE_SUPPORT, donc un admin garde accès aux
    // * écrans "support" en plus des siens -- pas une partition étanche des deux côtés.
    public function testAdminCanReachEverythingIncludingSupportScreens(): void
    {
        $this->loginAs('admin', [User::ROLE_ADMIN]);

        foreach ([...self::ADMIN_ONLY_CONTROLLERS, ...self::SUPPORT_ACCESSIBLE_CONTROLLERS] as $controller) {
            $this->client->request('GET', $this->indexUrl($controller));
            self::assertResponseIsSuccessful("ROLE_ADMIN devrait accéder à {$controller}.");
        }

        $this->client->request('GET', '/admin/reporting');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();
    }

    // * Menu : une entrée vers un écran ROLE_ADMIN ne doit même pas apparaître pour Support (sinon lien mort).
    public function testSupportMenuHidesAdminOnlyEntriesButKeepsItsOwn(): void
    {
        $this->loginAs('support4', [User::ROLE_SUPPORT]);

        // * Restreint aux liens du menu latéral (.ea-sidebar-item-link) : le corps de la page (tuiles du
        // * tableau de bord, ex. "Producteurs à valider") contient certains de ces mots aussi, hors de tout
        // * lien de menu -- une simple recherche sur le HTML entier donnerait de faux négatifs.
        $crawler = $this->client->request('GET', '/admin');
        $menuLabels = $crawler->filter('.ea-sidebar-item-link')->each(static fn ($node) => trim($node->text()));

        self::assertContains('Utilisateurs', $menuLabels);
        self::assertContains('Support', $menuLabels);
        self::assertNotContains('Producteurs', $menuLabels);
        self::assertNotContains('Abonnements', $menuLabels);
        self::assertNotContains("Journal d'audit", $menuLabels);
    }
}
