<?php

namespace App\Services\Organizations;

use App\Models\Organization;
use App\Models\User;
use App\Services\OrganisationUrlGenerator;
use App\Support\Organizations\HostResolution;

/**
 * Organisation URL Branding, Phase 5 (Stage 3) — the ONE place the
 * wrong-workspace decision is made. Deliberately entirely server-side:
 * the frontend never receives two organisation IDs to compare itself —
 * it only ever renders whichever `workspace_state` this service returns.
 * See `App\Http\Controllers\Api\AuthController::workspaceContext()`, the
 * only caller.
 *
 * The requested host is presentation/requested-workspace context ONLY —
 * exactly like `OrganisationHostResolver`'s own docblock says. This
 * service is what actually compares that requested context against the
 * authenticated user's real organisation membership; the hostname itself
 * never authorises anything.
 */
class AuthenticatedWorkspaceContextService
{
    public function __construct(
        private readonly OrganisationHostResolver $hostResolver,
        private readonly OrganisationUrlGenerator $urlGenerator,
    ) {
    }

    /**
     * @param string|null $requestedHost the raw hostname the frontend is
     *   actually being viewed on (sent explicitly by the client — see
     *   frontend's api client — never trusted from the request's own Host
     *   header, since that's always the fixed API host, not the frontend's)
     *
     * @return array{
     *   workspace_state: string,
     *   authoritative_workspace_url: ?string,
     *   may_continue: bool,
     *   organisation_name: ?string,
     * }
     */
    public function resolve(User $user, ?string $requestedHost): array
    {
        $host = $requestedHost !== null ? strtolower(trim($requestedHost)) : null;
        $platformHost = $this->platformHost();
        $isPlatformStaff = $user->hasRole('Super Admin') || $user->hasRole('Admin');

        // No host supplied, or it's the fixed app host itself.
        $onPlatformHost = $host === null || $host === '' || $host === $platformHost;

        $resolution = (!$onPlatformHost)
            ? $this->hostResolver->resolve($host)
            : HostResolution::none();

        // A branded-looking host that resolves to nothing — genuinely
        // unknown/inactive/removed. Distinct from $onPlatformHost, unlike
        // OrganisationHostResolver's own public-facing contract (which
        // deliberately folds "platform host" and "unknown" together to
        // avoid leaking information to an anonymous caller) — this
        // endpoint is authenticated, so it's safe and necessary to tell
        // these apart here.
        if (!$onPlatformHost && !$resolution->isResolved()) {
            // OrganisationHostResolver deliberately never resolves an
            // INACTIVE organisation's slug/domain at all (by design —
            // indistinguishable from "never existed" to an anonymous
            // caller). This endpoint IS authenticated, so it's safe to
            // tell the two apart — but only by checking whether the host
            // belongs to the AUTHENTICATED USER'S OWN organisation,
            // never by looking up any other organisation bypassing that
            // filter.
            if ($this->hostBelongsToOwnInactiveOrganization($host, $user->organization)) {
                return $this->result('inactive_workspace', null, false, null);
            }

            return $this->result('host_not_found', null, false, null);
        }

        if ($isPlatformStaff) {
            $fixedAppUrl = rtrim(config('suresign.frontend_url'), '/');
            if ($onPlatformHost) {
                return $this->result('platform_host', $fixedAppUrl, true, null);
            }

            // Platform staff never operate customer workspaces from a
            // customer hostname, regardless of role/permission breadth.
            return $this->result('platform_staff_on_customer_host', $fixedAppUrl, false, null);
        }

        // Ordinary customer/organisation user from here on.
        $userOrganization = $user->organization;

        if ($onPlatformHost) {
            $authoritative = $this->urlGenerator->authenticatedWorkspaceBaseUrl($userOrganization);
            return $this->result('platform_host', $authoritative, true, $userOrganization?->name);
        }

        $resolvedOrganization = $resolution->organization;

        // No organisation at all (data-integrity edge case) or a genuine
        // mismatch — both are "wrong workspace" from this user's
        // perspective; the safe destination is always THEIR authoritative
        // URL (their own org, or the fixed app host if they have none),
        // never the mismatched host's own URL.
        if ($userOrganization === null || $resolvedOrganization === null || $userOrganization->id !== $resolvedOrganization->id) {
            $authoritative = $this->urlGenerator->authenticatedWorkspaceBaseUrl($userOrganization);
            return $this->result('wrong_workspace', $authoritative, false, null);
        }

        if (!$resolvedOrganization->is_active) {
            return $this->result('inactive_workspace', null, false, null);
        }

        $authoritative = $this->urlGenerator->authenticatedWorkspaceBaseUrl($resolvedOrganization);

        return $this->result('matching_workspace', $authoritative, true, $resolvedOrganization->name);
    }

    /**
     * True only when $host matches the given (already-authenticated,
     * already-loaded) organisation's OWN slug/root-domain combination or
     * ONE of its OWN organization_domains rows — regardless of that
     * domain's own status — AND that organisation is inactive. Never
     * queries for any OTHER organisation; a host matching some other
     * inactive org's slug still correctly falls through to
     * 'host_not_found', exactly as OrganisationHostResolver's own
     * anonymous-caller contract requires.
     */
    private function hostBelongsToOwnInactiveOrganization(string $host, ?Organization $organization): bool
    {
        if ($organization === null || $organization->is_active) {
            return false;
        }

        $rootDomain = config('organisation_branding.root_domain');
        if ($rootDomain && $organization->url_slug !== null) {
            $slugHost = strtolower($organization->url_slug) . '.' . strtolower($rootDomain);
            if ($host === $slugHost) {
                return true;
            }
        }

        return $organization->domains()->pluck('hostname')
            ->map(fn ($h) => strtolower($h))
            ->contains($host);
    }

    private function result(string $state, ?string $authoritativeUrl, bool $mayContinue, ?string $organisationName): array
    {
        return [
            'workspace_state' => $state,
            'authoritative_workspace_url' => $authoritativeUrl,
            'may_continue' => $mayContinue,
            'organisation_name' => $organisationName,
        ];
    }

    private function platformHost(): ?string
    {
        $raw = config('suresign.frontend_url');
        if (!$raw) {
            return null;
        }
        $host = parse_url($raw, PHP_URL_HOST);
        return $host ? strtolower($host) : null;
    }
}
