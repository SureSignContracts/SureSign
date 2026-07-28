<?php

namespace App\Support\Billing;

/**
 * The controlled vocabulary of "who/what initiated this lifecycle
 * transition" — carried on every App\Services\Billing\TransitionContext.
 * Never an arbitrary caller-supplied string; SubscriptionLifecycleService
 * validates every context's source against this list.
 */
class TransitionSource
{
    public const SUPER_ADMIN = 'super_admin';
    public const CHECKOUT = 'checkout';
    public const VERIFIED_WEBHOOK = 'verified_webhook';
    public const SCHEDULED_COMMAND = 'scheduled_command';
    public const PROVIDER_RECONCILIATION = 'provider_reconciliation';
    public const SYSTEM_MIGRATION = 'system_migration';
    public const MANUAL_CORRECTION = 'manual_correction';

    /**
     * An authenticated organisation member's own self-service Billing
     * action (Stripe Sandbox Plan-Change checkpoint — requesting an
     * upgrade/downgrade, or cancelling a pending one) — distinct from
     * `CHECKOUT`, which names the first-subscription Checkout flow
     * specifically, not customer self-service in general.
     */
    public const CUSTOMER_BILLING_ACTION = 'customer_billing_action';

    public const ALL = [
        self::SUPER_ADMIN,
        self::CHECKOUT,
        self::VERIFIED_WEBHOOK,
        self::SCHEDULED_COMMAND,
        self::PROVIDER_RECONCILIATION,
        self::SYSTEM_MIGRATION,
        self::MANUAL_CORRECTION,
        self::CUSTOMER_BILLING_ACTION,
    ];

    public static function isValid(string $source): bool
    {
        return in_array($source, self::ALL, true);
    }
}
