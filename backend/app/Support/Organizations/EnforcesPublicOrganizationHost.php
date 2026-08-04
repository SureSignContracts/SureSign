<?php

namespace App\Support\Organizations;

use App\Services\Organizations\OrganisationHostResolver;

/**
 * Organisation URL Branding (Phase 1, upgraded Phase 2) — cross-host
 * tenant-isolation check for public, token-based controllers
 * (PublicAppointmentActionController, PublicConsultationViewController).
 *
 * Background: the backend API is always reached on its own fixed host (see
 * internal-docs/super-admin/organisation-url-branding.md's "API host
 * boundary" section) — a browser on a branded/custom hostname never sends
 * that host to the API directly. The marketing frontend's own hostname
 * resolution forwards the RAW hostname it's being served on as an
 * `X-Suresign-Org-Host` HTTP HEADER (deliberately never a query parameter —
 * every one of these endpoints sits behind Laravel's `signed` middleware,
 * which HMACs the full query string; a query parameter added after the
 * link was generated would simply fail signature verification, whereas a
 * header sits outside the signed payload entirely).
 *
 * Phase 2 change: this now delegates ALL hostname classification to
 * `App\Services\Organizations\OrganisationHostResolver` — the one
 * authoritative place a hostname is parsed — rather than looking up
 * `Organization::where('url_slug', ...)` directly itself (Phase 1's
 * original, now-duplicated approach). This is what lets a customer-owned
 * domain and a historic (superseded) slug both correctly identify their
 * organisation here, not just a current branded subdomain.
 *
 *   - No header given (the default, unbranded host) → always passes. The
 *     hostname carries no authorisation either way.
 *   - Header resolves to TYPE_NONE (unknown host, or an ordinary platform
 *     host) → always passes, identically to no header — an unresolved
 *     host is never treated as a mismatch on its own.
 *   - Header resolves to an organisation (current slug, historic slug, or
 *     customer domain) that ISN'T the resource's own `organization_id` →
 *     the resource is treated as not found. Deliberately indistinguishable
 *     from "token doesn't exist" (never a distinct error message) so a
 *     mismatched host can't be used to probe which organisation a token
 *     belongs to.
 *   - A HISTORIC slug that DOES match the resource's organisation is
 *     treated as a pass, not a mismatch — the organisation identity is
 *     what's being verified, regardless of which of that organisation's
 *     hostnames (current or superseded) reached this endpoint. The
 *     marketing frontend is responsible for redirecting a page view away
 *     from a historic hostname; a request that reaches the API anyway
 *     (e.g. a stale deep link) still resolves correctly rather than
 *     breaking.
 *
 * This performs presentation-consistency enforcement only — it is never a
 * substitute for the token itself being the real authorisation mechanism.
 */
trait EnforcesPublicOrganizationHost
{
    protected function hostMatchesOrganization(?string $rawHost, ?int $resourceOrganizationId): bool
    {
        if ($rawHost === null || $rawHost === '') {
            return true;
        }

        $resolution = app(OrganisationHostResolver::class)->resolve($rawHost);

        if (! $resolution->isResolved()) {
            return true;
        }

        if ($resourceOrganizationId === null) {
            return false;
        }

        return $resolution->organization?->id === $resourceOrganizationId;
    }
}
