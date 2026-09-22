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
 * Filtre "un rôle donné parmi ceux de User::$roles" (maquette Figma "Admin · Utilisateurs" : puces
 * Tous/Clients/Producteurs/Support/Admins -- UserCrudController). $roles est un simple_array (colonne texte
 * "ROLE_A,ROLE_B", User.php) : ni EntityFilter ni ChoiceFilter (pensés pour une colonne à valeur unique) ne
 * conviennent, une égalité stricte ne matcherait jamais un utilisateur ayant plusieurs rôles -- LIKE est
 * nécessaire. La valeur soumise peut lister plusieurs rôles séparés par "|" (ex.
 * "ROLE_ADMIN|ROLE_SUPER_ADMIN" pour la puce "Admins"), combinés en OR.
 *
 * FormType volontairement un simple TextType (pas le ChoiceFilterType par défaut d'EasyAdmin, qui imbrique
 * la valeur soumise sous "comparison"/"value") : la querystring reste alors "filters[roles]=ROLE_CLIENT",
 * la même forme que lit tm_filter_chips (templates/bundles/EasyAdminBundle/layout.html.twig) pour toutes les
 * puces de statut de l'admin.
 */
final class RoleFilter implements FilterInterface
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
        $alias = $filterDataDto->getEntityAlias();
        $property = $filterDataDto->getProperty();

        $conditions = [];
        foreach (explode('|', (string) $filterDataDto->getValue()) as $index => $role) {
            $parameterName = $filterDataDto->getParameterName().'_'.$index;
            $conditions[] = sprintf('%s.%s LIKE :%s', $alias, $property, $parameterName);
            $queryBuilder->setParameter($parameterName, '%'.$role.'%');
        }

        $queryBuilder->andWhere(implode(' OR ', $conditions));
    }
}
