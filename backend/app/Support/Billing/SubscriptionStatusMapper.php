<?php

namespace App\Support\Billing;

/**
 * The one place a raw Stripe subscription status string is translated into
 * SureSign's own SubscriptionStatus vocabulary. Nothing else in the
 * application should compare against a Stripe status string directly —
 * that comparison belongs here so a future change to how, say, `unpaid`
 * should be treated is a one-line change in one place, not a grep-and-fix
 * across controllers/services.
 *
 * draft/pending_payment/suspended have no Stripe equivalent and are never
 * produced by this mapper — they're set directly by SureSign's own service
 * logic (Phase 5+) before/around the Stripe object's lifecycle.
 *
 * **`incomplete_expired` -> `EXPIRED`, not `CANCELLED`** (changed in the
 * Subscription Event Hardening checkpoint — previously mapped to
 * `CANCELLED`). Stripe's own documentation describes `incomplete_expired`
 * as a genuinely terminal, non-commercial state ("the open invoice will be
 * voided and no further invoices will be generated") reached when a first
 * payment is never completed within the retry window — this is
 * semantically an abandoned/lapsed attempt, exactly what SureSign's own
 * `expired` status already means elsewhere (e.g. an abandoned
 * `pending_payment` window), not a deliberate commercial termination
 * (`cancelled`). See `App\Support\Billing\SubscriptionTransitions`' own
 * docblock for the corresponding `incomplete -> expired` transition-map
 * addition this required.
 */
class SubscriptionStatusMapper
{
    private const STRIPE_TO_INTERNAL = [
        'incomplete' => SubscriptionStatus::INCOMPLETE,
        'incomplete_expired' => SubscriptionStatus::EXPIRED,
        'trialing' => SubscriptionStatus::TRIALING,
        'active' => SubscriptionStatus::ACTIVE,
        'past_due' => SubscriptionStatus::PAST_DUE,
        'canceled' => SubscriptionStatus::CANCELLED,
        'unpaid' => SubscriptionStatus::UNPAID,
        'paused' => SubscriptionStatus::PAUSED,
    ];

    public static function fromStripeStatus(string $stripeStatus): string
    {
        return self::STRIPE_TO_INTERNAL[$stripeStatus] ?? throw new \InvalidArgumentException(
            "Unrecognised Stripe subscription status: {$stripeStatus}"
        );
    }

    public static function isKnownStripeStatus(string $stripeStatus): bool
    {
        return array_key_exists($stripeStatus, self::STRIPE_TO_INTERNAL);
    }
}
