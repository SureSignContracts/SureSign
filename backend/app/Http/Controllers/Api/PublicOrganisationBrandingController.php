<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SuresignSetting;
use App\Services\BrandingService;
use App\Services\OrganisationUrlGenerator;
use App\Services\Organizations\OrganisationHostResolver;
use App\Support\Organizations\HostResolution;
use Illuminate\Support\Facades\Cache;

/**
 * Organisation URL Branding (Phase 1, upgraded Phase 2) — the public,
 * unauthenticated endpoint the marketing site calls to skin a page (or
 * redirect away from a superseded hostname) before any login/token exists.
 *
 * Phase 2 change: takes the raw hostname the browser is actually on
 * (`{host}` — may be a branded subdomain OR a customer-owned domain, dots
 * and all) rather than a bare slug, and delegates ALL classification to
 * `App\Services\Organizations\OrganisationHostResolver` — the one
 * authoritative hostname-resolution flow, never duplicated here.
 *
 * Deliberately returns branding-safe fields only — never an internal
 * organisation id, never billing/users/projects/subscription/settings/
 * storage-path/contact data. A soft-deleted or inactive organisation's
 * hostname resolves to nothing here (404) — see the resolver's own
 * docblock for the exact rules, including why a deleted organisation's
 * slug/domain can never be silently reclaimed by another organisation.
 */
class PublicOrganisationBrandingController extends Controller
{
    private const CACHE_TTL_SECONDS = 600;

    public function show(string $host, OrganisationHostResolver $resolver, OrganisationUrlGenerator $urlGenerator)
    {
        $normalized = strtolower(trim($host));

        $payload = Cache::remember(
            "org-branding:{$normalized}",
            self::CACHE_TTL_SECONDS,
            fn () => $this->resolve($normalized, $resolver, $urlGenerator),
        );

        if ($payload === null) {
            return $this->notFound();
        }

        return response()->json(['data' => $payload]);
    }

    private function resolve(string $normalizedHost, OrganisationHostResolver $resolver, OrganisationUrlGenerator $urlGenerator): ?array
    {
        $resolution = $resolver->resolve($normalizedHost);

        if (! $resolution->isResolved()) {
            return null;
        }

        $organization = $resolution->organization;

        $branding = SuresignSetting::instance()->feature_white_label
            ? BrandingService::forOrganization($organization->id)
            : null;

        // branding_version (Phase 4) — reuses the branding row's own
        // updated_at as a cache-busting version, rather than a new column.
        // Falls back to the organisation's own updated_at when there's no
        // branding row yet, so an org with only a bare display-name/accent
        // default still gets a stable, changing-when-relevant version.
        $brandingVersion = ($branding?->updated_at ?? $organization->updated_at)?->timestamp;

        $logoUrl = $branding?->logo_path ? url('storage/' . $branding->logo_path) : null;
        if ($logoUrl && $brandingVersion) {
            $logoUrl .= '?v=' . $brandingVersion;
        }

        $base = [
            'host_type' => $resolution->type,
            'organisation_name' => $branding?->company_display_name ?? $organization->name,
            'logo_url' => $logoUrl,
            'accent_color' => BrandingService::accentColour($branding),
            'branding_version' => $brandingVersion,
        ];

        if ($resolution->type === HostResolution::TYPE_HISTORIC_SLUG) {
            // The marketing site is responsible for performing the actual
            // browser redirect (preserving path/query — see
            // OrganisationUrlGenerator::publicUrl()) — this endpoint only
            // ever supplies the CURRENT canonical base to redirect to,
            // never an arbitrary/user-supplied destination.
            return [...$base, 'redirect_base_url' => $urlGenerator->currentBaseUrl($organization)];
        }

        return $base;
    }

    private function notFound()
    {
        return response()->json(['message' => 'Not found.'], 404);
    }
}
