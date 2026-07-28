<?php

namespace App\Support\Intelligence;

/**
 * Phase G3 — the small, fixed health vocabulary the Subscription
 * Intelligence Centre uses to colour-code a usage entitlement or an
 * overall subscription/billing/Stripe signal. Deliberately separate from
 * `App\Support\Entitlements\EnforcementLevel` — enforcement describes how
 * strictly a value WOULD be applied (a Feature registry concept, static
 * per key); health describes the CURRENT computed state of one
 * organisation's actual usage against that value (dynamic, computed here).
 * Nothing in this vocabulary implies or performs enforcement.
 */
class EntitlementHealthStatus
{
    /** No applicable usage data, or the key doesn't apply to this subscription. */
    public const UNKNOWN = 'unknown';

    /** Comfortably within allowance, or unlimited. */
    public const HEALTHY = 'healthy';

    /** >= 80% of a finite allowance consumed. */
    public const WARNING = 'warning';

    /** >= 95% of a finite allowance consumed. */
    public const CRITICAL = 'critical';

    /** >= 100% of a finite allowance consumed. */
    public const EXCEEDED = 'exceeded';

    public const ALL = [
        self::UNKNOWN,
        self::HEALTHY,
        self::WARNING,
        self::CRITICAL,
        self::EXCEEDED,
    ];

    /**
     * The three near-limit thresholds Stage 8 asks for (80/90/95 are
     * "detect approaching", 100 is "exceeded") — 90% is folded into the
     * WARNING band (a genuinely distinct UI treatment for 90 vs 80 was not
     * requested; the recommendation copy still calls out the exact
     * percentage, see SubscriptionRecommendationService).
     */
    public static function forPercentUsed(?float $percentUsed): string
    {
        if ($percentUsed === null) {
            return self::HEALTHY; // unlimited or not applicable
        }

        return match (true) {
            $percentUsed >= 100 => self::EXCEEDED,
            $percentUsed >= 95 => self::CRITICAL,
            $percentUsed >= 80 => self::WARNING,
            default => self::HEALTHY,
        };
    }
}
