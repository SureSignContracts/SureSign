<?php

namespace App\Services;

use App\Models\Organization;

/**
 * Organisation URL Branding, Phase 1 — the single place a customer-facing
 * URL is assembled from an organisation + a path. Every public link/email
 * call site should go through this instead of concatenating
 * `config('suresign.marketing_url')`/`frontend_url` manually.
 *
 * Scope of this phase: only the MARKETING app (marketing_url —
 * appointments/consultations/summaries/public booking, a separate Next.js
 * deployment from the authenticated app — see marketing/ vs frontend/) is
 * brandable. The authenticated app (frontend_url) stays on its fixed host
 * in Phase 1 — no customer-facing page there currently needs to resolve an
 * organisation before login, and branding it is explicitly deferred (see
 * internal-docs/super-admin/organisation-url-branding.md).
 *
 * Priority order (Phase 2): a verified, ACTIVE customer-owned domain
 * (`Organization::activeDomain()`) wins over a branded `url_slug`
 * subdomain, which wins over the default marketing host. Branded-slug
 * output additionally requires `config('organisation_branding.root_domain')`
 * to be set (an operator has deliberately turned URL branding on
 * platform-wide — see that config file's docblock for why this is never
 * derived/guessed) — a custom domain has no such platform-wide gate, since
 * activating one is already an explicit, individually-verified Super Admin
 * action. When none of these apply, this always falls back to the
 * existing, unbranded marketing host — exactly Phase 1's original
 * behaviour, byte-for-byte.
 *
 * Does NOT touch signed-URL generation at all — callers still generate
 * their Laravel signed API URL exactly as before (see
 * AppointmentPublicLinkService), then pass the resulting query string
 * here via $query. The signature itself is always computed and validated
 * against the fixed API host — see internal-docs/super-admin/
 * organisation-url-branding.md's "API host boundary" section for why that
 * must never change.
 */
class OrganisationUrlGenerator
{
    /**
     * Build a public marketing-site URL for the given organisation
     * (nullable — a null/branding-ineligible organisation always falls
     * back to the default marketing host). $path must start with "/".
     * $query is merged onto the URL's existing query string, if any (e.g.
     * a signed link's "expires"/"signature" pair).
     */
    public function publicUrl(?Organization $organization, string $path, array $query = []): string
    {
        return $this->build(config('suresign.marketing_url'), $organization, $path, $query);
    }

    /**
     * (Phase 2) The organisation's current canonical base URL (scheme +
     * host, no path/query) — used only to redirect a visitor away from a
     * superseded (historic) hostname to wherever the organisation actually
     * lives NOW. Never accepts a caller-supplied destination; always
     * re-derives it from the same priority order as every other method
     * here. Falls back to the default marketing base when the
     * organisation has neither an active custom domain nor a branded slug
     * (e.g. its branding was fully removed) — the redirect then simply
     * lands on the ordinary default site, never a dead end.
     */
    public function currentBaseUrl(?Organization $organization): string
    {
        return $this->brandedBase($organization) ?? rtrim(config('suresign.marketing_url'), '/');
    }

    /**
     * Same as publicUrl(), but for a caller that already has a raw,
     * pre-encoded query string (e.g. a signed link's "expires=...&signature=..."
     * extracted via parse_url()) — appended as-is rather than re-encoded
     * through http_build_query(), which could otherwise subtly alter
     * Laravel's own URL-encoding of the signature.
     */
    public function publicUrlWithRawQuery(?Organization $organization, string $path, string $rawQuery = ''): string
    {
        $base = $this->brandedBase($organization) ?? rtrim(config('suresign.marketing_url'), '/');
        $url = $base . '/' . ltrim($path, '/');

        if ($rawQuery === '') {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . $rawQuery;
    }

    /**
     * True when EITHER an active custom domain OR a branded slug applies —
     * see brandedBase() for the priority between the two.
     */
    public function isBranded(?Organization $organization): bool
    {
        return $organization !== null && $this->brandedBase($organization) !== null;
    }

    private function build(string $defaultBase, ?Organization $organization, string $path, array $query): string
    {
        $base = $this->brandedBase($organization) ?? rtrim($defaultBase, '/');
        $url = $base . '/' . ltrim($path, '/');

        if ($query === []) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($query);
    }

    /**
     * (Phase 2) Priority order, per the approved architecture:
     *   1. A verified, ACTIVE customer-owned domain (Organization::activeDomain())
     *   2. A branded url_slug (only when config('organisation_branding.root_domain') is set)
     *   3. null — caller falls back to the default marketing host.
     *
     * Scheme is always the default marketing URL's own scheme, so a local
     * http:// dev setup never silently becomes https://. Returns no
     * trailing slash.
     */
    private function brandedBase(?Organization $organization): ?string
    {
        if ($organization === null) {
            return null;
        }

        $scheme = parse_url(config('suresign.marketing_url'), PHP_URL_SCHEME) ?: 'https';

        $activeDomain = $organization->activeDomain;
        if ($activeDomain !== null) {
            return "{$scheme}://{$activeDomain->hostname}";
        }

        $rootDomain = config('organisation_branding.root_domain');
        if ($organization->url_slug !== null && $rootDomain !== null) {
            return "{$scheme}://{$organization->url_slug}.{$rootDomain}";
        }

        return null;
    }
}
