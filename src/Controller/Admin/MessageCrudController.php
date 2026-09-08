<?php

namespace App\Controller\Admin;

use App\Entity\Identity\User;
use App\Entity\Messaging\Conversation;
use App\Entity\Messaging\Message;
use App\Entity\Trust\ModerationAction;
use App\Entity\Trust\Report;
use App\Enum\ConversationStatus;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class MessageCrudController extends AbstractCrudController
{
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
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->innerJoin('entity.conversation', 'c')
            ->andWhere('c.status = :reported')
            ->setParameter('reported', ConversationStatus::Reported);
    }

    public function detail(AdminContext $context): KeyValueStore|Response
    {
        $this->assertMessageIsReported($context->getEntity()->getInstance());

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
        yield DateTimeField::new('moderatedAt')->hideOnForm();
        yield DateTimeField::new('createdAt')->hideOnForm();
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

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, $hide)->add(Crud::PAGE_DETAIL, $hide)
            ->add(Crud::PAGE_INDEX, $block)->add(Crud::PAGE_DETAIL, $block);
    }

    #[AdminRoute(path: '/{entityId}/hide', name: 'hide')]
    public function hideMessage(AdminContext $context, EntityManagerInterface $em, #[CurrentUser] User $admin): Response
    {
        $message = $context->getEntity()->getInstance();
        $this->assertMessageIsReported($message);
        $message->setModeratedAt(new \DateTimeImmutable());
        $this->recordModerationAction($message->getConversation(), $admin, 'hide_message', ['messageId' => $message->getId()->toRfc4122()], $em);

        $em->flush();
        $this->addFlash('success', 'Message masqué.');

        return $this->redirectToIndex();
    }

    // * BlockedUser (blocker/blocked) sert au blocage entre pairs (un client bloque un producteur ou
    // * inversement) : sa clé primaire composite exige un blocker non nul, ce qu'un admin n'est pas ici.
    // * Le "blocage" côté modération back-office réutilise donc la suspension déjà en place sur Utilisateurs
    // * (User.status), mécanisme réellement conçu pour une décision administrative sur un compte.
    #[AdminRoute(path: '/{entityId}/block-sender', name: 'block_sender')]
    public function blockSender(AdminContext $context, EntityManagerInterface $em, #[CurrentUser] User $admin): Response
    {
        $message = $context->getEntity()->getInstance();
        $this->assertMessageIsReported($message);
        $sender = $message->getSender();
        $sender->setStatus(UserStatus::Suspended);

        $this->recordModerationAction($message->getConversation(), $admin, 'block_user', ['userId' => $sender->getId()->toRfc4122()], $em);

        $em->flush();
        $this->addFlash('success', 'Utilisateur suspendu.');

        return $this->redirectToIndex();
    }

    // * ModerationAction.report n'est pas nullable : sans Report retrouvé (ne devrait pas arriver puisque
    // * la liste est déjà filtrée aux conversations signalées), on masque/bloque quand même mais sans trace --
    // * mieux vaut agir sans trace que ne pas agir du tout, mais ce cas ne devrait jamais se produire en pratique.
    private function recordModerationAction(Conversation $conversation, User $admin, string $actionType, array $payload, EntityManagerInterface $em): void
    {
        $report = $em->getRepository(Report::class)->findOneBy([
            'targetType' => 'conversation',
            'targetId' => $conversation->getId(),
        ]);

        if (null === $report) {
            return;
        }

        $action = new ModerationAction();
        $action->setReport($report);
        $action->setAdmin($admin);
        $action->setActionType($actionType);
        $action->setPayload($payload);
        $em->persist($action);
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