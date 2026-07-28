<?php

namespace App\Support\Billing;

/**
 * Allow-listed payment providers. Only 'stripe' is implemented — kept as a
 * list rather than a single constant so BillingProviderManager (Phase 4)
 * and the provider-scoped unique constraints already in the Phase 1
 * migrations have one shared place to validate against.
 */
class BillingProviders
{
    public const STRIPE = 'stripe';

    /**
     * G4B.2 — the `Subscription.provider` value for a `manual`/
     * `complimentary`-source row (App\Support\Billing\SubscriptionSource):
     * no billing integration is authoritative for it at all. Deliberately
     * NOT included in ALL — that list is "configured providers"
     * (BillingProviderManager::configuredProvider()'s validation), a
     * different question from what a non-Stripe subscription row stores
     * in this column.
     */
    public const NONE = 'none';

    public const ALL = [
        self::STRIPE,
    ];

    public static function isSupported(string $provider): bool
    {
        return in_array($provider, self::ALL, true);
    }
}
