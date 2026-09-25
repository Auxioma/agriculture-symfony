<?php

namespace App\Controller\Admin;

use App\Entity\Matching\ClientRequest;
use App\Enum\RequestStatus;
use App\Service\Matching\RequestQualityAnalyzer;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// * RBAC (cahier DevOps ; cahier fonctionnel 22.2, "Le support accède uniquement aux éléments nécessaires") :
// * pas dans le périmètre nécessaire au support (voir security.yaml pour le détail du mécanisme).
#[IsGranted('ROLE_ADMIN')]
class ClientRequestCrudController extends AbstractCrudController
{
    use StatusBadgeFieldTrait;

    /** @var array<string, list<string>>|null */
    private ?array $flaggedSignals = null;

    // * Injection par constructeur : le container restreint d'un AbstractCrudController ne connaît pas les services
    // * applicatifs (voir ConversationCrudController).
    public function __construct(private readonly RequestQualityAnalyzer $analyzer)
    {
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
        yield $this->statusBadgeField('status', 'Statut', $pageName, [
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
        ], [
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
        ]);
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
