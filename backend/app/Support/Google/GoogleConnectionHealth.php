<?php

namespace App\Support\Google;

/**
 * Google Integration Foundation, Stage 4A — the real, multi-state health
 * model for a Google connection. Deliberately never reduced to a single
 * "connected: true/false" boolean — see
 * App\Services\Google\GoogleHealthService::currentHealth() for how each
 * state is derived from actual token validity, granted-scope comparison,
 * and recorded API-call outcomes.
 *
 * Priority order when more than one condition could apply (checked in this
 * exact order by GoogleHealthService): NOT_CONNECTED > REFRESH_FAILED >
 * PERMISSIONS_MISSING > CALENDAR_UNAVAILABLE > TOKEN_EXPIRED > HEALTHY,
 * falling back to CONNECTED (connected, but never yet successfully
 * verified via a real API call) rather than assuming HEALTHY prematurely.
 */
final class GoogleConnectionHealth
{
    public const NOT_CONNECTED = 'not_connected';
    public const CONNECTED = 'connected';
    public const TOKEN_EXPIRED = 'token_expired';
    public const REFRESH_FAILED = 'refresh_failed';
    public const PERMISSIONS_MISSING = 'permissions_missing';
    public const CALENDAR_UNAVAILABLE = 'calendar_unavailable';
    public const HEALTHY = 'healthy';

    public const ALL = [
        self::NOT_CONNECTED, self::CONNECTED, self::TOKEN_EXPIRED,
        self::REFRESH_FAILED, self::PERMISSIONS_MISSING,
        self::CALENDAR_UNAVAILABLE, self::HEALTHY,
    ];

    /**
     * The threshold at which repeated refresh failures stop being treated
     * as transient — see GoogleTokenRefreshService's own docblock for why
     * this is a bounded count, not an endless retry loop.
     */
    public const REFRESH_FAILURE_THRESHOLD = 3;
}
