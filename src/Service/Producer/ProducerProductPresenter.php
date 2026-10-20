<?php

namespace App\Service\Producer;

use App\Entity\Catalog\Label;
use App\Entity\Catalog\Unit;
use App\Entity\Producer\ProducerProduct;
use App\Entity\Producer\ProducerProductMedia;

/**
 * Un produit du producteur tel que le rend l'API : pour sa page « Produits et disponibilités » (forOwner) et pour la
 * fiche publique du producteur (forPublic). Même source, deux vues : la fiche publique n'a ni l'état actif (elle ne
 * montre que les produits actifs), ni le créneau en détail, ni les identifiants de modification.
 *
 * * La période, le volume et l'unité que le producteur a choisis (premier créneau, voir ProductAvailabilityWindow)
 * * l'emportent sur ce que dit le catalogue ; sans eux, la fiche publique retombe sur la saison et l'unité du catalogue.
 */
final class ProducerProductPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function forOwner(ProducerProduct $item): array
    {
        $product = $item->getProduct();
        $slot = ProductAvailabilityWindow::primary($item->getProductAvailabilities());
        [$startMonth, $endMonth] = null === $slot ? [null, null] : ProductAvailabilityWindow::monthsOf($slot);

        return [
            'id' => $item->getId()->toRfc4122(),
            'productId' => $product->getId()->toRfc4122(),
            'name' => $product->getName(),
            'variety' => $item->getVariety(),
            'description' => $item->getDescription(),
            'estimatedVolume' => $item->getEstimatedVolume(),
            'defaultPrice' => $item->getDefaultPrice(),
            'currencyCode' => $item->getCurrency()?->getCode(),
            'isActive' => $item->isActive(),
            'unit' => self::unit($product->getDefaultUnit()),
            'seasonStartMonth' => $product->getSeasonStartMonth(),
            'seasonEndMonth' => $product->getSeasonEndMonth(),
            'availability' => null === $slot ? null : [
                'startMonth' => $startMonth,
                'endMonth' => $endMonth,
                'quantity' => $slot->getQuantityEstimate(),
                'unit' => self::unit($slot->getUnit()),
            ],
            'labels' => array_map(static fn (Label $label) => ['id' => $label->getId()->toRfc4122(), 'name' => $label->getName()], $item->getLabels()->toArray()),
            'photos' => array_map(static fn (ProducerProductMedia $photo) => ['id' => $photo->getId()->toRfc4122(), 'url' => $photo->getFileUrl()], self::photos($item)),
        ];
    }

    /**
     * Pour GET /api/producers/{identifier}. `id` reste l'identifiant du produit du catalogue (c'est ce que renvoyait la
     * fiche avant) et seasonStartMonth / seasonEndMonth restent la période du produit : celle du producteur si elle existe.
     * Les labels sont déclarés par le producteur pour ce produit, pas vérifiés : le front doit le dire.
     *
     * @return array<string, mixed>
     */
    public function forPublic(ProducerProduct $item): array
    {
        $product = $item->getProduct();
        $slot = ProductAvailabilityWindow::primary($item->getProductAvailabilities());
        [$startMonth, $endMonth] = null === $slot ? [null, null] : ProductAvailabilityWindow::monthsOf($slot);
        $ownPeriod = null !== $startMonth && null !== $endMonth;

        return [
            'id' => $product->getId()->toRfc4122(),
            'name' => $product->getName(),
            'variety' => $item->getVariety(),
            'description' => $item->getDescription(),
            'seasonStartMonth' => $ownPeriod ? $startMonth : $product->getSeasonStartMonth(),
            'seasonEndMonth' => $ownPeriod ? $endMonth : $product->getSeasonEndMonth(),
            'quantity' => $slot?->getQuantityEstimate() ?? $item->getEstimatedVolume(),
            'unit' => self::unit($slot?->getUnit() ?? $product->getDefaultUnit()),
            'price' => $item->getDefaultPrice(),
            'currencyCode' => $item->getCurrency()?->getCode(),
            'labels' => array_map(static fn (Label $label) => ['code' => $label->getCode(), 'name' => $label->getName()], $item->getLabels()->toArray()),
            'photos' => array_map(static fn (ProducerProductMedia $photo) => ['url' => $photo->getFileUrl(), 'alt' => $photo->getAltText()], self::photos($item)),
        ];
    }

    /**
     * @return array{id: string, code: string, label: string}|null
     */
    public static function unit(?Unit $unit): ?array
    {
        return null === $unit ? null : ['id' => $unit->getId()->toRfc4122(), 'code' => $unit->getCode(), 'label' => $unit->getLabel() ?? $unit->getCode()];
    }

    /**
     * Les photos dans l'ordre de la galerie (les labels sont déjà triés par la requête, les photos non : plusieurs
     * collections jointes ensemble ne gardent pas chacune leur tri).
     *
     * @return list<ProducerProductMedia>
     */
    private static function photos(ProducerProduct $item): array
    {
        $photos = $item->getMedia()->toArray();
        usort($photos, static fn (ProducerProductMedia $a, ProducerProductMedia $b) => ($a->getPosition() ?? 0) <=> ($b->getPosition() ?? 0));

        return $photos;
    }
}
