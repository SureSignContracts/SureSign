<?php

namespace App\Support\Entitlements;

/**
 * Where a resolved entitlement value came from — Entitlement
 * Specification v1, Section 8's `source` column. This checkpoint does not
 * persist `subscription_entitlements` rows yet (see
 * `internal-docs/super-admin/subscription-billing.md`'s Plan Entitlements
 * checkpoint for why), but `FeatureGate`'s resolution logic already
 * reports which of these applied for a given decision — see
 * `EntitlementDecision`.
 */
class EntitlementSource
{
    /** Copied unmodified from the subscription's plan at creation. */
    public const PLAN_DEFAULT = 'plan_default';

    /** Set via an explicit Enterprise/negotiated action. */
    public const NEGOTIATED_OVERRIDE = 'negotiated_override';

    /** Set because an administrative migration moved the subscription. */
    public const MIGRATION = 'migration';

    /** A Super Admin fix to an incorrect value — not a negotiated change. */
    public const MANUAL_CORRECTION = 'manual_correction';

    /** Set as part of a temporary promotional offer. */
    public const PROMOTION = 'promotion';

    /** Set specifically for a trial subscription's entitlement profile. */
    public const TRIAL = 'trial';

    /**
     * No commercial relationship exists yet (no subscription, or one in a
     * pre-activation/non-entitled status) — not one of the Specification's
     * six named sources, since nothing was actually resolved from
     * anywhere; added here so `EntitlementDecision::source` is never null
     * or a magic string when `FeatureGate` has nothing to resolve from.
     */
    public const NONE = 'none';

    public const ALL = [
        self::PLAN_DEFAULT,
        self::NEGOTIATED_OVERRIDE,
        self::MIGRATION,
        self::MANUAL_CORRECTION,
        self::PROMOTION,
        self::TRIAL,
        self::NONE,
    ];

    public static function isValid(string $source): bool
    {
        return in_array($source, self::ALL, true);
    }
}
