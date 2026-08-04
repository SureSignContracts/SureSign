<?php

namespace App\Services\Google;

use App\Models\GoogleConnection;
use App\Support\Google\GoogleConnectionHealth;
use App\Support\Google\GoogleScopes;

/**
 * Google Integration Foundation, Stage 4A — computes the real, multi-state
 * health of the current Google connection from actual stored state. Never
 * makes a live Google API call itself (that's
 * CalendarProviderInterface::testConnection()'s job) — this reports the
 * last-known state cheaply, which is what "use cached health where
 * appropriate" / "avoid... blocking requests" means in practice for the
 * Admin diagnostics page's normal load.
 */
class GoogleHealthService
{
    public function __construct(private readonly GoogleConnectionService $connectionService)
    {
    }

    /**
     * @return array{state: string, connection: ?GoogleConnection, missing_scopes: array<int, string>}
     */
    public function currentHealth(): array
    {
        $connection = $this->connectionService->current();

        if (!$connection) {
            return ['state' => GoogleConnectionHealth::NOT_CONNECTED, 'connection' => null, 'missing_scopes' => []];
        }

        if ($connection->consecutive_refresh_failures >= GoogleConnectionHealth::REFRESH_FAILURE_THRESHOLD) {
            return ['state' => GoogleConnectionHealth::REFRESH_FAILED, 'connection' => $connection, 'missing_scopes' => []];
        }

        $missingScopes = array_values(array_diff(GoogleScopes::REQUIRED, $connection->scopes ?? []));
        if (!empty($missingScopes)) {
            return ['state' => GoogleConnectionHealth::PERMISSIONS_MISSING, 'connection' => $connection, 'missing_scopes' => $missingScopes];
        }

        if ($this->lastCallIndicatesCalendarUnavailable($connection)) {
            return ['state' => GoogleConnectionHealth::CALENDAR_UNAVAILABLE, 'connection' => $connection, 'missing_scopes' => []];
        }

        if ($connection->isTokenExpired()) {
            return ['state' => GoogleConnectionHealth::TOKEN_EXPIRED, 'connection' => $connection, 'missing_scopes' => []];
        }

        if ($connection->last_successful_call_at !== null) {
            return ['state' => GoogleConnectionHealth::HEALTHY, 'connection' => $connection, 'missing_scopes' => []];
        }

        // Connected, never yet verified by a real API call (test
        // connection has never been run) — deliberately NOT assumed
        // healthy.
        return ['state' => GoogleConnectionHealth::CONNECTED, 'connection' => $connection, 'missing_scopes' => []];
    }

    /**
     * The most recent recorded outcome was a failure specifically
     * classified as calendar-unavailable (see
     * App\Services\Calendar\GoogleCalendarProvider::classifyFailure()),
     * and no later success has superseded it.
     */
    private function lastCallIndicatesCalendarUnavailable(GoogleConnection $connection): bool
    {
        if ($connection->last_failed_call_at === null || $connection->last_failure_reason === null) {
            return false;
        }
        if (!str_starts_with($connection->last_failure_reason, 'calendar_unavailable:')) {
            return false;
        }

        return $connection->last_successful_call_at === null
            || $connection->last_failed_call_at->greaterThan($connection->last_successful_call_at);
    }
}
