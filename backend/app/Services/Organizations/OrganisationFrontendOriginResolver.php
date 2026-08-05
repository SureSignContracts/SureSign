<?php

namespace App\Services\Organizations;

use Illuminate\Support\Facades\Cache;

/**
 * Organisation URL Branding, Phase 5 (Stage 2A) — dynamic CORS origin
 * validation for the authenticated frontend. This is a SEPARATE concern
 * from `OrganisationHostResolver` (which classifies a hostname for
 * public-branding/appointment/consultation purposes) — this class answers
 * only "may a browser script running on this Origin make a
 * credentialed-shaped API request at all," never "does this request's
 * bearer token actually belong to this organisation." CORS is a
 * presentation/transport concern; authorization remains entirely with
 * Sanctum + normal organization_id scoping on every controller — see this
 * class's own docblock note on `isAllowed()` below.
 *
 * Deliberately does NOT replace `config/cors.php`'s existing static
 * allowlist (fixed app/marketing hosts) or its wildcard SureSign-subdomain
 * pattern — both already work correctly and cover the vast majority of
 * traffic without a single DB query. This service exists ONLY for the one
 * case a static pattern cannot express: a customer-owned custom domain,
 * an arbitrary string chosen by the customer, not a suffix of our own
 * root domain. See `App\Http\Middleware\AllowActiveCustomDomainCors` for
 * where this is actually applied.
 */
class OrganisationFrontendOriginResolver
{
    private const CACHE_TTL_MINUTES = 10;

    /**
     * @param string $origin the raw `Origin` request header, e.g. "https://portal.customer.com"
     *
     * Reused role: an "allowed" result here means ONLY that this Origin may
     * receive CORS headers permitting a browser to read the response — it
     * is never treated as proof of tenant membership, an active session,
     * or any authorization decision. Every authenticated endpoint still
     * independently enforces organization_id scoping regardless of which
     * Origin the request arrived from.
     */
    public function isActiveCustomDomainOrigin(string $origin): bool
    {
        $host = $this->parseHttpsHost($origin);
        if ($host === null) {
            return false;
        }

        return Cache::remember(
            "frontend-origin:{$host}",
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => app(OrganisationHostResolver::class)->resolve($host)->type
                === \App\Support\Organizations\HostResolution::TYPE_CUSTOMER_DOMAIN
        );
    }

    /**
     * Strict parse: requires a well-formed "https://host" origin with no
     * path, query, fragment, userinfo, or explicit port — an Origin header
     * is never any of those things in a real browser request, so anything
     * shaped that way is treated as malformed rather than tolerantly
     * parsed. Non-HTTPS is rejected unconditionally (this method is never
     * called in a context where plain HTTP should be considered a valid
     * production custom domain).
     */
    private function parseHttpsHost(string $origin): ?string
    {
        $parts = parse_url($origin);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        if ($parts['scheme'] !== 'https') {
            return null;
        }
        if (isset($parts['path']) && $parts['path'] !== '') {
            return null;
        }
        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        return strtolower($parts['host']);
    }
}
