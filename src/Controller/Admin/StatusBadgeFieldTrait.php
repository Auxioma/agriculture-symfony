<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;

/**
 * Champ "statut" rendu en badge coloré avec libellé français dans les listes/détails du back-office (maquette
 * Figma : pastilles vert/orange/gris). Sur les formulaires, un statut typé par enum garde le ChoiceField
 * automatique d'EasyAdmin : lui donner des valeurs en chaîne casserait la sauvegarde (setStatus(UserStatus) reçoit
 * une chaîne -> TypeError). $enumBacked = false pour les statuts stockés en simple chaîne (Ticket, Invoice...), qui
 * ont besoin de leurs choix explicites partout, formulaires compris.
 */
trait StatusBadgeFieldTrait
{
    /**
     * @param array<string, string> $labels   valeur => libellé affiché
     * @param array<string, string> $variants valeur => success|warning|danger|info|primary|secondary
     */
    private function statusBadgeField(string $property, string $label, string $pageName, array $labels, array $variants, bool $enumBacked = true): ChoiceField
    {
        $field = ChoiceField::new($property)->setLabel($label);
        $isReadOnlyPage = Crud::PAGE_INDEX === $pageName || Crud::PAGE_DETAIL === $pageName;

        if ($isReadOnlyPage || !$enumBacked) {
            $field->setChoices(array_flip($labels));
        }
        if ($isReadOnlyPage) {
            $field->renderAsBadges($variants);
        }

        return $field;
    }
}
