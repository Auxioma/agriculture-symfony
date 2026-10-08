<?php

namespace App\Service\Matching;

use App\Entity\Identity\User;
use App\Entity\Matching\RequestMatch;
use App\Enum\NeedType;

/**
 * Une demande disponible telle que le producteur la voit (dashboard et liste des demandes). Seuils choisis par
 * nous, à faire valider par le client : "nouveau" = reçue depuis moins de 2 jours, "volume élevé" = 50 ou plus
 * (toutes unités confondues). Le modèle n'a que NeedType::Professional pour dire qu'un client est un
 * professionnel, tout le reste compte comme particulier.
 */
final class AvailableRequestPresenter
{
    private const NEW_SINCE = '-2 days';
    private const HIGH_VOLUME_FROM = 50;

    /**
     * "Camille R." : seule l'initiale du nom, le producteur n'a pas besoin de plus à ce stade.
     */
    public function clientName(User $client): string
    {
        $lastName = $client->getLastName();

        return trim(($client->getFirstName() ?? '').($lastName ? ' '.mb_substr($lastName, 0, 1).'.' : '')) ?: 'Client';
    }

    /**
     * @return array<string, mixed>
     */
    public function present(RequestMatch $match): array
    {
        $request = $match->getRequest();
        $quantity = $request->getQuantity() !== null ? (float) $request->getQuantity() : null;

        return [
            'requestId' => $request->getId()->toRfc4122(),
            'clientName' => $this->clientName($request->getClient()),
            'product' => $request->getProduct()?->getName() ?? $request->getCustomProduct(),
            'quantity' => $quantity,
            'unit' => $request->getUnit()?->getCode(),
            'budgetMax' => $request->getBudgetMax() !== null ? (float) $request->getBudgetMax() : null,
            'currency' => $request->getCurrency()?->getSymbol() ?? $request->getCurrency()?->getCode(),
            'city' => $request->getCity(),
            'distanceKm' => $match->getDistanceKm() !== null ? (float) $match->getDistanceKm() : null,
            'message' => $request->getMessage(),
            'urgent' => $request->getUrgencyLevel() > 0,
            'clientType' => $request->getNeedType() === NeedType::Professional ? 'professional' : 'individual',
            'isNew' => $match->getCreatedAt() > new \DateTimeImmutable(self::NEW_SINCE),
            'highVolume' => $quantity !== null && $quantity >= self::HIGH_VOLUME_FROM,
        ];
    }
}
