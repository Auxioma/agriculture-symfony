<?php

namespace App\Controller\Admin;

use App\Entity\Identity\User;
use App\Entity\Matching\ClientRequest;
use App\Entity\Matching\RequestEvent;
use App\Enum\RequestStatus;
use App\Service\Audit\AuditLogger;
use App\Service\Matching\RequestQualityAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// * RBAC (cahier DevOps ; cahier fonctionnel 22.2, "Le support accède uniquement aux éléments nécessaires") :
// * pas dans le périmètre nécessaire au support (voir security.yaml pour le détail du mécanisme).
#[IsGranted('ROLE_ADMIN')]
class ClientRequestCrudController extends AbstractCrudController
{
    use StatusBadgeFieldTrait;
    use EagerAssociationJoinTrait;

    // * Extraits en constantes (configureFields() les consommait seules jusqu'ici) : detail() en a aussi besoin,
    // * pour afficher le même badge de statut que la liste.
    private const STATUS_LABELS = [
        RequestStatus::Draft->value => 'Brouillon',
        RequestStatus::Sent->value => 'Envoyée',
        RequestStatus::WaitingReplies->value => 'En attente de réponses',
        RequestStatus::RepliesReceived->value => 'Réponses reçues',
        RequestStatus::ConversationOpen->value => 'Conversation ouverte',
        RequestStatus::DealFound->value => 'Accord trouvé',
        RequestStatus::Expired->value => 'Expirée',
        RequestStatus::Archived->value => 'Archivée',
        RequestStatus::Cancelled->value => 'Annulée',
        RequestStatus::Reported->value => 'Signalée',
    ];
    private const STATUS_VARIANTS = [
        RequestStatus::Draft->value => 'secondary',
        RequestStatus::Sent->value => 'info',
        RequestStatus::WaitingReplies->value => 'warning',
        RequestStatus::RepliesReceived->value => 'success',
        RequestStatus::ConversationOpen->value => 'success',
        RequestStatus::DealFound->value => 'success',
        RequestStatus::Expired->value => 'secondary',
        RequestStatus::Archived->value => 'secondary',
        RequestStatus::Cancelled->value => 'secondary',
        RequestStatus::Reported->value => 'danger',
    ];
    // * Nombre de demandes précédentes du même client montrées sur la page détail (cahier fonctionnel 13, "Demandes" :
    // * détecter des doublons/du spam suppose de voir l'historique récent du client, pas seulement la demande isolée).
    private const RELATED_REQUESTS_LIMIT = 10;
    // * "Marquer comme spam" n'est proposé que si l'un de ces trois signaux est présent -- "duplicate" a son propre
    // * bouton ("Marquer comme doublon"), les deux pouvant être proposés en même temps sur une même demande.
    private const SPAM_SIGNALS = [RequestQualityAnalyzer::SIGNAL_FLOOD, RequestQualityAnalyzer::SIGNAL_LINK, RequestQualityAnalyzer::SIGNAL_MASS_MESSAGE];

    /** @var array<string, list<string>>|null */
    private ?array $flaggedSignals = null;

    // * Injection par constructeur : le container restreint d'un AbstractCrudController ne connaît pas les services
    // * applicatifs (voir ConversationCrudController).
    public function __construct(
        private readonly RequestQualityAnalyzer $analyzer,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Calculé une seule fois par requête : la colonne "Signaux", le compteur de la puce et le filtre en ont besoin.
     *
     * @return array<string, list<string>> identifiant de demande => signaux (doublon, spam...)
     */
    private function flaggedSignals(): array
    {
        return $this->flaggedSignals ??= $this->analyzer->flaggedSignals();
    }

    public static function getEntityFqcn(): string
    {
        return ClientRequest::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Demande')
            ->setEntityLabelInPlural('Demandes')
            ->setPageTitle(Crud::PAGE_INDEX, 'Demandes clients')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    // * client/category/product en hideOnForm : une demande appartient à son auteur, seul le statut est
    // * piloté par l'admin. Pas d'action dédiée comme pour la validation producteur : archivage, spam/doublons
    // * (annulation) et signalement sont juste des valeurs de l'enum RequestStatus, un ChoiceField suffit.
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->hideOnIndex();
        yield AssociationField::new('client')
            ->setLabel('Client')
            ->formatValue(fn ($value, $entity) => $entity?->getClient()?->getEmail())
            ->hideOnForm();
        yield AssociationField::new('category')->setLabel('Catégorie')->hideOnForm();
        yield AssociationField::new('product')->setLabel('Produit')->hideOnForm();
        yield TextField::new('customProduct')->setLabel('Produit libre')->hideOnForm()->hideOnIndex();
        yield ChoiceField::new('needType')->setLabel('Type de besoin')->hideOnForm()->hideOnIndex();
        yield $this->statusBadgeField('status', 'Statut', $pageName, self::STATUS_LABELS, self::STATUS_VARIANTS);
        // * Doublons et spam (cahier fonctionnel 13, "Demandes") : champ virtuel, rempli en un seul lot pour toute la
        // * page par configureResponseParameters() -- pas une requête par ligne.
        yield Field::new('signals')
            ->setLabel('Signaux')
            ->setVirtual(true)
            ->setSortable(false)
            ->setTemplatePath('admin/field/request_signals.html.twig')
            ->onlyOnIndex();
        yield AssociationField::new('country')->setLabel('Pays')->hideOnForm()->hideOnIndex();
        yield TextField::new('city')->setLabel('Ville')->hideOnForm();
        yield TextareaField::new('message')->setLabel('Message')->hideOnIndex();
        yield DateTimeField::new('createdAt')->setLabel('Date')->setFormat('d MMM y')->hideOnForm();
        yield DateTimeField::new('expiresAt')->setLabel('Expire le')->hideOnForm()->hideOnIndex();
    }

    // * User a trois OneToOne inverses (preference/producerProfile/presence) que Doctrine ne peut pas
    // * proxifier paresseusement (pas de FK côté User) : chaque hydratation de client via getClient()?->getEmail()
    // * en formatValue (colonne "client" ci-dessus) forcerait sinon une requête -- et ses 3 LEFT JOIN -- par ligne.
    // * Un leftJoin + addSelect ici les regroupe en une seule requête pour toute la page.
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->leftJoin('entity.client', 'client')->addSelect('client');

        return $this->joinUserEagerly($qb, 'client');
    }

    public function configureFilters(Filters $filters): Filters
    {
        // * EntityFilter explicite + choice_label obligatoire pour category/country : ni Category ni Country
        // * n'ont de __toString(), et le <select> du filtre (Symfony EntityType, non-autocomplete par défaut)
        // * plante sinon en tentant de caster l'entité en chaîne pour l'option affichée -- même mécanisme
        // * que sur les AssociationField de formulaire (CategoryCrudController::parent, etc.).
        // * "status" reste accessible ici aussi via le bouton "+ Filtres" , le cahier fonctionnel demande plusieurs filtres sur les demandes ("Liste,
        // * filtres, statut, doublons, spam..."), pas seulement le statut. TextFilter + setFormType(TextType::class) :
        // * voir le commentaire équivalent sur ProducerProfileCrudController::configureFilters() -- valeur
        // * soumise gardée plate pour que les puces "Toutes/Envoyées/En attente/..." (tm_filter_chips) et le
        // * "+ Filtres" pilotent le même paramètre de requête.
        return $filters
            ->add(TextFilter::new('status')->setFormType(TextType::class))
            // * Puce "À vérifier" : voir FlaggedFilter et RequestQualityAnalyzer (seuils : nos choix, pas ceux du cahier).
            ->add(FlaggedFilter::new('quality', fn (): array => array_keys($this->flaggedSignals())))
            ->add('needType')
            ->add(EntityFilter::new('category')->setFormTypeOption('value_type_options.choice_label', 'name'))
            ->add(EntityFilter::new('country')->setFormTypeOption('value_type_options.choice_label', 'name'));
    }

    // * Page détail sur mesure (cahier fonctionnel 13, "Demandes" : "doublons, spam") : la fiche générique d'EasyAdmin
    // * ne peut pas montrer "pourquoi cette demande est signalée" ni les demandes liées, qui n'existent nulle part en
    // * base (calculées par RequestQualityAnalyzer). Maquette Figma non consultée pour cette page (voir TODO.md).
    public function detail(AdminContext $context): KeyValueStore|Response
    {
        /** @var ClientRequest $request */
        $request = $context->getEntity()->getInstance();
        $id = $request->getId()->toRfc4122();
        $urls = $this->container->get(AdminUrlGenerator::class);

        $signals = $this->analyzer->signalsFor([$id])[$id] ?? [];
        $originalIds = $this->analyzer->duplicatesOf([$id])[$id] ?? [];
        $originals = [] !== $originalIds
            ? $this->em->createQueryBuilder()->select('o')->from(ClientRequest::class, 'o')
                ->andWhere('o.id IN (:ids)')->setParameter('ids', $originalIds)
                ->orderBy('o.createdAt', 'DESC')->getQuery()->getResult()
            : [];

        // * Historique récent du même client (cahier fonctionnel : détecter des doublons/du spam suppose de voir ce
        // * qu'il a envoyé d'autre, pas seulement la demande isolée) -- limité, voir RELATED_REQUESTS_LIMIT.
        $related = $this->em->createQueryBuilder()->select('r')->from(ClientRequest::class, 'r')
            ->andWhere('r.client = :client')->setParameter('client', $request->getClient())
            ->andWhere('r.id != :id')->setParameter('id', $request->getId())
            ->orderBy('r.createdAt', 'DESC')->setMaxResults(self::RELATED_REQUESTS_LIMIT)
            ->getQuery()->getResult();

        // * Twig ne peut pas appeler une closure passée en variable comme une fonction ("detailUrlFor(r)") : une table
        // * identifiant => URL, générée ici pendant que $urls est sous la main, comme TicketCrudController::
        // * attachmentUrls() pour les pièces jointes.
        $detailUrls = [];
        foreach ([...$originals, ...$related] as $r) {
            $detailUrls[$r->getId()->toRfc4122()] = $urls->setController(self::class)->setAction(Action::DETAIL)->setEntityId($r->getId())->generateUrl();
        }

        $isLive = \in_array($request->getStatus()->value, RequestQualityAnalyzer::LIVE_STATUSES, true);

        return $this->render('admin/client_request/detail.html.twig', [
            'clientRequest' => $request,
            'statusLabel' => self::STATUS_LABELS[$request->getStatus()->value] ?? $request->getStatus()->value,
            'statusVariant' => self::STATUS_VARIANTS[$request->getStatus()->value] ?? 'secondary',
            'statusLabels' => self::STATUS_LABELS,
            'statusVariants' => self::STATUS_VARIANTS,
            'signals' => $signals,
            'originals' => $originals,
            'related' => $related,
            'detailUrls' => $detailUrls,
            // * Boutons visibles seulement si le signal correspondant est bien présent ET que la demande est encore en
            // * circulation -- qualityAction() revérifie ces deux conditions côté serveur, un POST direct ne les
            // * contourne donc pas.
            'canMarkSpam' => $isLive && [] !== array_intersect($signals, self::SPAM_SIGNALS),
            'canMarkDuplicate' => $isLive && \in_array(RequestQualityAnalyzer::SIGNAL_DUPLICATE, $signals, true),
            'qualityActionUrl' => $urls->setController(self::class)->setAction('qualityAction')->setEntityId($request->getId())->generateUrl(),
            'indexUrl' => $urls->setController(self::class)->setAction(Action::INDEX)->unset('entityId')->generateUrl(),
            'editUrl' => $urls->setController(self::class)->setAction(Action::EDIT)->setEntityId($request->getId())->generateUrl(),
        ]);
    }

    // * Actions rapides "Marquer comme spam" / "Marquer comme doublon" (cahier fonctionnel 13, "Demandes" : "doublons,
    // * spam"). Annule la demande (RequestStatus::Cancelled -- même levier que le ChoiceField "status" du formulaire
    // * "Modifier", juste sans avoir à connaître/choisir la bonne valeur), journalise un RequestEvent (fil d'activité
    // * propre à la demande, jusqu'ici jamais alimenté -- voir son docblock) et une entrée d'audit (action admin
    // * sensible). Une demande annulée sort de LIVE_STATUSES, donc RequestQualityAnalyzer ne la signale plus : elle
    // * quitte d'elle-même la file "à vérifier" (voir le docblock de la classe), pas besoin de logique dédiée ici.
    /**
     * @param AdminContext<ClientRequest> $context
     */
    #[AdminRoute(path: '/{entityId}/quality-action', name: 'quality_action', options: ['methods' => ['POST']])]
    public function qualityAction(AdminContext $context): Response
    {
        /** @var ClientRequest $request */
        $request = $context->getEntity()->getInstance();
        $httpRequest = $context->getRequest();
        $admin = $this->getUser();
        if (!$admin instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('client_request_quality_action', (string) $httpRequest->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $id = $request->getId()->toRfc4122();
        $signals = $this->analyzer->signalsFor([$id])[$id] ?? [];
        $isLive = \in_array($request->getStatus()->value, RequestQualityAnalyzer::LIVE_STATUSES, true);
        $operation = (string) $httpRequest->request->get('operation');

        if ('mark_spam' === $operation) {
            if (!$isLive) {
                $this->addFlash('danger', 'Seule une demande encore en circulation peut être marquée.');
            } elseif ([] === array_intersect($signals, self::SPAM_SIGNALS)) {
                $this->addFlash('danger', "Cette demande n'a aucun signal de spam.");
            } else {
                $this->applyQualityDecision($request, $admin, 'flagged_spam', 'client_request_marked_spam', $signals);
                $this->addFlash('success', 'Demande marquée comme spam.');
            }
        } elseif ('mark_duplicate' === $operation) {
            if (!$isLive) {
                $this->addFlash('danger', 'Seule une demande encore en circulation peut être marquée.');
            } elseif (!\in_array(RequestQualityAnalyzer::SIGNAL_DUPLICATE, $signals, true)) {
                $this->addFlash('danger', "Cette demande n'est pas signalée comme doublon.");
            } else {
                $this->applyQualityDecision($request, $admin, 'flagged_duplicate', 'client_request_marked_duplicate', $signals);
                $this->addFlash('success', 'Demande marquée comme doublon.');
            }
        } else {
            throw new BadRequestHttpException('Opération inconnue.');
        }

        return $this->redirect(
            $this->container->get(AdminUrlGenerator::class)
                ->setController(self::class)->setAction(Action::DETAIL)->setEntityId($request->getId())->generateUrl()
        );
    }

    /**
     * @param list<string> $signals
     */
    private function applyQualityDecision(ClientRequest $request, User $admin, string $eventType, string $auditAction, array $signals): void
    {
        $previousStatus = $request->getStatus();
        $request->setStatus(RequestStatus::Cancelled);

        $event = new RequestEvent();
        $event->setRequest($request);
        $event->setActor($admin);
        $event->setEventType($eventType);
        $event->setPayload(['signals' => $signals]);
        $this->em->persist($event);

        $this->auditLogger->log(
            $auditAction,
            'matching',
            'client_requests',
            $request->getId()->toRfc4122(),
            ['status' => $previousStatus->value],
            ['status' => RequestStatus::Cancelled->value, 'signals' => $signals],
        );
        $this->em->flush();
    }

    public function configureActions(Actions $actions): Actions
    {
        // * DELETE reste actif ici (contrairement à Utilisateurs/Producteurs) : "suppression" est
        // * explicitement listée dans le CDC pour ce module (nettoyage spam/doublons). Seule la création est
        // * bloquée -- une demande naît du tunnel client, jamais du back-office.
        return $actions->disable(Action::NEW);
    }

    // * Appelé par EasyAdmin après le chargement de la page : seul moment où l'on connaît les demandes affichées ET où
    // * leurs champs sont déjà construits. Renseigne la colonne virtuelle "Signaux" de chaque ligne et le compteur de
    // * la puce "À vérifier" (tm_filter_chips, layout.html.twig, variable tm_flagged_count).
    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $signals = $this->flaggedSignals();
        foreach ($responseParameters->get('entities') as $entityDto) {
            $request = $entityDto->getInstance();
            if ($request instanceof ClientRequest) {
                $entityDto->getFields()->getByProperty('signals')?->setValue($signals[$request->getId()->toRfc4122()] ?? []);
            }
        }
        $responseParameters->set('tm_flagged_count', \count($signals));

        return $responseParameters;
    }
}
