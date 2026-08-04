<?php

namespace App\Services\Google;

use App\Models\ActivityLog;
use App\Models\GoogleConnection;
use App\Models\User;

/**
 * Google Integration Foundation, Stage 4A — resolves the current active
 * Google connection and owns disconnection. The single place any other
 * service asks "is there a connected Google account, and which one."
 */
class GoogleConnectionService
{
    public function __construct(private readonly GoogleApiClientInterface $apiClient)
    {
    }

    public function current(): ?GoogleConnection
    {
        return GoogleConnection::where('provider', 'google')
            ->where('purpose', 'primary')
            ->where('status', 'connected')
            ->latest('id')
            ->first();
    }

    /**
     * Revokes the token at Google (best-effort — local state is
     * authoritative regardless, mirroring
     * App\Services\Billing\CheckoutSessionService::cancelPendingCheckout()'s
     * identical "best-effort provider call, local state always wins"
     * convention), clears the stored secrets, and marks the row
     * 'disconnected'. The row itself is never deleted — it remains as
     * connection history. Historical Google Calendar event/Meet IDs
     * already stored elsewhere (Stage 4B) are never touched by this
     * method at all.
     */
    public function disconnect(User $actor): ?GoogleConnection
    {
        $connection = $this->current();
        if (!$connection) {
            return null;
        }

        try {
            if ($connection->access_token) {
                $this->apiClient->revokeToken($connection->access_token);
            }
        } catch (\Throwable) {
            // Best-effort only — local disconnection proceeds regardless.
        }

        $connection->update([
            'status'                  => 'disconnected',
            'access_token'            => null,
            'refresh_token'           => null,
            'disconnected_by_user_id' => $actor->id,
            'disconnected_at'         => now(),
        ]);

        ActivityLog::record(
            'google.disconnected',
            'Google account disconnected.',
            $actor,
            $connection,
            [],
        );

        return $connection->fresh();
    }
}
