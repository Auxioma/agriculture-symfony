<?php

namespace App\Dto\Producer;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Période de disponibilité d'un produit, dans POST /api/producer/products et PUT /api/producer/products/{id} :
 * début et fin en mois (1 à 12, comme le formulaire du producteur), volume estimé sur la période et unité.
 * Le back en fait le créneau du produit (voir ProductAvailabilityWindow pour les dates).
 *
 * * Tout à null = supprimer le créneau du produit. À l'inverse, ne pas envoyer "availability" du tout laisse le
 * * créneau tel quel (c'est ce que font l'interrupteur de la liste et les anciens clients).
 */
final readonly class ProductAvailabilityInput
{
    public function __construct(
        #[Assert\Range(min: 1, max: 12, notInRangeMessage: 'Mois invalide.')]
        public ?int $startMonth = null,
        #[Assert\Range(min: 1, max: 12, notInRangeMessage: 'Mois invalide.')]
        public ?int $endMonth = null,
        #[Assert\Regex(pattern: '/^\d{1,11}(\.\d{1,3})?$/', message: 'Volume estimé invalide.')]
        public ?string $quantity = null,
        // * existence de l'unité vérifiée dans le contrôleur (le validator n'a pas accès à la base)
        #[Assert\Uuid(message: 'Unité invalide.')]
        public ?string $unitId = null,
    ) {
    }

    // * un début sans fin (ou l'inverse) ne dit pas de quelle période il s'agit
    #[Assert\IsTrue(message: 'Le début et la fin de la période vont ensemble.')]
    public function isPeriodComplete(): bool
    {
        return (null === $this->startMonth) === (null === $this->endMonth);
    }

    public function isBlank(): bool
    {
        // * l'unité seule ne fait pas un créneau : le formulaire la présélectionne dès qu'on choisit un produit
        return null === $this->startMonth && null === $this->endMonth && null === $this->quantity;
    }
}
