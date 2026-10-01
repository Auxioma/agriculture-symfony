<?php

namespace App\Controller\Admin;

use App\Entity\Identity\User;
use App\Entity\Messaging\Conversation;
use App\Entity\Messaging\Message;
use App\Entity\Trust\ModerationAction;
use App\Entity\Trust\Report;
use App\Enum\ConversationStatus;
use App\Enum\ReportStatus;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use App\Service\Audit\AuditLogger;

class MessageCrudController extends AbstractCrudController
{
    use EagerAssociationJoinTrait;

    /** @var array<string, Report>|null identifiant de conversation => signalement */
    private ?array $reportsByConversationId = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Report est polymorphe (targetType/targetId, pas de relation Doctrine directe avec Conversation) : cette
     * correspondance n'existe nulle part en base sous cette forme -- calculée une seule fois par page (une seule
     * requête, la table est petite), réutilisée par la colonne "Signalement", ses puces et les actions
     * Résoudre/Rejeter. Remplace l'ancien lookup ponctuel par conversation de recordModerationAction().
     *
     * @return array<string, Report>
     */
    private function reportsByConversationId(): array
    {
        if (null !== $this->reportsByConversationId) {
            return $this->reportsByConversationId;
        }

        $map = [];
        foreach ($this->em->getRepository(Report::class)->findBy(['targetType' => 'conversation']) as $report) {
            if (null !== $report->getTargetId()) {
                $map[$report->getTargetId()->toRfc4122()] = $report;
            }
        }

        return $this->reportsByConversationId = $map;
    }

    private function reportFor(Conversation $conversation): ?Report
    {
        return $this->reportsByConversationId()[$conversation->getId()->toRfc4122()] ?? null;
    }

    public static function getEntityFqcn(): string
    {
        return Message::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        // * Pas de setEntityPermission() ici : combiné à une action personnalisée déclarée via #[AdminRoute]
        // * (hideMessage/blockSender ci-dessous), il fait échouer la résolution de l'entité dans cette
        // * version d'EasyAdmin (5.5.1) -- l'entityId du chemin n'est jamais lu, getInstance() renvoie null
        // * quelle que soit l'expression passée (vérifié avec une expression triviale, même résultat).
        // * La protection "consultation limitée aux signalements" est donc assurée par createIndexQueryBuilder()
        // * pour la liste, et par des vérifications manuelles dans detail()/hideMessage()/blockSender().
        return $crud
            ->setEntityLabelInSingular('Message signalé')
            ->setEntityLabelInPlural('Messages signalés')
            ->setPageTitle(Crud::PAGE_INDEX, 'Signalements')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->innerJoin('entity.conversation', 'c')
            ->andWhere('c.status = :reported')
            ->setParameter('reported', ConversationStatus::Reported)
            ->leftJoin('entity.sender', 'sender')->addSelect('sender');

        return $this->joinUserEagerly($qb, 'sender');
    }

    public function detail(AdminContext $context): KeyValueStore|Response
    {
        $message = $context->getEntity()->getInstance();
        $this->assertMessageIsReported($message);
        $this->auditLogger->log('reported_message_viewed', 'messaging', 'messages', $message->getId()->toRfc4122());
        $this->em->flush();

        return parent::detail($context);
    }

    private function assertMessageIsReported(?Message $message): void
    {
        if (null === $message || ConversationStatus::Reported !== $message->getConversation()->getStatus()) {
            throw new AccessDeniedHttpException("Ce message n'est pas signalé.");
        }
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('conversation')->hideOnForm();
        yield AssociationField::new('sender')->formatValue(fn ($v, $e) => $e?->getSender()?->getEmail())->hideOnForm();
        yield TextareaField::new('content')->hideOnForm();
        // * Report::$status (cahier fonctionnel, "Signalements" -- jusqu'ici jamais exposé ni fait évoluer, voir
        // * TODO.md) : champ virtuel, rempli en un seul lot pour toute la page par configureResponseParameters().
        yield Field::new('reportStatus')
            ->setLabel('Signalement')
            ->setVirtual(true)
            ->setSortable(false)
            ->setTemplatePath('admin/field/report_status.html.twig')
            ->onlyOnIndex();
        yield DateTimeField::new('moderatedAt')->hideOnForm();
        yield DateTimeField::new('createdAt')->hideOnForm();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(ReportStatusFilter::new('reportStatus', function (string $status): array {
            return array_keys(array_filter(
                $this->reportsByConversationId(),
                static fn (Report $r) => $r->getStatus()->value === $status,
            ));
        }));
    }

    // * Pas d'EDIT ici : un admin ne réécrit jamais le contenu d'un message, il le masque (hideMessage).
    public function configureActions(Actions $actions): Actions
    {
        $hide = Action::new('hide', 'Masquer')
            ->linkToCrudAction('hideMessage')
            ->displayIf(fn (Message $m) => null === $m->getModeratedAt());

        $block = Action::new('blockSender', "Bloquer l'expéditeur")
            ->linkToCrudAction('blockSender')
            ->displayIf(fn (Message $m) => null !== $m->getSender());

        // * Masquées une fois le signalement Résolu/Rejeté (voir resolveReport()/rejectReport()) : un signalement
        // * déjà tranché n'a plus besoin d'être re-tranché.
        $isDecidable = fn (Message $m): bool => \in_array(
            $this->reportFor($m->getConversation())?->getStatus(),
            [ReportStatus::Open, ReportStatus::InReview],
            true,
        );
        $resolve = Action::new('resolveReport', 'Résoudre')->linkToCrudAction('resolveReport')->displayIf($isDecidable);
        $reject = Action::new('rejectReport', 'Rejeter')->linkToCrudAction('rejectReport')->displayIf($isDecidable);

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, $hide)->add(Crud::PAGE_DETAIL, $hide)
            ->add(Crud::PAGE_INDEX, $block)->add(Crud::PAGE_DETAIL, $block)
            ->add(Crud::PAGE_INDEX, $resolve)->add(Crud::PAGE_DETAIL, $resolve)
            ->add(Crud::PAGE_INDEX, $reject)->add(Crud::PAGE_DETAIL, $reject);
    }

    // * Appelé par EasyAdmin après le chargement de la page (les messages affichés et leurs champs sont déjà
    // * construits) : renseigne la colonne virtuelle "Signalement" de chaque ligne, même principe que
    // * ClientRequestCrudController::configureResponseParameters() pour "Signaux".
    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        foreach ($responseParameters->get('entities') as $entityDto) {
            $message = $entityDto->getInstance();
            if ($message instanceof Message) {
                $entityDto->getFields()->getByProperty('reportStatus')?->setValue($this->reportFor($message->getConversation())?->getStatus());
            }
        }

        return $responseParameters;
    }

    #[AdminRoute(path: '/{entityId}/hide', name: 'hide')]
    public function hideMessage(AdminContext $context, #[CurrentUser] User $admin): Response
    {
        $message = $context->getEntity()->getInstance();
        $this->assertMessageIsReported($message);
        $message->setModeratedAt(new \DateTimeImmutable());
        $this->recordModerationAction($message->getConversation(), $admin, 'hide_message', ['messageId' => $message->getId()->toRfc4122()]);
        $this->auditLogger->log('message_hidden', 'messaging', 'messages', $message->getId()->toRfc4122());

        $this->em->flush();
        $this->addFlash('success', 'Message masqué.');

        return $this->redirectToIndex();
    }

    // * BlockedUser (blocker/blocked) sert au blocage entre pairs (un client bloque un producteur ou
    // * inversement) : sa clé primaire composite exige un blocker non nul, ce qu'un admin n'est pas ici.
    // * Le "blocage" côté modération back-office réutilise donc la suspension déjà en place sur Utilisateurs
    // * (User.status), mécanisme réellement conçu pour une décision administrative sur un compte.
    #[AdminRoute(path: '/{entityId}/block-sender', name: 'block_sender')]
    public function blockSender(AdminContext $context, #[CurrentUser] User $admin): Response
    {
        $message = $context->getEntity()->getInstance();
        $this->assertMessageIsReported($message);
        $sender = $message->getSender();
        $sender->setStatus(UserStatus::Suspended);

        $this->recordModerationAction($message->getConversation(), $admin, 'block_user', ['userId' => $sender->getId()->toRfc4122()]);
        $this->auditLogger->log('user_blocked', 'identity', 'users', $sender->getId()->toRfc4122(), ['status' => 'active'], ['status' => UserStatus::Suspended->value]);

        $this->em->flush();
        $this->addFlash('success', 'Utilisateur suspendu.');

        return $this->redirectToIndex();
    }

    // * ModerationAction.report n'est pas nullable : sans Report retrouvé (ne devrait pas arriver puisque
    // * la liste est déjà filtrée aux conversations signalées), on masque/bloque quand même mais sans trace --
    // * mieux vaut agir sans trace que ne pas agir du tout, mais ce cas ne devrait jamais se produire en pratique.
    // * ModerationAction.report n'est pas nullable : sans Report retrouvé (ne devrait pas arriver puisque la liste
    // * est déjà filtrée aux conversations signalées), on masque/bloque quand même mais sans trace -- mieux vaut
    // * agir sans trace que ne pas agir du tout, mais ce cas ne devrait jamais se produire en pratique.
    private function recordModerationAction(Conversation $conversation, User $admin, string $actionType, array $payload): void
    {
        $report = $this->reportFor($conversation);
        if (null === $report) {
            return;
        }

        // * Une action de modération prouve qu'un admin s'occupe du signalement : le faire passer d'Ouvert à "En
        // * cours" sans bouton dédié, plutôt que de le laisser indéfiniment "Ouvert" jusqu'à Résoudre/Rejeter.
        if (ReportStatus::Open === $report->getStatus()) {
            $report->setStatus(ReportStatus::InReview);
        }

        $action = new ModerationAction();
        $action->setReport($report);
        $action->setAdmin($admin);
        $action->setActionType($actionType);
        $action->setPayload($payload);
        $this->em->persist($action);
    }

    // * "Résoudre"/"Rejeter" (cahier fonctionnel, "Signalements") : tranche le signalement ET clôture sa
    // * conversation dans le même geste -- un signalement tranché n'a plus de raison de rester dans la file des
    // * conversations "Reported" (ConversationCrudController), pas besoin d'un second clic sur cet autre écran.
    /**
     * @param AdminContext<Message> $context
     */
    #[AdminRoute(path: '/{entityId}/resolve-report', name: 'resolve_report')]
    public function resolveReport(AdminContext $context, #[CurrentUser] User $admin): Response
    {
        return $this->decideReport($context, $admin, ReportStatus::Resolved, 'report_resolved', 'Signalement résolu, conversation clôturée.');
    }

    /**
     * @param AdminContext<Message> $context
     */
    #[AdminRoute(path: '/{entityId}/reject-report', name: 'reject_report')]
    public function rejectReport(AdminContext $context, #[CurrentUser] User $admin): Response
    {
        return $this->decideReport($context, $admin, ReportStatus::Rejected, 'report_rejected', 'Signalement rejeté, conversation clôturée.');
    }

    /**
     * @param AdminContext<Message> $context
     */
    private function decideReport(AdminContext $context, User $admin, ReportStatus $newStatus, string $auditAction, string $flashMessage): Response
    {
        $message = $context->getEntity()->getInstance();
        $this->assertMessageIsReported($message);
        $conversation = $message->getConversation();
        $report = $this->reportFor($conversation);
        if (null === $report) {
            throw new NotFoundHttpException('Aucun signalement ne correspond à cette conversation.');
        }

        $previousStatus = $report->getStatus();
        $report->setStatus($newStatus);
        $report->setReviewedBy($admin);
        $conversation->setStatus(ConversationStatus::Closed);

        $this->auditLogger->log(
            $auditAction,
            'trust',
            'reports',
            $report->getId()->toRfc4122(),
            ['status' => $previousStatus->value],
            ['status' => $newStatus->value],
        );
        $this->em->flush();
        $this->addFlash('success', $flashMessage);

        return $this->redirectToIndex();
    }

    private function redirectToIndex(): Response
    {
        return $this->redirect(
            $this->container->get(AdminUrlGenerator::class)
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->unset('entityId')
                ->generateUrl()
        );
    }
}