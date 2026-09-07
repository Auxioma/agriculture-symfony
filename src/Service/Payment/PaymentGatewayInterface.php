<?php

namespace App\Service\Payment;

interface PaymentGatewayInterface
{
    public function createCheckoutSession(string $priceId, string $customerEmail, array $metadata, string $successUrl, string $cancelUrl): string;

    public function updateSubscriptionPrice(string $providerSubscriptionId, string $newPriceId): void;
}