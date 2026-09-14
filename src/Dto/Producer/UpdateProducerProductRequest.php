<?php

namespace App\Dto\Producer;

/**
 * Payload attendu par PUT /api/producer/products/{id}
 *
 * * Aucun champ requis (pas de #[Assert\NotBlank]) malgré la méthode PUT : productId n'est volontairement
 * * pas repris ici (on ne change pas le produit catalogue d'une fiche existante, seulement ses attributs),
 * * et tout le reste peut légitimement être remis à vide (variety, description...).
 */
final readonly class UpdateProducerProductRequest
{
    public function __construct(
        public ?string $variety = null,
        public ?string $description = null,
        public ?string $estimatedVolume = null,
        public ?string $defaultPrice = null,
        public ?string $currencyCode = null,
        public bool $isActive = true,
    ) {
    }
}