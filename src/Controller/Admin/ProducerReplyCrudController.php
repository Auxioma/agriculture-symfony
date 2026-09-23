<?php

namespace App\Controller\Admin;

use App\Entity\Matching\ClientRequest;
use App\Entity\Matching\ProducerReply;
use App\Enum\ReplyStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Écran "Réponses et devis" du back-office (maquette Figma "Réponses et devis" : "Suivi des propositions
 * envoyées par les producteurs"). Consultation seule : une réponse naît de l'espace producteur
 * (ProducerRequestController) et l'admin ne fait que la suivre, sans la créer, la modifier ni la supprimer.
 *
 * RBAC (cahier DevOps ; cahier fonctionnel 22.2, "Le support accède uniquement aux éléments nécessaires") :
 * pas dans le périmètre nécessaire au support (voir security.yaml pour le détail du mécanisme).
 */
#[IsGranted('ROLE_ADMIN')]
class ProducerReplyCrudController extends AbstractCrudController
{
    use StatusBadgeFieldTrait;

    public static function getEntityFqcn(): string
    {
        return ProducerReply::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Réponse')
            ->setEntityLabelInPlural('Réponses et devis')
            ->setPageTitle(Crud::PAGE_INDEX, 'Réponses et devis')
            ->setPageTitle(Crud::PAGE_DETAIL, 'Détail de la réponse')
            ->setSearchFields(['producer.farmName', 'replyText'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnIndex();
        yield AssociationField::new('request')
            ->setLabel('Demande liée')
            ->formatValue(fn ($value, ProducerReply $reply) => $this->describeRequest($reply->getRequest()));
        yield AssociationField::new('producer')
            ->setLabel('Producteur')
            ->formatValue(fn ($value, ProducerReply $reply) => $reply->getProducer()->getFarmName());
        yield TextField::new('priceAmount')
            ->setLabel('Prix indicatif')
            ->formatValue(fn ($value, ProducerReply $reply) => $this->describePrice($reply));
        yield DateTimeField::new('createdAt')->setLabel('Date')->setFormat('d MMMM y');
        yield $this->statusBadgeField('status', 'Statut', $pageName, [
            ReplyStatus::Draft->value => 'Brouillon',
            ReplyStatus::Sent->value => 'Envoyée',
            ReplyStatus::Seen->value => 'Vue',
            ReplyStatus::Accepted->value => 'Acceptée',
            ReplyStatus::Declined->value => 'Refusée',
            ReplyStatus::Expired->value => 'Expirée',
            ReplyStatus::Archived->value => 'Archivée',
        ], [
            ReplyStatus::Draft->value => 'secondary',
            ReplyStatus::Sent->value => 'secondary',
            ReplyStatus::Seen->value => 'warning',
            ReplyStatus::Accepted->value => 'success',
            ReplyStatus::Declined->value => 'secondary',
            ReplyStatus::Expired->value => 'secondary',
            ReplyStatus::Archived->value => 'secondary',
        ]);
        yield TextareaField::new('replyText')->setLabel('Message')->onlyOnDetail();
        yield TextareaField::new('conditions')->setLabel('Conditions')->onlyOnDetail();
        yield DateField::new('availabilityDate')->setLabel('Disponible à partir du')->setFormat('d MMMM y')->onlyOnDetail();
        yield DateField::new('validUntil')->setLabel("Valable jusqu'au")->setFormat('d MMMM y')->onlyOnDetail();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('status');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::DETAIL, static fn (Action $action) => $action->setLabel('Voir'));
    }

    // * Une demande n'a pas de titre : produit du catalogue (ou produit libre, à défaut la catégorie), suivi de
    // * la quantité et de l'unité quand elles sont renseignées -- ex. "Tomates bio - 5 kg".
    private function describeRequest(ClientRequest $request): string
    {
        $subject = $request->getProduct()?->getName() ?? $request->getCustomProduct() ?? $request->getCategory()?->getName() ?? 'Demande';
        $quantity = $request->getQuantity();
        if (null === $quantity) {
            return $subject;
        }

        if (str_contains($quantity, '.')) {
            $quantity = rtrim(rtrim($quantity, '0'), '.');
        }

        return trim(sprintf('%s - %s %s', $subject, $quantity, $request->getUnit()?->getCode() ?? ''));
    }

    // * "3,50 €/kg" : montant à deux décimales, symbole de la devise (à défaut son code), unité en suffixe.
    private function describePrice(ProducerReply $reply): string
    {
        if (null === $reply->getPriceAmount()) {
            return '—';
        }

        $currency = $reply->getCurrency();
        $formatted = number_format((float) $reply->getPriceAmount(), 2, ',', ' ').' '.($currency?->getSymbol() ?? $currency?->getCode() ?? '');
        $unit = $reply->getPriceUnit();

        return rtrim($formatted).(null !== $unit ? '/'.$unit->getCode() : '');
    }
}
