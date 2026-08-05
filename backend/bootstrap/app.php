<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role'              => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'        => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'account.status'    => \App\Http\Middleware\EnsureAccountIsActive::class,
            'password.current'  => \App\Http\Middleware\EnsurePasswordIsCurrent::class,
            'track.usage'       => \App\Http\Middleware\TrackApplicationUsage::class,
            'billing.enabled'   => \App\Http\Middleware\EnsureBillingIsEnabled::class,
        ]);

        // Organisation URL Branding, Phase 5 (Stage 2A) — must be GLOBAL
        // (not merely 'api' route-group middleware — a preflight OPTIONS
        // request for a route that only registers GET, e.g.
        // /api/guest-settings, never matches during routing at all, so
        // route-group middleware never runs for it), AND must be PREPENDED
        // rather than appended: the framework's own HandleCors is a global
        // middleware that short-circuits EVERY OPTIONS request immediately
        // (returning its own response without calling the next middleware),
        // regardless of origin — confirmed empirically, an appended
        // (innermost) copy of this middleware never even ran for OPTIONS.
        // Prepending makes this the outermost layer, so it gets a chance to
        // handle a preflight for an origin HandleCors doesn't recognize
        // BEFORE HandleCors's own unconditional short-circuit. For actual
        // (non-OPTIONS) requests it still just inspects/augments the
        // response after calling $next() — see the middleware's own
        // docblock; never overrides an already-permitted origin.
        $middleware->prepend(\App\Http\Middleware\AllowActiveCustomDomainCors::class);

        // The app is only ever reached through the nginx container (which sets
        // X-Forwarded-For/X-Real-IP correctly — see docker/nginx/default.conf).
        // Trusting the private Docker bridge ranges (rather than only a single
        // fixed IP) lets Laravel resolve the real client IP through that proxy
        // regardless of which subnet Docker Compose assigns on a given host.
        // Known caveat: docker-compose.prod.yml also host-maps the backend
        // container's port directly (8000:8000) alongside nginx's 8080:80 — a
        // request that reaches the backend on 8000 without going through nginx
        // would arrive from a IP outside these trusted ranges, so
        // Illuminate\Http\Request::ip() would correctly NOT trust a spoofed
        // X-Forwarded-For in that case and would fall back to the real
        // connecting IP. That direct port should still be closed off at the
        // host firewall / Dokploy level as a separate hardening step.
        $middleware->trustProxies(
            at: ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Wires the 'api' named limiter (AppServiceProvider::configureRateLimiters)
        // into the default `api` middleware group, so every API route gets the
        // general per-user/per-IP ceiling. Individual auth routes additionally
        // apply their own tighter named limiter (throttle:login, etc.) on top.
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Rate-limit responses must never leak the throttle key, whether an
        // account exists, or any internal detail — just a generic message and
        // the standard Retry-After header the frontend can read.
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(
                    ['message' => 'Too many attempts. Please try again later.'],
                    429,
                    $e->getHeaders(),
                );
            }
        });
    })->create();
