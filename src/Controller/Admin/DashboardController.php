<?php

namespace App\Controller\Admin;

use App\Controller\Admin\CategoryCrudController;
use App\Controller\Admin\ClientRequestCrudController;
use App\Controller\Admin\ConversationCrudController;
use App\Controller\Admin\MessageCrudController;
use App\Controller\Admin\ProducerProfileCrudController;
use App\Controller\Admin\PaymentCrudController;
use App\Controller\Admin\PlanPriceCrudController;
use App\Controller\Admin\ProductCrudController;
use App\Controller\Admin\SubscriptionCrudController;
use App\Controller\Admin\SubscriptionPlanCrudController;
use App\Controller\Admin\UnitCrudController;
use App\Controller\Admin\UserCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        $adminUrlGenerator = $this->container->get(AdminUrlGenerator::class);

        return $this->redirect($adminUrlGenerator->setController(UserCrudController::class)->generateUrl());
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('TrouveMoi Agri — Back-office');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord', 'fa fa-home');
        yield MenuItem::linkTo(UserCrudController::class, 'Utilisateurs', 'fa fa-users');
        yield MenuItem::linkTo(ProducerProfileCrudController::class, 'Validation producteurs', 'fa fa-check-circle');
        yield MenuItem::linkTo(ClientRequestCrudController::class, 'Demandes clients', 'fa fa-inbox');
        yield MenuItem::linkTo(ConversationCrudController::class, 'Conversations signalées', 'fa fa-flag');
        yield MenuItem::linkTo(MessageCrudController::class, 'Messages signalés', 'fa fa-comment-slash');
        yield MenuItem::subMenu('Catalogue', 'fa fa-tags')->setSubItems([
            MenuItem::linkTo(CategoryCrudController::class, 'Catégories', 'fa fa-folder-tree'),
            MenuItem::linkTo(ProductCrudController::class, 'Produits', 'fa fa-carrot'),
            MenuItem::linkTo(UnitCrudController::class, 'Unités', 'fa fa-ruler'),
        ]);
        yield MenuItem::subMenu('Abonnements', 'fa fa-credit-card')->setSubItems([
            MenuItem::linkTo(SubscriptionPlanCrudController::class, 'Plans', 'fa fa-list'),
            MenuItem::linkTo(PlanPriceCrudController::class, 'Prix', 'fa fa-euro-sign'),
            MenuItem::linkTo(SubscriptionCrudController::class, 'Abonnements actifs', 'fa fa-repeat'),
            MenuItem::linkTo(InvoiceCrudController::class, 'Factures', 'fa fa-file-invoice'),
            MenuItem::linkTo(PaymentCrudController::class, 'Paiements', 'fa fa-money-check'),
        ]);
    }
}