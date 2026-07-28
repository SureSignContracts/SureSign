<?php

namespace App\Support\Billing;

/**
 * Stripe Test Mode Integration checkpoint, Part 22/23 — the controlled
 * vocabulary `App\Services\Billing\StripeReconciliationService` reports
 * per subscription scanned.
 */
class ReconciliationFinding
{
    public const HEALTHY = 'healthy';
    public const LOCAL_ONLY = 'local_only';
    public const PROVIDER_SUBSCRIPTION_DELETED = 'provider_subscription_deleted';
    public const MODE_MISMATCH = 'mode_mismatch';
    public const CUSTOMER_MISMATCH = 'customer_mismatch';
    public const PRICE_MISMATCH = 'price_mismatch';
    public const UNKNOWN_PRICE = 'unknown_price';

    /**
     * Billing Architecture Audit + Slice E1 checkpoint — local
     * `cancel_at_period_end` disagrees with the provider's own reported
     * value, for a subscription that is ACTIVE both locally and at the
     * provider (an active/inactive disagreement is already caught
     * earlier as a status mismatch, not here). Never auto-repaired —
     * always surfaced for explicit operator review, exactly like
     * PRICE_MISMATCH/UNKNOWN_PRICE.
     */
    public const CANCELLATION_STATE_MISMATCH = 'cancellation_state_mismatch';
    public const PENDING_CHANGE_CONFIRMED = 'pending_change_confirmed';
    public const PENDING_CHANGE_STALE = 'pending_change_stale';
    public const MISSING_SNAPSHOT = 'missing_snapshot';
    public const RETRYABLE_ERROR = 'retryable_error';
    public const TERMINAL_ERROR = 'terminal_error';

    public const ALL = [
        self::HEALTHY,
        self::LOCAL_ONLY,
        self::PROVIDER_SUBSCRIPTION_DELETED,
        self::MODE_MISMATCH,
        self::CUSTOMER_MISMATCH,
        self::PRICE_MISMATCH,
        self::UNKNOWN_PRICE,
        self::CANCELLATION_STATE_MISMATCH,
        self::PENDING_CHANGE_CONFIRMED,
        self::PENDING_CHANGE_STALE,
        self::MISSING_SNAPSHOT,
        self::RETRYABLE_ERROR,
        self::TERMINAL_ERROR,
    ];
}
