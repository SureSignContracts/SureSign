<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOwnOrganizationUrlSlugRequest;
use App\Services\Entitlements\FeatureGate;
use App\Services\OrganisationUrlGenerator;
use App\Services\Organizations\OrganizationUrlSlugService;
use App\Support\Entitlements\Feature;
use Illuminate\Http\Request;

/**
 * Organisation URL Branding — customer self-service (Company Branding →
 * Custom URL). Mirrors `OrganizationController::getBranding()`/
 * `updateBranding()`'s existing "Super Admin/Admin have no org to manage
 * here" precedent, but ADDS the entitlement check that page has never
 * had — this is the first real, wired `FeatureGate::allows()` caller in
 * the codebase (previously architecture-only — see FeatureGate's own
 * docblock).
 *
 * Deliberately a SEPARATE controller from `OrganizationController`'s
 * Super Admin url-slug endpoints — organisation-scoped-to-self
 * (`$request->user()->organization`) vs. route-model-bound-to-any-org,
 * different authorization shape entirely. Both delegate the actual
 * mutation to the same `OrganizationUrlSlugService`.
 *
 * The hostname itself is never an authorisation boundary here either —
 * this controller only ever reads/writes the AUTHENTICATED user's own
 * `organization`, never a caller-supplied organisation id.
 */
class OrganizationBrandingUrlController extends Controller
{
    /**
     * Returns 422 (not 403/404) for a Super Admin/Admin caller — matches
     * `getBranding()`/`updateBranding()`'s own existing precedent exactly
     * (they have no organisation of their own to manage this for).
     */
    private function resolveOwnOrganization(Request $request)
    {
        $user = $request->user();

        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) {
            return null;
        }

        return $user->organization;
    }

    private function payload($organization, FeatureGate $gate, OrganisationUrlGenerator $urlGenerator): array
    {
        return [
            'url_slug' => $organization->url_slug,
            'entitled' => $gate->allows($organization, Feature::CUSTOM_BRANDED_SUBDOMAIN),
            'preview_url' => $organization->url_slug !== null ? $urlGenerator->publicUrl($organization, '/') : null,
        ];
    }

    public function show(Request $request, FeatureGate $gate, OrganisationUrlGenerator $urlGenerator)
    {
        $organization = $this->resolveOwnOrganization($request);
        if ($organization === null) {
            return response()->json(['message' => 'Platform administrators have no organisation to manage this for.'], 422);
        }

        return response()->json(['data' => $this->payload($organization, $gate, $urlGenerator)]);
    }

    /**
     * Set/change the organisation's own branded URL slug — requires
     * `Feature::CUSTOM_BRANDED_SUBDOMAIN`. Checked here explicitly (never
     * relying on the frontend hiding the control) — an entitlement-less
     * organisation gets a customer-safe 403, never the internal
     * `FeatureNotEntitledException` message (see that exception's own
     * docblock on why it's unsafe to surface verbatim).
     */
    public function update(UpdateOwnOrganizationUrlSlugRequest $request, FeatureGate $gate, OrganisationUrlGenerator $urlGenerator, OrganizationUrlSlugService $service)
    {
        $organization = $this->resolveOwnOrganization($request);
        if ($organization === null) {
            return response()->json(['message' => 'Platform administrators have no organisation to manage this for.'], 422);
        }

        if (! $gate->allows($organization, Feature::CUSTOM_BRANDED_SUBDOMAIN)) {
            return response()->json(['message' => 'A custom SureSign URL is not available on your current plan.'], 403);
        }

        $organization = $service->apply($organization, $request->normalizedUrlSlug(), 'organisation_customer', $request->user());

        return response()->json(['data' => $this->payload($organization, $gate, $urlGenerator)]);
    }

    /**
     * Removal is deliberately NOT gated by entitlement — an organisation
     * that has lost access to this capability (plan downgrade, lapsed
     * subscription) may still turn its own existing branded URL off. See
     * internal-docs/super-admin/organisation-url-branding.md's customer
     * self-service section for the approved entitlement-loss behaviour:
     * existing links keep working, mutation (set/change) is blocked, but
     * removal always remains available.
     */
    public function destroy(Request $request, FeatureGate $gate, OrganisationUrlGenerator $urlGenerator, OrganizationUrlSlugService $service)
    {
        $organization = $this->resolveOwnOrganization($request);
        if ($organization === null) {
            return response()->json(['message' => 'Platform administrators have no organisation to manage this for.'], 422);
        }

        $organization = $service->apply($organization, null, 'organisation_customer', $request->user());

        return response()->json(['data' => $this->payload($organization, $gate, $urlGenerator)]);
    }
}
