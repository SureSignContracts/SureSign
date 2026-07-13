<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the normal authenticated API surface while a user's
 * must_change_password flag is set (an admin forced a reset, or assigned a
 * temporary password) — enforced server-side so this can't be bypassed by
 * calling the API directly instead of going through the frontend's
 * ForcePasswordChangeGate. Only the handful of routes that screen actually
 * needs stay reachable.
 */
class EnsurePasswordIsCurrent
{
    /**
     * Route *names* (not paths) reachable while must_change_password is
     * true — kept to the minimum the frontend's ForcePasswordChangeGate
     * component actually calls: submit the new password, refresh the user
     * object afterward, or sign out instead.
     */
    private const ALLOWED_ROUTE_NAMES = [
        'auth.force-password-change',
        'auth.me',
        'auth.logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! in_array($request->route()?->getName(), self::ALLOWED_ROUTE_NAMES, true)) {
            return response()->json([
                'message' => 'You must change your password before continuing.',
                'code'    => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}
