<?php

namespace App\Controller\Admin;

use App\Entity\Identity\User;
use App\Entity\Support\Ticket;
use App\Entity\Support\TicketAttachment;
use App\Entity\Support\TicketMessage;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Module "Support" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf :
 * "Tickets, priorités, assignation, modèles de réponse, pièces jointes").
 *
 * "Modèles de réponse" : voir SupportReplyTemplateCrudController (module séparé) -- distinct de
 * QuickReply, qui appartient à un producteur précis pour sa messagerie client.
 *
 * Ticket.status/priority sont de simples chaînes (pas d'enum en base) : les choix ci-dessous sont posés au
 * niveau du formulaire seulement, aucune migration nécessaire.
 */

class TicketCrudController extends AbstractCrudController
{
    use StatusBadgeFieldTrait;

    private const STATUSES = ['Ouvert' => 'open', 'En cours' => 'in_progress', 'Résolu' => 'resolved', 'Fermé' => 'closed'];
    // * Niveaux de gravité repris du cahier DevOps ("Support et astreinte").
    private const PRIORITIES = ['Basse' => 'basse', 'Moyenne' => 'moyenne', 'Haute' => 'haute', 'Critique' => 'critique'];
    private const STATUS_VARIANTS = ['open' => 'warning', 'in_progress' => 'warning', 'resolved' => 'success', 'closed' => 'secondary'];
    private const PRIORITY_VARIANTS = ['basse' => 'secondary', 'moyenne' => 'secondary', 'haute' => 'warning', 'critique' => 'danger'];

    // * Injection par constructeur (comme ConversationCrudController) : le container restreint d'un
    // * AbstractCrudController ne connaît pas EntityManagerInterface. Le stockage des pièces jointes est le
    // * même que celui de TicketController (API) -- l'admin lit ce que l'utilisateur a téléversé.
    // * Même plafond que SendTicketMessageRequest (API) : un message fait la même taille quel que soit son auteur.
    private const MAX_REPLY_LENGTH = 5000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
        #[Autowire(service: 'ticket_attachments.storage')]
        private readonly FilesystemOperator $attachmentsStorage,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Ticket::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Ticket')
            ->setEntityLabelInPlural('Tickets')
            ->setPageTitle(Crud::PAGE_INDEX, 'Support')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    // * idUser visible seulement à la création (l'admin ouvre un ticket pour un appel/mail reçu hors plateforme,
    // * cf. docblock de classe) : hideWhenUpdating au lieu de hideOnForm, sinon user_id (NOT NULL) ne peut
    // * jamais être renseigné et la création échoue. Une fois le ticket créé, son auteur ne se réassigne pas ;
    // * seule sa prise en charge (assignedTo) reste éditable. choice_label : User n'a pas de __toString().
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->hideOnIndex();
        yield AssociationField::new('idUser')
            ->setLabel('Utilisateur')
            ->formatValue(fn ($v, $e) => $e?->getIdUser()?->getEmail())
            ->setFormTypeOption('choice_label', 'email')
            ->hideWhenUpdating();
        yield TextField::new('subject')->setLabel('Sujet');
        // * Ticket::$status/$priority sont de simples chaînes (pas d'enum) : $enumBacked = false, les choix restent donc
        // * explicites sur les formulaires aussi (voir StatusBadgeFieldTrait).
        yield $this->statusBadgeField('priority', 'Priorité', $pageName, array_flip(self::PRIORITIES), self::PRIORITY_VARIANTS, false);
        yield $this->statusBadgeField('status', 'Statut', $pageName, array_flip(self::STATUSES), self::STATUS_VARIANTS, false);
        yield AssociationField::new('assignedTo')
            ->setLabel('Assigné à')
            ->formatValue(fn ($v, $e) => $e?->getAssignedTo()?->getEmail())
            ->setFormTypeOption('choice_label', 'email')
            ->hideOnIndex();
        yield DateTimeField::new('createdAt')->setLabel('Ouvert le')->setFormat('d MMM y')->hideOnForm();
        yield DateTimeField::new('closedAt')->setLabel('Fermé le')->hideOnForm()->hideOnIndex();
        yield AssociationField::new('messages')->onlyOnDetail();
    }

    // * Puces "Ouverts/En cours/Résolus" (layout.html.twig, tm_filter_chips) au lieu du bouton "+ Filtres".
    // * TextFilter + setFormType(TextType::class) : voir le commentaire équivalent sur
    // * ProducerProfileCrudController::configureFilters() -- valeur soumise gardée plate pour tm_filter_chips.
    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(TextFilter::new('status')->setFormType(TextType::class));
    }

    // * Page détail sur mesure (maquette Figma "Détail ticket" : informations, fil de messages) à la place de la
    // * fiche générique d'EasyAdmin -- le fil est un échange chronologique, pas une liste d'associations.
    public function detail(AdminContext $context): KeyValueStore|Response
    {
        /** @var Ticket $ticket */
        $ticket = $context->getEntity()->getInstance();
        $urls = $this->container->get(AdminUrlGenerator::class);

        // * Triés explicitement par createdAt : Ticket::$messages n'a pas d'#[ORM\OrderBy] (même remarque que
        // * TicketController::getTicket() côté API).
        $messages = $this->em->getRepository(TicketMessage::class)->findBy(['ticket' => $ticket], ['createdAt' => 'ASC']);

        return $this->render('admin/ticket/detail.html.twig', [
            'ticket' => $ticket,
            'requester' => $ticket->getIdUser(),
            'messages' => $messages,
            'statusLabel' => array_flip(self::STATUSES)[$ticket->getStatus()] ?? $ticket->getStatus(),
            'statusVariant' => self::STATUS_VARIANTS[$ticket->getStatus()] ?? 'secondary',
            'priorityLabel' => null !== $ticket->getPriority() ? (array_flip(self::PRIORITIES)[$ticket->getPriority()] ?? $ticket->getPriority()) : null,
            'priorityVariant' => self::PRIORITY_VARIANTS[$ticket->getPriority()] ?? 'secondary',
            'indexUrl' => $urls->setController(self::class)->setAction(Action::INDEX)->unset('entityId')->generateUrl(),
            'editUrl' => $urls->setController(self::class)->setAction(Action::EDIT)->setEntityId($ticket->getId())->generateUrl(),
            'attachmentUrls' => $this->attachmentUrls($messages, $ticket, $urls),
            'canReply' => 'closed' !== $ticket->getStatus(),
            'replyUrl' => $urls->setController(self::class)->setAction('replyToTicket')->setEntityId($ticket->getId())->generateUrl(),
            'maxReplyLength' => self::MAX_REPLY_LENGTH,
        ]);
    }

    // * Réponse de l'agent (cahier fonctionnel, Support). Même règle que l'API (TicketController::
    // * reopenOrRejectIfClosed()) : un ticket fermé n'accepte plus aucun message. Le premier agent qui répond prend le
    // * ticket en charge s'il n'a personne, et un ticket "ouvert" passe "en cours" -- il n'attend plus une première
    // * réponse. Un ticket "résolu" reste résolu (suivi après coup). Le contenu du message n'est pas journalisé
    // * (données personnelles du demandeur) : seul l'identifiant du message l'est.
    /**
     * @param AdminContext<Ticket> $context
     */
    #[AdminRoute(path: '/{entityId}/reply', name: 'reply', options: ['methods' => ['POST']])]
    public function replyToTicket(AdminContext $context): Response
    {
        /** @var Ticket $ticket */
        $ticket = $context->getEntity()->getInstance();
        $request = $context->getRequest();
        $agent = $this->getUser();
        if (!$agent instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('ticket_reply', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $content = trim((string) $request->request->get('content'));
        if ('closed' === $ticket->getStatus()) {
            $this->addFlash('danger', 'Ce ticket est fermé : il n\'accepte plus de message.');
        } elseif ('' === $content) {
            $this->addFlash('danger', 'La réponse ne peut pas être vide.');
        } elseif (mb_strlen($content) > self::MAX_REPLY_LENGTH) {
            $this->addFlash('danger', sprintf('La réponse est trop longue (%d caractères maximum).', self::MAX_REPLY_LENGTH));
        } else {
            $message = new TicketMessage();
            $message->setTicket($ticket);
            $message->setSender($agent);
            $message->setContent($content);
            $this->em->persist($message);

            if (null === $ticket->getAssignedTo()) {
                $ticket->setAssignedTo($agent);
            }
            if ('open' === $ticket->getStatus()) {
                $ticket->setStatus('in_progress');
            }

            $this->auditLogger->log('ticket_replied', 'support', 'tickets', $ticket->getId()->toRfc4122(), null, [
                'message_id' => $message->getId()->toRfc4122(),
                'status' => $ticket->getStatus(),
            ]);
            $this->em->flush();
            $this->addFlash('success', 'Réponse envoyée.');
        }

        return $this->redirect(
            $this->container->get(AdminUrlGenerator::class)
                ->setController(self::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($ticket->getId())
                ->generateUrl()
        );
    }

    // * Téléchargement d'une pièce jointe (cahier fonctionnel, Support : "pièces jointes"). Servie en flux par
    // * l'application (et pas par une URL présignée du stockage) : reste derrière l'authentification du
    // * back-office, sans dépendre du type de stockage de l'environnement. Le fichier doit appartenir au ticket de
    // * l'URL ; "attachment" empêche tout affichage/exécution dans le navigateur, quel que soit le type déclaré.
    /**
     * @param AdminContext<Ticket> $context
     */
    #[AdminRoute(path: '/{entityId}/attachment', name: 'attachment')]
    public function downloadAttachment(AdminContext $context): Response
    {
        /** @var Ticket $ticket */
        $ticket = $context->getEntity()->getInstance();
        $attachment = $this->em->find(TicketAttachment::class, (string) $context->getRequest()->query->get('attachmentId'));
        $key = $attachment?->getFileUrl();
        if (!$attachment instanceof TicketAttachment || null === $key || $attachment->getTicketMessage()->getTicket() !== $ticket) {
            throw new NotFoundHttpException('Pièce jointe introuvable pour ce ticket.');
        }

        $stream = $this->attachmentsStorage->readStream($key);
        $response = new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        });
        $response->headers->set('Content-Type', $attachment->getMimeType() ?? 'application/octet-stream');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $attachment->getFileName() ?? 'piece-jointe'));

        return $response;
    }

    /**
     * @param list<TicketMessage> $messages
     *
     * @return array<string, string> identifiant de pièce jointe => URL de téléchargement
     */
    private function attachmentUrls(array $messages, Ticket $ticket, AdminUrlGenerator $urls): array
    {
        $result = [];
        foreach ($messages as $message) {
            foreach ($message->getAttachments() as $attachment) {
                $result[$attachment->getId()->toRfc4122()] = $urls
                    ->setController(self::class)
                    ->setAction('downloadAttachment')
                    ->setEntityId($ticket->getId())
                    ->set('attachmentId', $attachment->getId()->toRfc4122())
                    ->generateUrl();
            }
        }

        return $result;
    }
}