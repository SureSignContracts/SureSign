<?php

namespace App\Support\FeatureAvailability;

/**
 * The three effective states a registered feature can be in. Deliberately a
 * plain class with string constants — matching this codebase's existing
 * vocabulary convention (App\Support\Entitlements\Feature,
 * App\Support\AI\AiCreditOperatingMode, SubscriptionStatus, etc.) rather
 * than a native PHP `enum`, which no part of this codebase uses yet.
 *
 * ACTIVE is both the default and the fail-safe target: an unrecognised or
 * corrupt stored status value, an unregistered feature key, or a lookup
 * failure must all resolve here — never toward MAINTENANCE/COMING_SOON. See
 * FeatureAvailabilityService for where this is enforced.
 */
final class FeatureAvailabilityStatus
{
    public const ACTIVE = 'active';
    public const MAINTENANCE = 'maintenance';
    public const COMING_SOON = 'coming_soon';

    public const ALL = [
        self::ACTIVE,
        self::MAINTENANCE,
        self::COMING_SOON,
    ];

    public const DEFAULT = self::ACTIVE;

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    /**
     * Normalizes any stored/requested value to a known-valid status,
     * falling back to the safe default (ACTIVE) for anything unrecognised.
     * This is the ONE place that fallback decision is made — callers should
     * never re-implement the "unknown → active" rule themselves.
     */
    public static function normalize(?string $status): string
    {
        return $status !== null && self::isValid($status) ? $status : self::DEFAULT;
    }
}
