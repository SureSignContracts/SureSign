<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Services\Entitlements\FeatureGate;
use App\Services\Entitlements\FeatureNotEntitledException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The COMMERCIAL PLAN ENTITLEMENT counterpart to `EnsureFeatureIsAvailable`
 * ({@see EnsureFeatureIsAvailable}'s own docblock) — deliberately a
 * separate middleware, never merged with it. `feature.available` answers
 * "is this module operationally switched on platform-wide right now"
 * (Active/Maintenance/Coming Soon, no subscription/plan awareness at
 * all); this middleware answers "does this ORGANISATION'S subscription
 * plan include this capability" via the existing `FeatureGate`
 * (`App\Support\Entitlements\Feature`/`PlanEntitlementRepository`/
 * `SubscriptionAccessPolicy`) — the same commercial entitlement
 * architecture used for `Feature::CUSTOM_BRANDED_SUBDOMAIN` in
 * `OrganizationBrandingUrlController`, just wired as route-level
 * middleware instead of an inline controller check, since a feature like
 * Friday Packs spans many routes across several controllers.
 *
 * Aliased as `feature.entitled` in bootstrap/app.php, used like
 * `feature.entitled:friday_packs` (a bare `Feature::*` key — never the
 * dotted `project.*`/`organization.*` `feature.available` key namespace,
 * to keep the two systems visually and conceptually distinct at the
 * route-registration call site too).
 *
 * Resolution:
 *   - Super Admin/Admin always bypass — mirrors the exact same
 *     `$user->hasRole('Super Admin') || $user->hasRole('Admin')` bypass
 *     every controller's own `authorize()` method already uses
 *     throughout this codebase; not a bespoke bypass invented here.
 *   - The organisation is resolved from the route's own bound `Project`
 *     model when present (every Friday Pack route sits under
 *     `projects/{project}/...`), falling back to the authenticated
 *     user's own organisation otherwise. No organisation resolvable at
 *     all (e.g. a Super Admin/Admin path with no project param, already
 *     bypassed above) → continues unchanged; this middleware only ever
 *     narrows access for a real customer organisation, never invents a
 *     new authorization requirement route-model-binding already covers
 *     (a project belonging to a different organisation is a 403 from the
 *     controller's own existing `authorize()`, not this middleware's
 *     concern).
 *   - Not entitled → `FeatureGate::requireFeature()`'s own
 *     `FeatureNotEntitledException` is caught and converted to a 403
 *     with that exception's existing customer-safe `message` and
 *     structured `code` (`feature_not_entitled`) — never the internal
 *     organisation id/feature-key detail that exception's own docblock
 *     says must never reach a customer response.
 */
class EnsureFeatureIsEntitled
{
    public function __construct(private readonly FeatureGate $gate)
    {
    }

    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        $user = $request->user();

        if ($user === null || $user->hasRole('Super Admin') || $user->hasRole('Admin')) {
            return $next($request);
        }

        $organization = $this->resolveOrganization($request, $user);

        if ($organization === null) {
            return $next($request);
        }

        try {
            $this->gate->requireFeature($organization, $featureKey);
        } catch (FeatureNotEntitledException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
                'feature' => $e->featureKey,
            ], 403);
        }

        return $next($request);
    }

    private function resolveOrganization(Request $request, $user)
    {
        $project = $request->route('project');

        if ($project instanceof Project) {
            return $project->organization;
        }

        return $user->organization;
    }
}
