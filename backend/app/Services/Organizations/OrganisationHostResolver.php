<?php

namespace App\Services\Organizations;

use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\OrganizationUrlSlugHistory;
use App\Support\Organizations\DomainStatus;
use App\Support\Organizations\HostResolution;
use App\Support\Organizations\UrlSlugValidator;

/**
 * Organisation URL Branding, Phase 2 — the ONE authoritative place a raw
 * hostname is classified and resolved to an organisation. Replaces the
 * duplicated `Organization::where('url_slug', ...)` lookups that
 * previously lived independently in
 * `App\Support\Organizations\EnforcesPublicOrganizationHost` and
 * `App\Http\Controllers\Api\PublicOrganisationBrandingController` (Phase 1)
 * — both now call this instead. Do not add another hostname-parsing call
 * site anywhere; extend this class.
 *
 * Resolution order for a raw hostname:
 *   1. An `organization_domains` row with this exact hostname and status
 *      `active` → TYPE_CUSTOMER_DOMAIN.
 *   2. If the host matches `{label}.{root_domain}` (root_domain from
 *      config/organisation_branding.php): an active organisation whose
 *      CURRENT `url_slug` is that label → TYPE_ORGANISATION. Otherwise, an
 *      active organisation whose HISTORY contains that label →
 *      TYPE_HISTORIC_SLUG (the caller decides what to do with this — see
 *      OrganisationUrlGenerator for the redirect-target it resolves to,
 *      and PublicOrganisationBrandingController for how the marketing site
 *      is told to redirect).
 *   3. Otherwise → TYPE_NONE. This deliberately covers BOTH "this is an
 *      ordinary platform host" (app./www./the bare root domain) AND
 *      "this hostname matches nothing at all" — callers must never treat
 *      those two cases differently (see this class's own security note
 *      below); an unresolved host always falls back to default,
 *      unbranded behaviour, and never silently guesses.
 *
 * The hostname is NEVER itself an authorisation mechanism — every caller
 * resolving a TYPE_CUSTOMER_DOMAIN/TYPE_ORGANISATION/TYPE_HISTORIC_SLUG
 * result still independently verifies that the specific resource being
 * requested (an Appointment, a Consultation) actually belongs to the
 * resolved organisation — see EnforcesPublicOrganizationHost.
 */
class OrganisationHostResolver
{
    public function resolve(string $rawHost): HostResolution
    {
        $host = strtolower(trim($rawHost));
        if ($host === '') {
            return HostResolution::none();
        }

        $domain = OrganizationDomain::where('hostname', $host)
            ->where('status', DomainStatus::ACTIVE)
            ->with('organization')
            ->first();

        if ($domain !== null && $domain->organization !== null && $domain->organization->is_active) {
            return HostResolution::customerDomain($domain->organization, $domain);
        }

        $rootDomain = config('organisation_branding.root_domain');
        if (! $rootDomain) {
            return HostResolution::none();
        }

        $suffix = '.' . strtolower($rootDomain);
        if (! str_ends_with($host, $suffix)) {
            return HostResolution::none();
        }

        $label = substr($host, 0, -strlen($suffix));
        if ($label === '' || str_contains($label, '.') || ! UrlSlugValidator::isValidFormat($label)) {
            return HostResolution::none();
        }

        $organization = Organization::where('url_slug', $label)->where('is_active', true)->first();
        if ($organization !== null) {
            return HostResolution::organisation($organization);
        }

        $historic = OrganizationUrlSlugHistory::where('url_slug', $label)
            ->with('organization')
            ->latest('released_at')
            ->first();

        if ($historic !== null && $historic->organization !== null && $historic->organization->is_active) {
            return HostResolution::historicSlug($historic->organization);
        }

        return HostResolution::none();
    }
}
