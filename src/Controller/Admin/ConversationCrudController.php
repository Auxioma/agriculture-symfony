<?php

namespace App\Controller\Admin;

use App\Entity\Messaging\Conversation;
use App\Enum\ConversationStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ConversationCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Conversation::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        // * Pas de setEntityPermission() : combiné aux actions personnalisées #[AdminRoute] ci-dessous, ça
        // * casse la résolution de l'entité dans cette version d'EasyAdmin (5.5.1) -- même constat que sur
        // * MessageCrudController. La protection "signalement uniquement" passe par createIndexQueryBuilder()
        // * pour la liste et par assertConversationIsReported() pour detail()/reopen/close.
        return $crud
            ->setEntityLabelInSingular('Conversation signalée')
            ->setEntityLabelInPlural('Conversations signalées')
            ->setDefaultSort(['lastMessageAt' => 'DESC']);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->andWhere('entity.status = :reported')
            ->setParameter('reported', ConversationStatus::Reported);
    }

    public function detail(AdminContext $context): KeyValueStore|Response
    {
        $this->assertConversationIsReported($context->getEntity()->getInstance());

        return parent::detail($context);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        // * $e peut être null lors de certains rendus internes d'EasyAdmin (ex. calcul des métadonnées de
        // * colonne indépendamment des lignes) -- l'opérateur null-safe doit couvrir $e lui-même, pas
        // * seulement l'association, sous peine d'un "Call to a member function ... on null".
        yield AssociationField::new('client')->formatValue(fn ($v, $e) => $e?->getClient()?->getEmail())->hideOnForm();
        yield AssociationField::new('producer')->formatValue(fn ($v, $e) => $e?->getProducer()?->getFarmName())->hideOnForm();
        yield ChoiceField::new('status')->hideOnForm();
        yield DateTimeField::new('lastMessageAt')->hideOnForm();
        yield AssociationField::new('messages')->onlyOnDetail();
    }

    // * Pas d'EDIT : un formulaire d'édition libre sur "status" a fait planter la génération du lien de
    // * cette action dans cette version d'EasyAdmin (URL générée avec un entityId vide) dès qu'un
    // * AssociationField avec formatValue() était présent sur l'index -- Rouvrir/Clôturer le remplacent.
    public function configureActions(Actions $actions): Actions
    {
        // * displayIf exige Reported, comme assertConversationIsReported() : une fois traitée (rouverte ou
        // * clôturée), la conversation sort du statut "signalé" et disparaît donc de cette liste (filtrée
        // * par createIndexQueryBuilder) -- elle ne peut jamais être re-présentée à ces actions après coup.
        $reopen = Action::new('reopen', 'Rouvrir')
            ->linkToCrudAction('reopenConversation')
            ->displayIf(fn (Conversation $c) => ConversationStatus::Reported === $c->getStatus());

        $close = Action::new('close', 'Clôturer')
            ->linkToCrudAction('closeConversation')
            ->displayIf(fn (Conversation $c) => ConversationStatus::Reported === $c->getStatus());

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, $reopen)->add(Crud::PAGE_DETAIL, $reopen)
            ->add(Crud::PAGE_INDEX, $close)->add(Crud::PAGE_DETAIL, $close);
    }

    #[AdminRoute(path: '/{entityId}/reopen', name: 'reopen')]
    public function reopenConversation(AdminContext $context, EntityManagerInterface $em): Response
    {
        $conversation = $context->getEntity()->getInstance();
        $this->assertConversationIsReported($conversation);
        $conversation->setStatus(ConversationStatus::Open);
        $em->flush();
        $this->addFlash('success', 'Conversation rouverte.');

        return $this->redirectToIndex();
    }

    #[AdminRoute(path: '/{entityId}/close', name: 'close')]
    public function closeConversation(AdminContext $context, EntityManagerInterface $em): Response
    {
        $conversation = $context->getEntity()->getInstance();
        $this->assertConversationIsReported($conversation);
        $conversation->setStatus(ConversationStatus::Closed);
        $em->flush();
        $this->addFlash('success', 'Conversation clôturée.');

        return $this->redirectToIndex();
    }

    private function assertConversationIsReported(?Conversation $conversation): void
    {
        if (null === $conversation || ConversationStatus::Reported !== $conversation->getStatus()) {
            throw new AccessDeniedHttpException("Cette conversation n'est pas signalée.");
        }
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