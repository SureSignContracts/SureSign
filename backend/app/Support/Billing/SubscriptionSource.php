<?php

namespace App\Support\Billing;

/**
 * Phase G4B.1 — a Subscription's commercial ORIGIN, distinct from both:
 *   - `Subscription::$provider` (which billing integration, if any, is
 *     authoritative for this row — today always 'stripe', see
 *     `App\Support\Billing\BillingProviders`), and
 *   - `Subscription::$status` (`SubscriptionStatus` — the row's current
 *     lifecycle state, e.g. trialing/active/cancelled).
 *
 * A subscription can be `active` (status) via Stripe (provider) with
 * source `stripe`, `manual`, or (once G4B.2 builds the write path)
 * `complimentary` — all three are equally real commercial relationships
 * from `SubscriptionAccessPolicy`/`FeatureGate`'s point of view, which
 * must keep resolving purely from status/snapshot and never branch on
 * source (see those classes' own docblocks).
 *
 * Deliberately does NOT include:
 *   - `testing` — temporary QA/simulation state belongs to an
 *     organisation-level test marker and temporary override records (a
 *     later phase), never to a real Subscription row's permanent origin.
 *   - `trial` — already fully represented by `SubscriptionStatus::TRIALING`,
 *     a lifecycle state, not an origin.
 *   - `legacy` — every row created before this column existed is
 *     genuinely `stripe`-origin (see the creating migration's backfill and
 *     `SubscriptionLifecycleService::createDraftSubscription()`, the sole
 *     production creation path, which only ever runs against a real Stripe
 *     provider price mapping). "Legacy" data-quality concerns belong to
 *     `SnapshotIntegrityClassifier`, not to a fabricated fourth source
 *     value.
 *
 * Write-once: see `Subscription::booted()`'s guard against changing this
 * after creation. A subscription that starts `manual` and later becomes a
 * real paying Stripe customer must be ended and replaced by a NEW,
 * `stripe`-source Subscription — never converted in place (G4B.2+, not
 * yet built).
 */
class SubscriptionSource
{
    public const STRIPE = 'stripe';
    public const MANUAL = 'manual';
    public const COMPLIMENTARY = 'complimentary';

    public const ALL = [
        self::STRIPE,
        self::MANUAL,
        self::COMPLIMENTARY,
    ];

    public static function isValid(string $source): bool
    {
        return in_array($source, self::ALL, true);
    }
}
