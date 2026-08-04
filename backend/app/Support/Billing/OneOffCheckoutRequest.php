<?php

namespace App\Support\Billing;

/**
 * Consultancy Live Booking Upgrade, Stage 3 — the provider-neutral request
 * for a one-off (non-subscription) Stripe Checkout Session, using inline
 * `price_data` rather than a pre-registered recurring Price (which
 * BillingProviderInterface::createCheckoutSession() requires). Callers
 * (App\Services\Consultancy\ConsultancyCheckoutService) build this from an
 * already-created immutable commercial snapshot — never from a live
 * ConsultancyService model, and never from raw controller input.
 */
final class OneOffCheckoutRequest
{
    public function __construct(
        public readonly int $amountMinorUnits,
        public readonly string $currency,
        public readonly string $productName,
        public readonly ?string $productDescription,
        public readonly string $successUrl,
        public readonly string $cancelUrl,
        public readonly \DateTimeInterface $expiresAt,
        public readonly array $metadata,
        public readonly string $idempotencyKey,
    ) {
    }
}
