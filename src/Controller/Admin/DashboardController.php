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
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Doctrine\ORM\EntityManagerInterface;

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
        yield MenuItem::linkTo(TicketCrudController::class, 'Support', 'fa fa-headset');
        yield MenuItem::linkToRoute('Reporting', 'fa fa-chart-line', 'admin_reporting');
    }

    /**
     * Module "Reporting" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf :
     * "Demandes par région, taux de réponse, churn, revenus, catégories populaires").
     *
     * Pas de CRUD EasyAdmin ici : ce sont des agrégats cross-tables (client_requests, producer_replies,
     * subscriptions, invoices, categories), pas l'édition d'une entité. "Région" n'existe pas dans le
     * schéma (seulement country, clé naturelle) : le regroupement se fait par pays.
     *
     * Le churn est calculé en cumul global (annulés / total abonnements) faute de date d'annulation dans
     * Subscription -- pas un taux mensuel par cohorte, qui demanderait un champ supplémentaire.
     */

    #[AdminRoute(path: '/reporting', name: 'reporting')]
    public function reporting(EntityManagerInterface $em): Response
    {
        $connection = $em->getConnection();

        $requestsByCountry = $connection->fetchAllAssociative(
            'SELECT co.code, co.name, COUNT(cr.id) AS total
             FROM matching.client_requests cr
             JOIN catalog.countries co ON co.code = cr.country_code
             GROUP BY co.code, co.name
             ORDER BY total DESC'
        );

        $responseRateRow = $connection->fetchAssociative(
            "SELECT
                COUNT(DISTINCT cr.id) FILTER (WHERE pr.id IS NOT NULL) AS with_reply,
                COUNT(DISTINCT cr.id) AS total
             FROM matching.client_requests cr
             LEFT JOIN matching.producer_replies pr ON pr.request_id = cr.id AND pr.status != 'draft'
             WHERE cr.status != 'draft'"
        );
        $responseRate = $responseRateRow['total'] > 0
            ? round(100 * $responseRateRow['with_reply'] / $responseRateRow['total'], 1)
            : null;

        $churnRow = $connection->fetchAssociative(
            "SELECT
                COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled,
                COUNT(*) AS total
             FROM billing.subscriptions"
        );
        $churnRate = $churnRow['total'] > 0
            ? round(100 * $churnRow['cancelled'] / $churnRow['total'], 1)
            : null;

        $totalRevenue = $connection->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) FROM billing.invoices WHERE status = 'paid'"
        );

        $revenueByMonth = $connection->fetchAllAssociative(
            "SELECT to_char(date_trunc('month', paid_at), 'YYYY-MM') AS month, SUM(amount) AS total
             FROM billing.invoices
             WHERE status = 'paid' AND paid_at >= now() - interval '6 months'
             GROUP BY month
             ORDER BY month"
        );

        $popularCategories = $connection->fetchAllAssociative(
            'SELECT c.id, c.name, COUNT(*) AS total
             FROM matching.client_requests cr
             LEFT JOIN catalog.products p ON p.id = cr.product_id
             JOIN catalog.categories c ON c.id = COALESCE(cr.category_id, p.category_id)
             GROUP BY c.id, c.name
             ORDER BY total DESC
             LIMIT 10'
        );

        return $this->render('admin/reporting.html.twig', [
            'requestsByCountry' => $requestsByCountry,
            'responseRate' => $responseRate,
            'responseRateRow' => $responseRateRow,
            'churnRate' => $churnRate,
            'churnRow' => $churnRow,
            'totalRevenue' => $totalRevenue,
            'revenueByMonth' => $revenueByMonth,
            'popularCategories' => $popularCategories,
        ]);
    }
}