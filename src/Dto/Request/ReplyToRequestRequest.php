<?php

namespace App\Dto\Request;

/**
 * Payload attendu par POST /api/producer/requests/{id}/reply
 *
 * ! Tous les champs sont optionnels ici, y compris replyText et priceAmount -- la règle "l'un des deux est
 * ! requis" n'est pas exprimée en attribut de validation, elle est vérifiée à la main en tout début de
 * ! ProducerRequestController::replyToRequest() (avant même la vérification des droits d'abonnement).
 *
 * draft = true : "Enregistrer le brouillon" (cahier fonctionnel : "Sauvegarder un brouillon"), la réponse reste préparée mais
 * non envoyée, sans notification ni conversation, et rien n'est obligatoire.
 */
final readonly class ReplyToRequestRequest
{
    public function __construct(
        public ?string $replyText = null,
        public ?string $priceAmount = null,
        public ?string $priceUnitId = null,
        public ?string $currencyCode = null,
        public ?\DateTimeImmutable $availabilityDate = null,
        public ?\DateTimeImmutable $validUntil = null,
        public ?string $conditions = null,
        public ?string $availableQuantity = null,
        public ?string $pickupConditions = null,
        public ?string $deliveryConditions = null,
        public bool $draft = false,
    ) {
    }
}