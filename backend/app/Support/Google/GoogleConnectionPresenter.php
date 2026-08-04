<?php

namespace App\Support\Google;

use App\Models\GoogleConnection;

/**
 * Google Integration Foundation, Stage 4A — the single place a
 * GoogleConnection is shaped for an API response. Never returns
 * access_token/refresh_token in any form — mirrors
 * App\Support\AI\AiAnalysisPresenter's categorical-exclusion discipline
 * for execution telemetry, applied here to OAuth secrets instead.
 */
class GoogleConnectionPresenter
{
    /**
     * @return array{connected: bool, status: ?string, connected_email: ?string, google_account_id: ?string, scopes: array<int, string>, connected_at: ?string, last_refreshed_at: ?string, last_successful_call_at: ?string, last_failed_call_at: ?string, last_failure_reason: ?string, consecutive_refresh_failures: ?int, connected_by: ?string, disconnected_at: ?string}
     */
    public static function diagnostics(?GoogleConnection $connection): array
    {
        if (!$connection) {
            return [
                'connected'                    => false,
                'status'                       => null,
                'connected_email'               => null,
                'google_account_id'             => null,
                'scopes'                        => [],
                'connected_at'                  => null,
                'last_refreshed_at'             => null,
                'last_successful_call_at'       => null,
                'last_failed_call_at'           => null,
                'last_failure_reason'           => null,
                'consecutive_refresh_failures'  => null,
                'connected_by'                  => null,
                'disconnected_at'               => null,
            ];
        }

        return [
            'connected'                    => $connection->isActive(),
            'status'                       => $connection->status,
            'connected_email'               => $connection->connected_email,
            'google_account_id'             => $connection->google_account_id,
            'scopes'                        => $connection->scopes ?? [],
            'connected_at'                  => $connection->connected_at?->toIso8601String(),
            'last_refreshed_at'             => $connection->last_refreshed_at?->toIso8601String(),
            'last_successful_call_at'       => $connection->last_successful_call_at?->toIso8601String(),
            'last_failed_call_at'           => $connection->last_failed_call_at?->toIso8601String(),
            'last_failure_reason'           => $connection->last_failure_reason,
            'consecutive_refresh_failures'  => $connection->consecutive_refresh_failures,
            'connected_by'                  => $connection->connectedBy?->name,
            'disconnected_at'               => $connection->disconnected_at?->toIso8601String(),
        ];
    }
}
