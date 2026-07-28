<?php

namespace App\Support\Billing;

/**
 * SureSign's own internal subscription status vocabulary — deliberately not
 * a 1:1 mirror of Stripe's subscription statuses (incomplete,
 * incomplete_expired, trialing, active, past_due, canceled, unpaid, paused).
 * draft/pending_payment/suspended/expired have no Stripe equivalent at all:
 * draft/pending_payment precede any Stripe object existing, suspended is a
 * manual SureSign-only decision Stripe never knows about, and expired is our
 * own post-cancellation bookkeeping state. See SubscriptionStatusMapper for
 * the only place Stripe strings are translated into this vocabulary.
 */
class SubscriptionStatus
{
    public const DRAFT = 'draft';
    public const PENDING_PAYMENT = 'pending_payment';
    public const INCOMPLETE = 'incomplete';
    public const TRIALING = 'trialing';
    public const ACTIVE = 'active';
    public const PAST_DUE = 'past_due';
    public const UNPAID = 'unpaid';
    public const PAUSED = 'paused';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';
    public const SUSPENDED = 'suspended';

    public const ALL = [
        self::DRAFT,
        self::PENDING_PAYMENT,
        self::INCOMPLETE,
        self::TRIALING,
        self::ACTIVE,
        self::PAST_DUE,
        self::UNPAID,
        self::PAUSED,
        self::CANCELLED,
        self::EXPIRED,
        self::SUSPENDED,
    ];

    /**
     * Statuses under which the organisation is normally considered to have a
     * live (pending or active) subscription — used by SubscriptionService
     * (Phase 5+) to enforce "only one primary pending/active subscription
     * per organisation". Kept here, not duplicated in that service, so the
     * definition of "live" has exactly one source.
     */
    public const LIVE = [
        self::DRAFT,
        self::PENDING_PAYMENT,
        self::INCOMPLETE,
        self::TRIALING,
        self::ACTIVE,
        self::PAST_DUE,
        self::UNPAID,
        self::PAUSED,
    ];

    /**
     * Statuses that always represent an existing, unresolved commercial
     * relationship and therefore block a fresh Checkout attempt — the same
     * list `SubscriptionLifecycleService::hasConflictingSubscription()`
     * enforces (see that method's docblock for the full conflict matrix,
     * including `draft`'s own separate checkout-session-based rule).
     * Deliberately excludes `cancelled`/`expired`: both are terminal and
     * historical, whether the subscription ever activated or was cancelled
     * before any payment was taken (Phase E6 — see BillingOverviewService's
     * `isAbandonedCheckout()`). Kept here, not duplicated, so
     * `hasConflictingSubscription()` and any presentation-layer "can this
     * organisation start a new Checkout" check share one definition.
     */
    public const BLOCKS_NEW_CHECKOUT = [
        self::TRIALING,
        self::PENDING_PAYMENT,
        self::INCOMPLETE,
        self::ACTIVE,
        self::PAST_DUE,
        self::UNPAID,
        self::PAUSED,
        self::SUSPENDED,
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function isLive(string $status): bool
    {
        return in_array($status, self::LIVE, true);
    }

    public static function blocksNewCheckout(string $status): bool
    {
        return in_array($status, self::BLOCKS_NEW_CHECKOUT, true);
    }
}
