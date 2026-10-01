<?php

namespace App\Controller\Admin;

use App\Enum\ReportStatus;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

/**
 * Filtre "Signalement" de l'écran Signalements (MessageCrudController) : ne garde que les messages dont la
 * conversation a un Report::$status donné. Comme FlaggedFilter (Demandes clients), la correspondance
 * conversation => statut n'existe nulle part en base sous cette forme (Report est polymorphe, targetType/
 * targetId, pas de relation Doctrine directe avec Conversation) : elle est reçue du contrôleur sous forme de
 * fonction, calculée une seule fois par page (MessageCrudController::reportsByConversationId()).
 */
final class ReportStatusFilter implements FilterInterface
{
    use FilterTrait;

    private \Closure $conversationIdsForStatus;

    /**
     * @param \Closure(string): list<string> $conversationIdsForStatus valeur (ReportStatus::value) => identifiants de conversation
     */
    public static function new(string $propertyName, \Closure $conversationIdsForStatus): self
    {
        $filter = (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel('Signalement')
            ->setFormType(ChoiceType::class)
            ->setFormTypeOptions(['choices' => [
                'Ouvert' => ReportStatus::Open->value,
                'En cours' => ReportStatus::InReview->value,
                'Résolu' => ReportStatus::Resolved->value,
                'Rejeté' => ReportStatus::Rejected->value,
            ], 'placeholder' => 'Tous']);
        $filter->conversationIdsForStatus = $conversationIdsForStatus;

        return $filter;
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $ids = ($this->conversationIdsForStatus)((string) $filterDataDto->getValue());

        // * "IN ()" sans élément est une erreur SQL : aucune conversation à ce statut = aucun résultat.
        if ([] === $ids) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $parameterName = $filterDataDto->getParameterName();
        $queryBuilder
            ->andWhere(sprintf('%s.conversation IN (:%s)', $filterDataDto->getEntityAlias(), $parameterName))
            ->setParameter($parameterName, $ids);
    }
}
