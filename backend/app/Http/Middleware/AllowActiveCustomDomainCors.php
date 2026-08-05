<?php

namespace App\Http\Middleware;

use App\Services\Organizations\OrganisationFrontendOriginResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Organisation URL Branding, Phase 5 (Stage 2A) — supplements (never
 * replaces) Laravel's built-in `HandleCors` + `config/cors.php`. That
 * existing config already correctly handles the fixed app/marketing hosts
 * and the SureSign-managed wildcard subdomain pattern via static regex —
 * this middleware exists ONLY to additionally allow an active, verified
 * customer-owned domain, which cannot be expressed as a static pattern
 * (an arbitrary customer-chosen hostname, not a suffix of our own root
 * domain).
 *
 * Runs after the framework's own HandleCors. If that middleware already
 * set `Access-Control-Allow-Origin` (a static-pattern match), this is a
 * no-op — never overrides an already-correct decision. Only acts when the
 * incoming Origin wasn't already permitted AND resolves to an active
 * customer domain via `OrganisationFrontendOriginResolver`.
 *
 * Never a tenant-authorization decision — see that resolver's own
 * docblock. A permitted Origin here only lets the browser read the
 * response; every endpoint still independently enforces
 * organization_id/Sanctum ability scoping regardless of Origin.
 */
class AllowActiveCustomDomainCors
{
    public function __construct(private readonly OrganisationFrontendOriginResolver $resolver)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        // Preflight for a custom domain the static config doesn't know
        // about — HandleCors won't have short-circuited this, so answer it
        // here directly rather than letting it fall through to a real route
        // (which may not even accept OPTIONS).
        if ($origin && $request->getMethod() === 'OPTIONS' && $this->resolver->isActiveCustomDomainOrigin($origin)) {
            return $this->withCorsHeaders(response('', 204), $origin);
        }

        /** @var Response $response */
        $response = $next($request);

        if (!$origin || $response->headers->has('Access-Control-Allow-Origin')) {
            // Already handled by the static config (or no Origin present at
            // all, e.g. a same-origin/server-to-server request) — nothing
            // to add.
            return $response;
        }

        if ($this->resolver->isActiveCustomDomainOrigin($origin)) {
            $this->withCorsHeaders($response, $origin);
        }

        return $response;
    }

    private function withCorsHeaders(Response $response, string $origin): Response
    {
        // Mirrors config/cors.php's own settings exactly (allowed_methods:
        // '*', allowed_headers: '*', supports_credentials: false) — this
        // middleware widens WHICH origins are allowed, never what they're
        // allowed to do once permitted.
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', $response->headers->get('Access-Control-Allow-Headers') ?? 'Content-Type, Authorization, X-Requested-With');
        $response->headers->set('Vary', 'Origin');

        return $response;
    }
}
