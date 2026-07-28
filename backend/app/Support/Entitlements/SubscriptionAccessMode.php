<?php

namespace App\Support\Entitlements;

/**
 * The smallest set of commercial access modes that accurately represents
 * approved policy — deliberately NOT one mode per `SubscriptionStatus`
 * (eleven statuses collapse into five modes). See
 * `App\Services\Entitlements\SubscriptionAccessPolicy` for the full
 * status-to-mode matrix and internal-docs/super-admin/subscription-billing.md's
 * "Subscription Lifecycle and Entitlement Access Policy Review" section
 * for the complete commercial reasoning behind each mapping.
 */
class SubscriptionAccessMode
{
    /**
     * No commercial relationship is currently active — no subscription at
     * all, a pre-activation status (`draft`/`pending_payment`/`incomplete`),
     * or a defensive fail-safe fallback for an unrecognised/inconsistent
     * status. `FeatureGate` resolves every entitlement as "not entitled" in
     * this mode. Never blocks viewing/downloading existing records — that
     * guarantee (Entitlement Specification v1 §15) is orthogonal to this
     * mode entirely, not implemented by it.
     */
    public const NONE = 'none';

    /**
     * The dedicated trial entitlement profile (Section 17) — never a
     * standard plan's defaults, regardless of which plan was selected for
     * eventual conversion.
     */
    public const TRIAL = 'trial';

    /**
     * The subscription's full plan entitlement set resolves normally.
     * Covers `active`, including an `active` subscription with
     * `cancel_at_period_end = true` — a scheduled cancellation does not
     * reduce this mode until the status itself actually changes (see
     * "Scheduled actions" in the checkpoint documentation for why
     * `FeatureGate` never needs to inspect `cancel_at_period_end` or any
     * effective-date field directly).
     */
    public const FULL = 'full';

    /**
     * A temporary payment-collection problem (`past_due`) that has not
     * (yet) exceeded its grace window — resolves the SAME entitlement
     * values as `FULL` today (Entitlement Specification v1 §16: "a
     * temporary payment hiccup should not immediately disrupt compliance
     * work"), kept as a distinct mode so a future support/UI surface can
     * message it differently ("your payment needs attention") without
     * any entitlement-value difference, and so a future grace-duration
     * policy has a mode to change the behaviour of without renaming
     * anything.
     */
    public const GRACE = 'grace';

    /**
     * No paid entitlements resolve — `unpaid`, `suspended`, `cancelled`,
     * `expired`, and a `past_due` subscription whose grace window has
     * technically elapsed (see `SubscriptionAccessPolicy`'s grace-expiry
     * check). Deliberately a single mode rather than one each: today,
     * every one of these statuses has an IDENTICAL entitlement-resolution
     * consequence (no paid entitlements), even though their commercial
     * *meaning* differs (see the full matrix). A future, dedicated
     * lifecycle/access-policy review (Entitlement Specification v1 §16/24,
     * still explicitly open) may split this further — e.g. a genuine
     * read-only UI mode — but no such split is implemented or assumed
     * here. Existing-record viewing is NEVER gated by this mode (§15).
     */
    public const RESTRICTED = 'restricted';

    public const ALL = [
        self::NONE,
        self::TRIAL,
        self::FULL,
        self::GRACE,
        self::RESTRICTED,
    ];

    /**
     * Whether `FeatureGate` may consult a manual/negotiated override at
     * all while in this mode — Part 10's explicit safety requirement:
     * "the safest default should prevent a normal feature grant from
     * silently bypassing a suspension or terminal lifecycle state." An
     * override is only ever consulted on TOP of an already-granted
     * commercial relationship (`FULL`/`GRACE`/`TRIAL`) — never to
     * resurrect one that the access mode says doesn't currently exist.
     * A future "emergency access grant" category (Part 10) would need to
     * be a deliberately separate, explicitly-flagged mechanism — not a
     * loosening of this default.
     */
    public static function allowsOverrides(string $mode): bool
    {
        return in_array($mode, [self::FULL, self::GRACE, self::TRIAL], true);
    }

    public static function isValid(string $mode): bool
    {
        return in_array($mode, self::ALL, true);
    }
}
