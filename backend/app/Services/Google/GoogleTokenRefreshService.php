<?php

namespace App\Services\Google;

use App\Models\ActivityLog;
use App\Models\GoogleConnection;
use App\Support\Google\GoogleConnectionHealth;

/**
 * Google Integration Foundation, Stage 4A — lazy, on-demand access-token
 * refresh via GoogleApiClientInterface. Deliberately NOT scheduled and NOT
 * run on every request: a refresh is attempted only immediately before an
 * actual outbound Google API call needs a token, and only when the stored
 * token is at or past its recorded expiry. Reading stored diagnostics/
 * health for the Admin page never triggers a refresh or any live Google
 * call — see App\Services\Google\GoogleHealthService, which reports the
 * last-known state instead.
 *
 * Repeated failures move the connection to `refresh_failed` (see
 * App\Support\Google\GoogleConnectionHealth::REFRESH_FAILURE_THRESHOLD)
 * rather than retrying indefinitely — "attempt retry, if still failing
 * mark unhealthy, do not repeatedly spam Google" is satisfied by this
 * lazy-per-call-site trigger itself: there is no background retry loop to
 * runaway in the first place, since a refresh is only ever attempted when
 * a real caller genuinely needs a fresh token.
 */
class GoogleTokenRefreshService
{
    public function __construct(private readonly GoogleApiClientInterface $apiClient)
    {
    }

    /**
     * @throws \RuntimeException if the connection has no refresh token, or Google rejects the refresh
     */
    public function ensureFreshAccessToken(GoogleConnection $connection): string
    {
        if (!$connection->isTokenExpired()) {
            return $connection->access_token;
        }

        if (!$connection->refresh_token) {
            throw new \RuntimeException('This Google connection has no refresh token — reconnection is required.');
        }

        try {
            $newToken = $this->apiClient->refreshAccessToken($connection->refresh_token);

            $wasFailing = $connection->consecutive_refresh_failures > 0;

            $connection->update([
                'access_token'                 => $newToken['access_token'],
                'token_expires_at'             => now()->addSeconds((int) ($newToken['expires_in'] ?? 3600)),
                'last_refreshed_at'            => now(),
                'consecutive_refresh_failures' => 0,
            ]);

            if ($wasFailing) {
                ActivityLog::record('google.refresh_recovered', 'Google token refresh recovered after previous failures.', null, $connection, []);
            }

            return $newToken['access_token'];
        } catch (\Throwable $e) {
            $connection->increment('consecutive_refresh_failures');
            $connection->update(['last_failure_reason' => 'refresh_failed: ' . $e->getMessage()]);

            if ($connection->fresh()->consecutive_refresh_failures === GoogleConnectionHealth::REFRESH_FAILURE_THRESHOLD) {
                ActivityLog::record('google.refresh_failed', 'Google token refresh failed repeatedly — connection is now unhealthy.', null, $connection, []);
            }

            throw new \RuntimeException('Unable to refresh the Google connection. Reconnection may be required.', previous: $e);
        }
    }
}
