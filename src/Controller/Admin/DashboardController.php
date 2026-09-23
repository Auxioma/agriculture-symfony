<?php

namespace App\Controller\Admin;

use App\Controller\Admin\AuditLogCrudController;
use App\Controller\Admin\CategoryCrudController;
use App\Controller\Admin\ClientRequestCrudController;
use App\Controller\Admin\ConversationCrudController;
use App\Controller\Admin\CouponCrudController;
use App\Controller\Admin\LegalPageCrudController;
use App\Controller\Admin\MessageCrudController;
use App\Controller\Admin\ProducerProfileCrudController;
use App\Controller\Admin\PaymentCrudController;
use App\Controller\Admin\PlanPriceCrudController;
use App\Controller\Admin\ProductCrudController;
use App\Controller\Admin\ReviewCrudController;
use App\Controller\Admin\SubscriptionCrudController;
use App\Controller\Admin\SubscriptionPlanCrudController;
use App\Controller\Admin\SupportReplyTemplateCrudController;
use App\Controller\Admin\UnitCrudController;
use App\Controller\Admin\UserCrudController;
use App\Controller\Admin\VerificationDocumentCrudController;
use App\Entity\Catalog\Currency;
use App\Form\Admin\PlatformSettingsType;
use App\Service\Audit\AuditLogger;
use App\Service\Platform\PlatformSettings;
use Doctrine\DBAL\Connection;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Theme;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Doctrine\ORM\EntityManagerInterface;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    // * index() vient de DashboardControllerInterface avec une signature figée (aucun paramètre) : impossible
    // * d'y injecter EntityManagerInterface via un argument de méthode comme sur reporting(). Le container
    // * exposé par AbstractDashboardController ($this->container) est un service locator restreint qui ne
    // * connaît qu'un sous-ensemble de services (AdminUrlGenerator y est, EntityManagerInterface non -- testé
    // * en navigateur, erreur "not found ... smaller service locator") : l'injection par constructeur reste
    // * le seul moyen fiable ici, même pattern que Conversation/MessageCrudController::detail().
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Module "Tableau de bord" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf :
     * "Utilisateurs, producteurs, demandes, conversations, revenus, signalements, tickets support").
     * Remplace l'ancien redirect vers Utilisateurs par une vraie vue d'ensemble.
     */
    public function index(): Response
    {
        $connection = $this->em->getConnection();

        $stats = [
            'activeUsers' => (int) $connection->fetchOne("SELECT count(*) FROM identity.users WHERE status = 'active'"),
            'subscribedProducers' => (int) $connection->fetchOne("SELECT count(DISTINCT producer_id) FROM billing.subscriptions WHERE status = 'active'"),
            'producersToValidate' => (int) $connection->fetchOne("SELECT count(*) FROM producer.producer_profiles WHERE verification_status = 'pending'"),
            'requestsThisWeek' => (int) $connection->fetchOne("SELECT count(*) FROM matching.client_requests WHERE created_at >= now() - interval '7 days'"),
            'openReports' => (int) $connection->fetchOne("SELECT count(*) FROM trust.reports WHERE status = 'open'"),
            // * Revenus du mois en cours, pas un cumul historique -- Statistiques couvre déjà la vue détaillée/dans
            // * le temps, le dashboard doit rester un instantané "que se passe-t-il maintenant".
            'revenueThisMonth' => (float) $connection->fetchOne(
                "SELECT COALESCE(SUM(amount), 0) FROM billing.invoices WHERE status = 'paid' AND paid_at >= date_trunc('month', now())"
            ),
        ];

        return $this->render('admin/dashboard.html.twig', [
            'stats' => $stats,
            'recentActivity' => $this->recentActivity($connection),
        ]);
    }

    /**
     * Les 5 derniers évènements notables de la plateforme (inscriptions, signalements, abonnements,
     * tickets), fusionnés en une seule liste triée par date -- volontairement pas le AuditLog : celui-ci trace
     * des actions d'administration, pas l'activité des utilisateurs.
     *
     * @return list<array{event: string, actor: string, when: string, kind: string, kindLabel: string}>
     */
    private function recentActivity(Connection $connection): array
    {
        $rows = $connection->fetchAllAssociative(
            "SELECT * FROM (
                SELECT 'Nouvelle inscription producteur' AS event, p.farm_name AS actor, u.created_at AS occurred_at, 'producer' AS kind
                FROM producer.producer_profiles p JOIN identity.users u ON u.id = p.owner_user_id
                UNION ALL
                SELECT 'Nouvelle inscription client', trim(coalesce(u.first_name, '') || ' ' || coalesce(u.last_name, '')), u.created_at, 'client'
                FROM identity.users u
                WHERE u.roles::text LIKE '%ROLE_CLIENT%' AND NOT EXISTS (SELECT 1 FROM producer.producer_profiles p WHERE p.owner_user_id = u.id)
                UNION ALL
                SELECT 'Signalement reçu', trim(coalesce(u.first_name, '') || ' ' || coalesce(u.last_name, '')), r.created_at, 'report'
                FROM trust.reports r JOIN identity.users u ON u.id = r.reporter_id
                UNION ALL
                SELECT 'Nouvel abonnement ' || sp.name, pp.farm_name, s.created_at, 'subscription'
                FROM billing.subscriptions s
                JOIN billing.plan_prices pr ON pr.id = s.plan_price_id
                JOIN billing.subscription_plans sp ON sp.id = pr.plan_id
                JOIN producer.producer_profiles pp ON pp.id = s.producer_id
                UNION ALL
                SELECT 'Nouveau ticket support', trim(coalesce(u.first_name, '') || ' ' || coalesce(u.last_name, '')), t.created_at, 'support'
                FROM support.tickets t JOIN identity.users u ON u.id = t.user_id
            ) activity
            ORDER BY occurred_at DESC
            LIMIT 5"
        );

        $kindLabels = ['producer' => 'Producteur', 'client' => 'Client', 'report' => 'Signalement', 'subscription' => 'Abonnement', 'support' => 'Support'];

        return array_map(fn (array $row) => [
            'event' => $row['event'],
            'actor' => '' !== $row['actor'] ? $row['actor'] : '—',
            'when' => $this->formatWhen(new \DateTimeImmutable($row['occurred_at'])),
            'kind' => $row['kind'],
            'kindLabel' => $kindLabels[$row['kind']],
        ], $rows);
    }

    private function formatWhen(\DateTimeImmutable $date): string
    {
        $daysAgo = (int) (new \DateTimeImmutable('today'))->diff($date->setTime(0, 0))->format('%r%a');
        if (0 === $daysAgo) {
            return "Aujourd'hui, ".$date->format('H:i');
        }
        if (-1 === $daysAgo) {
            return 'Hier, '.$date->format('H:i');
        }

        $months = [1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

        return $date->format('j').' '.$months[(int) $date->format('n')].' '.$date->format('Y');
    }

    // * Maquette Figma "Trouve Moi Agri" (sections Desktop/Mobile Admin, validée par le client) : thème clair
    // * uniquement, contenu pleine largeur, styles dans assets/styles/admin.css (layer non-EasyAdmin, donc
    // * prioritaire sur les tokens du bundle sans !important).
    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<span class="tm-logo"><img class="tm-logo-img" src="/img/logo.png" alt="TrouveMoi Agri" onerror="this.parentNode.classList.add(\'no-logo\');this.remove()"><span class="tm-logo-text">TrouveMoi Admin</span></span>')
            ->disableDarkMode()
            ->setDefaultColorScheme('light')
            ->renderContentMaximized()
            ->setTheme(Theme::new()->primaryColor('#42750c'));
    }

    public function configureAssets(): Assets
    {
        return Assets::new()
            ->addHtmlContentToHead('<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">')
            ->addCssFile('styles/admin.css');
    }

    // * Ordre et libellés de la maquette. "Réponses & devis", "Labels" et "Paramètres" y figurent aussi mais n'ont
    // * pas encore d'écran côté Symfony (pas de CRUD ProducerReply/Label ni de stockage de paramètres) -- absents
    // * du menu plutôt que des liens morts. Les écrans existants que la maquette ne place pas dans la barre latérale
    // * (produits, unités, plans, prix, coupons...) restent accessibles dans la section "Autres".
    //
    // * RBAC (cahier fonctionnel 22.2, "Le support accède uniquement aux éléments nécessaires") : ->setPermission()
    // * masque l'entrée de menu à un compte Support seul (elle resterait un lien mort sinon, le contrôleur visé
    // * étant lui-même protégé par #[IsGranted('ROLE_ADMIN')]) -- même partition que dans security.yaml/les
    // * contrôleurs. Restent visibles à Support : Tableau de bord, Utilisateurs, Conversations, Signalements,
    // * Support, Modèles de réponse.
    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord', 'fas fa-house');
        yield MenuItem::linkTo(UserCrudController::class, 'Utilisateurs', 'far fa-user');
        yield MenuItem::linkTo(ProducerProfileCrudController::class, 'Producteurs', 'fas fa-leaf')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(ClientRequestCrudController::class, 'Demandes', 'fas fa-bars-staggered')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(ProducerReplyCrudController::class, 'Réponses & devis', 'fas fa-file-invoice')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(ConversationCrudController::class, 'Conversations', 'far fa-comment');
        yield MenuItem::linkTo(MessageCrudController::class, 'Signalements', 'far fa-flag');
        yield MenuItem::linkTo(ReviewCrudController::class, 'Avis', 'far fa-star')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(CategoryCrudController::class, 'Catégories', 'fas fa-table-cells-large')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(LabelCrudController::class, 'Labels', 'fas fa-certificate')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(SubscriptionCrudController::class, 'Abonnements', 'far fa-credit-card')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(InvoiceCrudController::class, 'Paiements & factures', 'fas fa-receipt')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(TicketCrudController::class, 'Support', 'far fa-circle-question');
        yield MenuItem::linkTo(LegalPageCrudController::class, 'Pages légales', 'far fa-file')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkToRoute('Statistiques', 'fas fa-chart-simple', 'admin_reporting')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkToRoute('Paramètres', 'fas fa-sliders', 'admin_settings')->setPermission('ROLE_ADMIN');

        yield MenuItem::section('Autres');
        yield MenuItem::linkTo(VerificationDocumentCrudController::class, 'Documents justificatifs', 'fas fa-file-shield')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(ProductCrudController::class, 'Produits', 'fas fa-carrot')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(UnitCrudController::class, 'Unités', 'fas fa-ruler')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(SubscriptionPlanCrudController::class, 'Plans', 'fas fa-list')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(PlanPriceCrudController::class, 'Prix', 'fas fa-euro-sign')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(CouponCrudController::class, 'Coupons', 'fas fa-tag')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(PaymentCrudController::class, 'Paiements', 'fas fa-money-check')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(SupportReplyTemplateCrudController::class, 'Modèles de réponse', 'far fa-comment-dots');
        // * Absent de la barre latérale dans la maquette (atteint depuis le tableau de bord par un lien qui
        // * n'apparaît nulle part ailleurs) : sous "Autres", comme Coupons -- même situation.
        yield MenuItem::linkTo(AuditLogCrudController::class, "Journal d'audit", 'fas fa-clipboard-list')->setPermission('ROLE_ADMIN');
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

    // * RBAC (cahier DevOps ; cahier fonctionnel 22.2, "Le support accède uniquement aux éléments nécessaires") :
    // * les statistiques (revenus compris) ne sont pas dans le périmètre nécessaire au support.
    #[AdminRoute(path: '/reporting', name: 'reporting')]
    #[IsGranted('ROLE_ADMIN')]
    public function reporting(): Response
    {
        $connection = $this->em->getConnection();

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

    /**
     * Écran "Paramètres de la plateforme" (maquette Figma "Admin · Paramètres" : général et sécurité). Lecture et
     * écriture passent par PlatformSettings ; chaque enregistrement est journalisé (paramètres = action admin
     * sensible) avec les valeurs avant/après.
     */
    // * RBAC (cahier DevOps ; cahier fonctionnel 22.2, "Le support accède uniquement aux éléments nécessaires") :
    // * les paramètres de la plateforme ne sont pas dans le périmètre nécessaire au support.
    #[AdminRoute(path: '/settings', name: 'settings')]
    #[IsGranted('ROLE_ADMIN')]
    public function settings(Request $request, PlatformSettings $settings, AuditLogger $auditLogger): Response
    {
        $current = $settings->all();

        $currencies = [];
        foreach ($this->em->getRepository(Currency::class)->findBy(['isActive' => true], ['code' => 'ASC']) as $currency) {
            $currencies[$currency->getCode()] = null !== $currency->getSymbol()
                ? sprintf('%s (%s)', $currency->getCode(), $currency->getSymbol())
                : $currency->getCode();
        }
        // * La devise enregistrée reste sélectionnable même si elle a été désactivée depuis.
        $currencies += [$current[PlatformSettings::DEFAULT_CURRENCY] => $current[PlatformSettings::DEFAULT_CURRENCY]];

        $current[PlatformSettings::SESSION_MINUTES] = (int) $current[PlatformSettings::SESSION_MINUTES];
        $form = $this->createForm(PlatformSettingsType::class, $current, ['currencies' => $currencies]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $submitted = $form->getData();
            $before = $settings->all();
            $settings->save($submitted);
            $auditLogger->log('platform_settings_updated', 'content', 'platform_settings', 'global', $before, array_map('strval', $submitted));
            $this->em->flush();
            $this->addFlash('success', 'Paramètres enregistrés.');

            return $this->redirectToRoute('admin_settings');
        }

        return $this->render('admin/settings.html.twig', ['form' => $form]);
    }
}