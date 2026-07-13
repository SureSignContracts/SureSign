<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-checks account status on every authenticated request — auth:sanctum
 * only proves the token was valid at issuance, it does not re-verify the
 * account is still active/unbanned. Without this, a deactivated or banned
 * user's previously-issued token keeps working indefinitely (Sanctum tokens
 * never expire by default — see config/sanctum.php's `expiration => null`).
 *
 * When blocked, the current token is revoked immediately, so only the
 * first request on a stale token ever reaches this far — every subsequent
 * request fails at the auth:sanctum layer itself (401) before this
 * middleware runs again. This is intentional, not a bug: it means a
 * revoked account's token becomes fully dead after one rejected attempt,
 * rather than perpetually soft-rejected.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (! $user->is_active || $user->isBanned())) {
            $token = $user->currentAccessToken();
            $token?->delete();

            // Bounded by design: revoking the token above means this only
            // fires once per still-live stale token (per device/session),
            // not on every subsequent request against the same token.
            ActivityLog::record(
                'auth.blocked_inactive_or_banned',
                "Blocked an API request from {$user->email} — account is inactive or banned",
                $user,
            );

            return response()->json([
                'message' => 'Your account is not currently permitted to access the platform.',
                'code'    => 'account_unavailable',
            ], 403);
        }

        return $next($request);
    }
}
