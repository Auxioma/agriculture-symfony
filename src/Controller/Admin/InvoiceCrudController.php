<?php

namespace App\Controller\Admin;

use App\Entity\Billing\Invoice;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Module "Abonnements" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf) -- volet "factures". Consultation seule (générées par Stripe).
 */
// * RBAC (cahier DevOps ; cahier fonctionnel 22.2, "Le support accède uniquement aux éléments nécessaires") :
// * pas dans le périmètre nécessaire au support (voir security.yaml pour le détail du mécanisme).
#[IsGranted('ROLE_ADMIN')]
class InvoiceCrudController extends AbstractCrudController
{
    use StatusBadgeFieldTrait;
    use EagerAssociationJoinTrait;

    public static function getEntityFqcn(): string
    {
        return Invoice::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Facture')
            ->setEntityLabelInPlural('Factures')
            ->setPageTitle(Crud::PAGE_INDEX, 'Paiements et factures')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    // * subscription puis subscription.producer forcent sinon une requête par ligne (voir le commentaire
    // * équivalent sur ClientRequestCrudController::createIndexQueryBuilder()).
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->leftJoin('entity.subscription', 'subscription')->addSelect('subscription')
            ->leftJoin('subscription.producer', 'producer')->addSelect('producer');

        return $this->joinProducerEagerly($qb, 'producer');
    }

    public function configureFields(string $pageName): iterable
    {
        // * Colonnes de la maquette "Admin · Paiements et factures" : PRODUCTEUR, MONTANT, STATUT, DATE.
        yield IdField::new('id')->hideOnForm()->hideOnIndex();
        yield AssociationField::new('subscription')
            ->setLabel('Producteur')
            ->formatValue(fn ($v, $e) => $e?->getSubscription()?->getProducer()->getFarmName())
            ->hideOnForm();
        yield TextField::new('amount')->setLabel('Montant')->formatValue(fn ($v) => null === $v ? '—' : str_replace('.', ',', (string) $v).' €')->hideOnForm();
        // * Invoice::$status est une simple chaîne ('paid', 'open', ...) : $enumBacked = false.
        yield $this->statusBadgeField('status', 'Statut', $pageName, [
            'paid' => 'Payée',
            'open' => 'En attente',
            'failed' => 'Échec',
        ], [
            'paid' => 'success',
            'open' => 'warning',
            'failed' => 'danger',
        ], false)->hideOnForm();
        yield TextField::new('invoiceUrl')->setLabel('Lien facture')->hideOnIndex()->hideOnForm();
        yield DateTimeField::new('paidAt')->setLabel('Payée le')->setFormat('d MMM y')->hideOnForm();
        yield DateTimeField::new('createdAt')->setLabel('Créée le')->setFormat('d MMM y')->hideOnForm()->hideOnIndex();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }
}