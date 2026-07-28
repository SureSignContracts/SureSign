<?php

namespace App\Support\Entitlements;

/**
 * Entitlement Specification v1, Section 13 — how strictly an entitlement
 * is (eventually) applied, distinct from its resolved *value*. Purely
 * descriptive metadata in this checkpoint: nothing in the codebase reads
 * this to actually block, warn, or restrict anything yet — see
 * `Feature::enforcementLevel()` and the checkpoint's explicit "no
 * enforcement" scope.
 */
class EnforcementLevel
{
    /** Displayed, never restricts anything (e.g. priority_support's SLA). */
    public const INFORMATIONAL = 'informational';

    /** The customer/operator sees a clear warning; nothing is blocked. */
    public const WARNING = 'warning';

    /** Warned and may require an explicit action to proceed; not blocked outright. */
    public const SOFT_LIMIT = 'soft_limit';

    /** A Super Admin action is required to proceed further. */
    public const APPROVAL_REQUIRED = 'approval_required';

    /** The action is blocked outright. */
    public const HARD_LIMIT = 'hard_limit';

    /** The feature simply isn't present for this subscription (feature flags only). */
    public const UNAVAILABLE = 'unavailable';

    public const ALL = [
        self::INFORMATIONAL,
        self::WARNING,
        self::SOFT_LIMIT,
        self::APPROVAL_REQUIRED,
        self::HARD_LIMIT,
        self::UNAVAILABLE,
    ];

    public static function isValid(string $level): bool
    {
        return in_array($level, self::ALL, true);
    }
}
