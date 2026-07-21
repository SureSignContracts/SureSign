<?php

namespace App\Http\Middleware;

use App\Services\Monitoring\ModuleUsageResolver;
use App\Services\Monitoring\ModuleUsageService;
use App\Services\Monitoring\UserPresenceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Centralized authenticated-activity signal for Super Admin Application
 * Monitoring — records presence and (throttled) module usage for requests
 * that represent genuine user interaction. Registered on the main
 * authenticated route group in routes/api.php, after auth:sanctum, so
 * $request->user() is always populated here.
 *
 * `ModuleUsageResolver::resolve()` is the single source of truth for what
 * counts: a request that resolves to null (health checks, notification
 * polling, the monitoring endpoint itself, unmapped routes) updates
 * neither presence nor module usage. This keeps "is this real user
 * activity" defined in exactly one place rather than duplicated between
 * the two services.
 */
class TrackApplicationUsage
{
    public function __construct(
        private readonly UserPresenceService $presence,
        private readonly ModuleUsageService $usage,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if ($user) {
            $moduleKey = ModuleUsageResolver::resolve($request);

            if ($moduleKey !== null) {
                $this->presence->recordActivity($user, $moduleKey);
                $this->usage->recordVisit($user, $moduleKey);
            }
        }

        return $response;
    }
}
