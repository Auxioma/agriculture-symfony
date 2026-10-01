<?php

namespace App\Controller\Admin;

use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

/**
 * Filtre "À vérifier" de l'écran Demandes : ne garde que les demandes signalées comme doublon ou spam par
 * App\Service\Matching\RequestQualityAnalyzer. Cette liste d'identifiants n'existe pas en base (elle est calculée),
 * donc le filtre la reçoit du contrôleur sous forme de fonction -- possible parce qu'EasyAdmin conserve l'objet
 * filtre lui-même (FilterTrait garde $this dans le callback d'application). Sert aussi de propriété virtuelle
 * ("quality") : la valeur soumise est celle de la puce (tm_filter_chips, layout.html.twig).
 *
 * ChoiceType et non TextType (contrairement à RoleFilter/InFilter) : ici la même valeur est aussi proposée dans la
 * fenêtre "+ Filtres", qui doit montrer une liste déroulante lisible plutôt qu'un champ texte libre. Reste plat
 * ("filters[quality]=flagged"), comme les autres.
 */
final class FlaggedFilter implements FilterInterface
{
    use FilterTrait;

    public const VALUE = 'flagged';

    /** @var \Closure(): list<string> */
    private \Closure $flaggedIds;

    /**
     * @param \Closure(): list<string> $flaggedIds identifiants des demandes signalées (évalué seulement si le filtre est actif)
     */
    public static function new(string $propertyName, \Closure $flaggedIds): self
    {
        $filter = (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel('Signalement')
            ->setFormType(ChoiceType::class)
            ->setFormTypeOptions(['choices' => ['À vérifier (doublon ou spam)' => self::VALUE], 'placeholder' => 'Toutes']);
        $filter->flaggedIds = $flaggedIds;

        return $filter;
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        if (self::VALUE !== $filterDataDto->getValue()) {
            return;
        }

        $ids = ($this->flaggedIds)();
        // * "IN ()" sans élément est une erreur SQL : aucune demande signalée = aucun résultat, tout simplement.
        if ([] === $ids) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $parameterName = $filterDataDto->getParameterName();
        $queryBuilder
            ->andWhere(sprintf('%s.id IN (:%s)', $filterDataDto->getEntityAlias(), $parameterName))
            ->setParameter($parameterName, $ids);
    }
}
