<?php

namespace App\Support\Billing;

/**
 * The subscription lifecycle transition map validated in the Phase 0
 * report and extended in the SubscriptionLifecycleService checkpoint.
 * canTransition() is the one place "is this status change allowed" is
 * answered; SubscriptionLifecycleService must consult this rather than
 * re-deriving its own rules.
 *
 * grace_period, suspension_pending, and cancel_at_period_end are
 * deliberately NOT statuses — the existing status model already
 * represents those concepts through fields rather than a distinct status
 * (past_due + grace_period_ends_at; suspended is reached directly, with
 * "scheduling" recorded via ActivityLog/suspension_reason rather than an
 * intermediate status; cancel_at_period_end is a boolean scheduling flag
 * on an otherwise-ACTIVE subscription, not a status of its own). See
 * SubscriptionLifecycleService's class docblock and
 * internal-docs/super-admin/subscription-billing.md for the full
 * rationale — this was a deliberate retention, not an oversight.
 *
 * Additions beyond the original Phase 0 map, each tied to a lifecycle
 * concept this checkpoint's design review found genuinely missing:
 *   - incomplete -> active: a Stripe subscription stuck in `incomplete`
 *     (first payment failed) can still succeed via a 3D Secure retry: this
 *     needs the same recovery path past_due -> active already has.
 *   - unpaid -> suspended: past_due -> suspended was specified; unpaid is a
 *     strictly worse collection state than past_due and needs the same
 *     manual-suspension escape hatch.
 *   - paused -> active: Stripe's own "pause collection" resume path.
 *   - draft -> trialing: a Super Admin-granted trial starts directly from
 *     draft, before any payment step — explicitly listed as a valid path
 *     in this checkpoint's approved lifecycle review.
 *   - draft -> cancelled: an operator may abandon a draft before it ever
 *     reaches a payment step.
 *   - trialing -> pending_payment: a trial converting to paid moves
 *     through the same pending-payment/checkout path a non-trial
 *     subscription does — trialing never jumps straight to active without
 *     going through a verified payment step (a future checkpoint's
 *     concern; this map only says the transition is valid).
 *   - trialing -> cancelled: a trial can be abandoned before conversion.
 *   - pending_payment -> expired: an abandoned checkout/payment window
 *     that's never completed and never explicitly cancelled expires
 *     rather than sitting in pending_payment forever.
 *   - suspended -> cancelled: a suspended subscription may still be
 *     terminated outright rather than only ever restored to active.
 *   - incomplete -> expired (added in the Subscription Event Hardening
 *     checkpoint): Stripe's `incomplete_expired` is a genuinely TERMINAL
 *     status per Stripe's own documentation ("the open invoice will be
 *     voided and no further invoices will be generated") — closer to
 *     SureSign's own `expired` (a lapsed, abandoned attempt) than
 *     `cancelled` (an active commercial relationship being deliberately
 *     ended). `SubscriptionStatusMapper::fromStripeStatus('incomplete_expired')`
 *     was changed to return `EXPIRED` accordingly (previously `CANCELLED`)
 *     — see that class's own docblock. `incomplete -> cancelled` is
 *     deliberately KEPT alongside this addition (not replaced) for the
 *     separate, real case of an `incomplete` subscription being explicitly
 *     cancelled (Stripe's `canceled` status, a distinct raw value from
 *     `incomplete_expired`).
 *
 * suspended/cancelled always require an operator-supplied reason —
 * SubscriptionLifecycleService enforces this at the method level; this map
 * only answers "is X -> Y ever valid", not "what else must be true/
 * recorded".
 */
class SubscriptionTransitions
{
    public const MAP = [
        SubscriptionStatus::DRAFT => [
            SubscriptionStatus::PENDING_PAYMENT,
            SubscriptionStatus::TRIALING,
            SubscriptionStatus::CANCELLED,
        ],
        SubscriptionStatus::PENDING_PAYMENT => [
            SubscriptionStatus::ACTIVE,
            SubscriptionStatus::INCOMPLETE,
            SubscriptionStatus::CANCELLED,
            SubscriptionStatus::EXPIRED,
        ],
        SubscriptionStatus::INCOMPLETE => [
            SubscriptionStatus::ACTIVE,
            SubscriptionStatus::CANCELLED,
            SubscriptionStatus::EXPIRED,
        ],
        SubscriptionStatus::TRIALING => [
            SubscriptionStatus::PENDING_PAYMENT,
            SubscriptionStatus::ACTIVE,
            SubscriptionStatus::EXPIRED,
            SubscriptionStatus::CANCELLED,
        ],
        SubscriptionStatus::ACTIVE => [
            SubscriptionStatus::PAST_DUE,
            SubscriptionStatus::CANCELLED,
            SubscriptionStatus::SUSPENDED,
        ],
        SubscriptionStatus::PAST_DUE => [
            SubscriptionStatus::ACTIVE,
            SubscriptionStatus::UNPAID,
            SubscriptionStatus::SUSPENDED,
        ],
        SubscriptionStatus::UNPAID => [
            SubscriptionStatus::SUSPENDED,
        ],
        SubscriptionStatus::PAUSED => [
            SubscriptionStatus::ACTIVE,
        ],
        SubscriptionStatus::CANCELLED => [
            SubscriptionStatus::EXPIRED,
        ],
        SubscriptionStatus::SUSPENDED => [
            SubscriptionStatus::ACTIVE,
            SubscriptionStatus::CANCELLED,
        ],
        SubscriptionStatus::EXPIRED => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return false;
        }

        return in_array($to, self::MAP[$from] ?? [], true);
    }

    /**
     * @return string[]
     */
    public static function allowedFrom(string $status): array
    {
        return self::MAP[$status] ?? [];
    }
}
