<?php

namespace App\Dto\Engagement;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par POST /api/favorites, désérialisé et validé automatiquement par
 * #[MapRequestPayload] dans FavoriteController::addFavorite(). targetType reprend les valeurs
 * documentées sur Favorite::$targetType (cahier fonctionnel, dashboard client : "Producteurs,
 * produits, catégories").
 */

final readonly class AddFavoriteRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['producer_profile', 'product', 'category'])]
        public string $targetType,

        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $targetId,
    ) {
    }
}
