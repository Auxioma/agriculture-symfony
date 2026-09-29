<?php

namespace App\Controller\Admin;

use App\Entity\Audit\AuditLog;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
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
// * pas dans le périmètre nécessaire au support (voir security.yaml pour le détail du mécanisme)
#[IsGranted('ROLE_ADMIN')]
class AuditLogCrudController extends AbstractCrudController
{
    use EagerAssociationJoinTrait;

    // * Actions posées explicitement par AuditLogger (voir les huit contrôleurs qui l'appellent) : libellé
    // * français affiché à la place du code brut
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
        'admin_2fa_enabled' => 'Double authentification activée',
        'admin_2fa_reset' => 'Double authentification réinitialisée',
        'ticket_replied' => 'Réponse à un ticket',
        'ticket_status_changed' => 'Statut de ticket modifié',
        'ticket_assigned' => 'Ticket assigné',
        'ticket_priority_changed' => 'Priorité de ticket modifiée',
        'client_request_marked_spam' => 'Demande marquée comme spam',
        'client_request_marked_duplicate' => 'Demande marquée comme doublon',
        'report_resolved' => 'Signalement résolu',
        'report_rejected' => 'Signalement rejeté',
    ];

    // * Lignes posées par les triggers PostgreSQL (action = TG_OP brut) : combiné avec le nom de table pour un
    // * libellé du type "Utilisateur modifié" -- voir Version20260901130245 (trg_users_audit et consorts)
    private const DB_ACTION_VERBS = ['INSERT' => 'créé', 'UPDATE' => 'modifié', 'DELETE' => 'supprimé'];
    private const TABLE_LABELS = [
        'users' => 'Utilisateur',
        'producer_profiles' => 'Producteur',
        'subscriptions' => 'Abonnement',
        'verification_documents' => 'Document justificatif',
    ];

    // * Tables pour lesquelles la cible a besoin d'une requête (les autres -- platform_settings, conversations,
    // * messages -- sont de simples libellés calculés, sans accès base) : voir resolveTargetLabel()
    private const LOOKUP_TABLES = ['users', 'producer_profiles', 'subscriptions', 'verification_documents', 'reviews'];

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
        // * formatValue tourne dès la CONSTRUCTION du champ (avant configureResponseParameters(), qui n'a donc
        // * pas encore eu la chance de regrouper les requêtes) : sur la liste, "Cible" doit rester un champ
        // * virtuel sans formatValue -- comme "signals"/"reportStatus" -- pour que la valeur ne soit posée
        // * qu'une fois, par lot, dans configureResponseParameters(). La page détail (une seule ligne, pas
        // * d'enjeu N+1) garde le formatValue direct sur recordId. setTemplatePath() sur un chemin qui n'est PAS
        // * un template EasyAdmin natif : sinon, tant que le champ vaut encore null à la construction (avant
        // * configureResponseParameters()), CommonPostConfigurator le bascule définitivement sur le template
        // * "Aucun(e)" du bundle, qui ignore ensuite toute valeur posée après coup (voir plain_text.html.twig)
        if ($isDetail) {
            yield TextField::new('recordId')->setLabel('Cible')->formatValue(fn ($v, AuditLog $log) => $this->describeTarget($log));
        } else {
            yield Field::new('targetLabel')->setLabel('Cible')->setVirtual(true)->setSortable(false)->setTemplatePath('admin/field/plain_text.html.twig')->onlyOnIndex();
        }
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

    // * actor force sinon une requête (et ses joints eager, voir User) par ligne pour le champ "Acteur" ci-dessus
    // * (même situation que ClientRequestCrudController::createIndexQueryBuilder())
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->leftJoin('entity.actor', 'actor')->addSelect('actor');

        return $this->joinUserEagerly($qb, 'actor');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(InFilter::new('schemaName'));
    }

    // * Appelé par EasyAdmin après le chargement de la page ET la construction des champs (voir
    // * MessageCrudController::configureResponseParameters() pour le même principe) : c'est justement pour ça
    // * que "targetLabel" doit être virtuel et sans formatValue (voir configureFields()) -- un formatValue sur
    // * recordId aurait déjà tourné, une fois par ligne, avant que cette méthode n'ait la moindre chance de
    // * regrouper les requêtes. On ne fait plus ici qu'une requête IN (:ids) par table présente sur la page
    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $idsByTable = [];
        foreach ($responseParameters->get('entities') as $entityDto) {
            $log = $entityDto->getInstance();
            if ($log instanceof AuditLog && \in_array($log->getTableName(), self::LOOKUP_TABLES, true) && null !== $log->getRecordId()) {
                $idsByTable[$log->getTableName()][$log->getRecordId()] = true;
            }
        }

        $labelsByTable = [];
        foreach ($idsByTable as $table => $ids) {
            $labelsByTable[$table] = $this->fetchLabelsForIds($table, array_keys($ids));
        }

        foreach ($responseParameters->get('entities') as $entityDto) {
            $log = $entityDto->getInstance();
            if ($log instanceof AuditLog) {
                $recordId = $log->getRecordId();
                $lookedUpLabel = null !== $recordId ? ($labelsByTable[$log->getTableName() ?? ''][$recordId] ?? null) : null;
                $entityDto->getFields()->getByProperty('targetLabel')?->setValue($this->resolveTargetLabel($log, $lookedUpLabel));
            }
        }

        return $responseParameters;
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

    // * Aucune association réelle entre AuditLog et sa cible ($recordId est du texte libre, pas une FK) : sur
    // * la page détail (une seule ligne, pas d'enjeu N+1, seul appelant de cette méthode -- voir configureFields())
    // * on interroge directement. La liste passe par le lot de configureResponseParameters()
    private function describeTarget(AuditLog $log): string
    {
        $recordId = $log->getRecordId();
        if (null === $recordId) {
            return '—';
        }

        $lookedUpLabel = \in_array($log->getTableName(), self::LOOKUP_TABLES, true)
            ? $this->fetchLabelsForIds($log->getTableName(), [$recordId])[$recordId] ?? null
            : null;

        return $this->resolveTargetLabel($log, $lookedUpLabel);
    }

    // * Une cible supprimée depuis (aucune ligne trouvée) ou une table hors LOOKUP_TABLES retombe sur les 8
    // * premiers caractères de l'identifiant
    private function resolveTargetLabel(AuditLog $log, ?string $lookedUpLabel): string
    {
        $recordId = $log->getRecordId();
        if (null === $recordId) {
            return '—';
        }

        $label = match ($log->getTableName()) {
            'users', 'producer_profiles', 'subscriptions', 'verification_documents', 'reviews' => $lookedUpLabel,
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

    // * Une requête IN (:ids) par table connue, quel que soit le nombre de $ids -- remplace les fetchOne()
    // * individuels de l'ancienne describeTarget(), comme DashboardController::reporting() pour l'esprit.
    // * $table vient toujours de self::LOOKUP_TABLES (voir les deux appelants) : le default du match() ne sert
    // * qu'à documenter l'invariant pour PHPStan, il ne devrait jamais s'exécuter
    /**
     * @param string[] $ids
     *
     * @return array<string, string>
     */
    private function fetchLabelsForIds(string $table, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $connection = $this->em->getConnection();
        $sql = match ($table) {
            'users' => 'SELECT id, email AS label FROM identity.users WHERE id IN (:ids)',
            'producer_profiles' => 'SELECT id, farm_name AS label FROM producer.producer_profiles WHERE id IN (:ids)',
            'subscriptions' => 'SELECT s.id AS id, p.farm_name AS label FROM billing.subscriptions s JOIN producer.producer_profiles p ON p.id = s.producer_id WHERE s.id IN (:ids)',
            'verification_documents' => 'SELECT d.id AS id, p.farm_name AS label FROM trust.verification_documents d JOIN producer.producer_profiles p ON p.id = d.producer_id WHERE d.id IN (:ids)',
            'reviews' => 'SELECT r.id AS id, p.farm_name AS label FROM trust.reviews r JOIN producer.producer_profiles p ON p.id = r.producer_id WHERE r.id IN (:ids)',
            default => throw new \LogicException(\sprintf('Table "%s" hors self::LOOKUP_TABLES.', $table)),
        };

        $rows = $connection->fetchAllAssociative($sql, ['ids' => $ids], ['ids' => ArrayParameterType::STRING]);

        $labels = [];
        foreach ($rows as $row) {
            $labels[$row['id']] = $row['label'];
        }

        return $labels;
    }

    // * "82.14.xx.xx" (RGPD, cahier DevOps : minimiser les données personnelles affichées) : seuls les deux
    // * premiers groupes d'une IPv4 restent visibles, le reste masqué. IPv6: mêmes deux premiers groupes
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
