<?php

namespace App\Support\Organizations;

use App\Models\Organization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Organisation URL Branding, Phase 4 — the one place a branding change
 * invalidates `PublicOrganisationBrandingController`'s per-hostname cache
 * (`org-branding:{host}`, 10-minute TTL). Called from every mutation point
 * that can change what that endpoint returns for one of an organisation's
 * hosts: `OrganizationController::updateBranding()`/`uploadLogo()`/
 * `uploadCover()`, `OrganizationUrlSlugService::apply()` (both the
 * previous and new slug — either could still be serving a stale cached
 * response), and `DomainVerificationService::activate()`/`disable()`/
 * `reactivate()`/`remove()`.
 *
 * Deliberately forgets every hostname the organisation has ever been
 * reachable on (current url_slug host + every `organization_domains` row
 * regardless of status) rather than trying to reason about which ones are
 * "currently live" — forgetting a cache key that was never set, or is
 * already expired, is a harmless no-op, and the cost of missing one stale
 * key is a customer seeing outdated branding for up to the endpoint's own
 * TTL, not a correctness bug.
 *
 * Best-effort and non-fatal by design (mirrors `AiCreditWorkflowLifecycle`'s
 * own contract) — a branding save must never fail because cache
 * invalidation failed; any exception here is caught and logged, never
 * rethrown.
 */
class BrandingCacheInvalidator
{
    public static function forgetForOrganization(Organization $organization, ?string $additionalHost = null): void
    {
        try {
            $hosts = [];

            $rootDomain = config('organisation_branding.root_domain');
            if ($rootDomain && $organization->url_slug) {
                $hosts[] = strtolower($organization->url_slug) . '.' . strtolower($rootDomain);
            }

            foreach ($organization->domains()->pluck('hostname') as $hostname) {
                $hosts[] = strtolower($hostname);
            }

            if ($additionalHost) {
                $hosts[] = strtolower($additionalHost);
            }

            foreach (array_unique($hosts) as $host) {
                Cache::forget("org-branding:{$host}");
                // Organisation URL Branding, Phase 5 (Stage 2A) — same host
                // set also backs OrganisationFrontendOriginResolver's CORS
                // cache; reusing this existing invalidation call site
                // rather than adding a new one.
                Cache::forget("frontend-origin:{$host}");
            }
        } catch (\Throwable $e) {
            Log::warning('BrandingCacheInvalidator: failed to invalidate branding cache for organization ' . $organization->id . ': ' . $e->getMessage());
        }
    }
}
