<?php

namespace App\Controller\Admin;

use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * Filtre "propriété dans une liste de valeurs exactes" (maquette Figma "Admin · Journal d'audit" : puces
 * Toutes/Modération/Facturation/Comptes, chacune regroupant plusieurs valeurs de $schemaName --
 * AuditLogCrudController). Valeur soumise = plusieurs valeurs séparées par "|" (ex. "producer|messaging|trust"
 * pour la puce "Modération"), combinées en SQL IN(...).
 *
 * FormType volontairement un simple TextType (comme RoleFilter) : la querystring reste
 * "filters[schemaName]=..." sans le sous-niveau "comparison"/"value" qu'EasyAdmin ajoute pour ses filtres à
 * opérateur -- exactement ce que lisent les puces de tm_filter_chips (layout.html.twig).
 */
final class InFilter implements FilterInterface
{
    use FilterTrait;

    public static function new(string $propertyName): self
    {
        return (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setFormType(TextType::class);
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $parameterName = $filterDataDto->getParameterName();

        $queryBuilder
            ->andWhere(sprintf('%s.%s IN (:%s)', $filterDataDto->getEntityAlias(), $filterDataDto->getProperty(), $parameterName))
            ->setParameter($parameterName, explode('|', (string) $filterDataDto->getValue()));
    }
}
