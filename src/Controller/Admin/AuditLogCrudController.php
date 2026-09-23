<?php

namespace App\Controller\Admin;

use App\Entity\Audit\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Écran "Journal d'audit" du back-office (maquette Figma "Admin · Journal d'audit" : "Historique des actions
 * sensibles effectuées sur la plateforme" ; cahier DevOps, sécurité DevSecOps -- "journal d'audit
 * consultable"). Consultation seule des lignes déjà écrites par App\Service\Audit\AuditLogger (huit
 * contrôleurs admin) et par les triggers PostgreSQL trg_*_audit (action = INSERT/UPDATE/DELETE, $actor NULL
 * faute de contexte applicatif -- voir le docblock de AuditLog) : aucune action n'écrit dans cette table
 * depuis l'admin, donc pas de NEW/EDIT/DELETE ici.
 *
 * Absent du menu principal dans la maquette (atteint depuis le tableau de bord par un lien "Retour au tableau
 * de bord", qui n'apparaît nulle part ailleurs dans les écrans fournis) : ajouté sous "Autres" dans notre menu,
 * comme Coupons (même situation -- accessible uniquement par ce biais sinon).
 */
// * RBAC (cahier DevOps ; cahier fonctionnel 22.2, "Le support accède uniquement aux éléments nécessaires") :
// * pas dans le périmètre nécessaire au support (voir security.yaml pour le détail du mécanisme).
#[IsGranted('ROLE_ADMIN')]
class AuditLogCrudController extends AbstractCrudController
{
    // * Actions posées explicitement par AuditLogger (voir les huit contrôleurs qui l'appellent) : libellé
    // * français affiché à la place du code brut.
    private const ACTION_LABELS = [
        'reported_conversation_viewed' => 'Conversation signalée consultée',
        'conversation_reopened' => 'Conversation rouverte',
        'conversation_closed' => 'Conversation clôturée',
        'platform_settings_updated' => 'Paramètres modifiés',
        'reported_message_viewed' => 'Message signalé consulté',
        'message_hidden' => 'Message masqué',
        'user_blocked' => 'Utilisateur bloqué',
        'producer_validated' => 'Producteur validé',
        'producer_rejected' => 'Producteur refusé',
        'producer_more_info_requested' => 'Complément demandé',
        'review_published' => 'Avis publié',
        'review_rejected' => 'Avis rejeté',
        'user_anonymized' => 'Compte anonymisé',
        'user_status_changed' => 'Statut utilisateur modifié',
        'verification_document_approved' => 'Document approuvé',
        'verification_document_rejected' => 'Document rejeté',
    ];

    // * Lignes posées par les triggers PostgreSQL (action = TG_OP brut) : combiné avec le nom de table pour un
    // * libellé du type "Utilisateur modifié" -- voir Version20260901130245 (trg_users_audit et consorts).
    private const DB_ACTION_VERBS = ['INSERT' => 'créé', 'UPDATE' => 'modifié', 'DELETE' => 'supprimé'];
    private const TABLE_LABELS = [
        'users' => 'Utilisateur',
        'producer_profiles' => 'Producteur',
        'subscriptions' => 'Abonnement',
        'verification_documents' => 'Document justificatif',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public static function getEntityFqcn(): string
    {
        return AuditLog::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Entrée du journal')
            ->setEntityLabelInPlural("Journal d'audit")
            ->setPageTitle(Crud::PAGE_INDEX, "Journal d'audit")
            ->setPageTitle(Crud::PAGE_DETAIL, "Détail de l'entrée")
            ->setSearchFields(['action', 'tableName', 'actor.email'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        $isDetail = Crud::PAGE_DETAIL === $pageName;

        yield IdField::new('id')->hideOnIndex();
        yield TextField::new('action')->setLabel('Action')->formatValue(fn ($v, AuditLog $log) => $this->describeAction($log));
        yield AssociationField::new('actor')->setLabel('Acteur')->formatValue(fn ($v, AuditLog $log) => $log->getActor()?->getEmail() ?? 'Système');
        yield TextField::new('recordId')->setLabel('Cible')->formatValue(fn ($v, AuditLog $log) => $this->describeTarget($log));
        yield DateTimeField::new('createdAt')->setLabel('Date')->setFormat('d MMM y, HH:mm');
        yield TextField::new('ipAddress')
            ->setLabel('IP')
            ->formatValue(fn ($v) => $this->maskIp($v))
            ->setTemplatePath('admin/field/masked_text.html.twig');

        if ($isDetail) {
            yield TextField::new('schemaName')->setLabel('Schéma');
            yield TextField::new('tableName')->setLabel('Table');
            yield ArrayField::new('oldData')->setLabel('Avant')->setTemplatePath('admin/field/pretty_json.html.twig');
            yield ArrayField::new('newData')->setLabel('Après')->setTemplatePath('admin/field/pretty_json.html.twig');
        }
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(InFilter::new('schemaName'));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::DETAIL, static fn (Action $action) => $action->setLabel('Voir'));
    }

    private function describeAction(AuditLog $log): string
    {
        $action = $log->getAction();
        if (null === $action) {
            return '—';
        }

        if (isset(self::ACTION_LABELS[$action])) {
            return self::ACTION_LABELS[$action];
        }

        $verb = self::DB_ACTION_VERBS[$action] ?? null;
        $table = self::TABLE_LABELS[$log->getTableName() ?? ''] ?? null;
        if (null !== $verb && null !== $table) {
            return sprintf('%s %s', $table, $verb);
        }

        return $action;
    }

    // * Aucune association réelle entre AuditLog et sa cible ($recordId est du texte libre, pas une FK) :
    // * une petite requête SQL par table connue, comme DashboardController::reporting(). Une cible supprimée
    // * depuis (fetchOne ne trouve plus rien) retombe sur les 8 premiers caractères de son identifiant.
    private function describeTarget(AuditLog $log): string
    {
        $recordId = $log->getRecordId();
        if (null === $recordId) {
            return '—';
        }

        $connection = $this->em->getConnection();
        $label = match ($log->getTableName()) {
            'users' => $connection->fetchOne('SELECT email FROM identity.users WHERE id = :id', ['id' => $recordId]),
            'producer_profiles' => $connection->fetchOne('SELECT farm_name FROM producer.producer_profiles WHERE id = :id', ['id' => $recordId]),
            'subscriptions' => $connection->fetchOne(
                'SELECT p.farm_name FROM billing.subscriptions s JOIN producer.producer_profiles p ON p.id = s.producer_id WHERE s.id = :id',
                ['id' => $recordId]
            ),
            'verification_documents' => $connection->fetchOne(
                'SELECT p.farm_name FROM trust.verification_documents d JOIN producer.producer_profiles p ON p.id = d.producer_id WHERE d.id = :id',
                ['id' => $recordId]
            ),
            'reviews' => $connection->fetchOne(
                'SELECT p.farm_name FROM trust.reviews r JOIN producer.producer_profiles p ON p.id = r.producer_id WHERE r.id = :id',
                ['id' => $recordId]
            ),
            'platform_settings' => 'Paramètres de la plateforme',
            'conversations' => 'Conversation #'.substr($recordId, 0, 8),
            'messages' => 'Message #'.substr($recordId, 0, 8),
            default => null,
        };

        if (\is_string($label) && '' !== $label) {
            return $label;
        }

        return substr($recordId, 0, 8);
    }

    // * "82.14.xx.xx" (RGPD, cahier DevOps : minimiser les données personnelles affichées) : seuls les deux
    // * premiers groupes d'une IPv4 restent visibles, le reste masqué. IPv6 : mêmes deux premiers groupes.
    private function maskIp(?string $ip): string
    {
        if (null === $ip || '' === $ip) {
            return '—';
        }

        $separator = str_contains($ip, ':') ? ':' : '.';
        $parts = explode($separator, $ip);
        $visible = \array_slice($parts, 0, 2);
        $masked = array_fill(0, \count($parts) - \count($visible), 'xx');

        return implode($separator, [...$visible, ...$masked]);
    }
}
