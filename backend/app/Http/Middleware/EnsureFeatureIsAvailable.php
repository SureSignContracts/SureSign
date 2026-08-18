<?php

namespace App\Http\Middleware;

use App\Services\FeatureAvailability\FeatureAvailabilityService;
use App\Support\FeatureAvailability\FeatureAvailabilityStatus;
use App\Support\FeatureAvailability\FeatureAvailabilityUnavailableException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SureSign Feature Availability — the route-level enforcement primitive.
 * Aliased as `feature.available` in bootstrap/app.php, used like
 * `feature.available:project.programme`.
 *
 * PHASE A: this class is built and independently tested, but is NOT
 * attached to any real module route yet — see the Phase A report for why
 * (the full route-ownership audit determining exactly which endpoints are
 * genuinely module-exclusive vs. shared with Dashboard/Overview/Calendar/
 * notifications belongs to a later, dedicated enforcement-rollout phase).
 *
 * Behaviour when eventually invoked:
 *   - ACTIVE → continues to the next middleware/controller unchanged.
 *   - MAINTENANCE/COMING_SOON → a Super Admin/Admin bypasses (continues
 *     unchanged, per FeatureAvailabilityService::isAvailableToUser()); any
 *     other authenticated user (Client) receives a deterministic 503 with a
 *     structured `code` (`feature_maintenance`/`feature_coming_soon`) and
 *     `feature` key — never the internal audit reason, never actor
 *     identity. Mirrors EnsureBillingIsEnabled's exact response shape.
 *   - An unrecognised feature key passed to this middleware resolves
 *     ACTIVE (fails open) via the service's own resolution rules — never
 *     blocks a route by accident because of a typo'd key.
 *
 * The customer-facing display message/copy comes from the Phase B frontend
 * (via GET /feature-availability), not from this middleware's own 503 body
 * — matches this codebase's existing convention that normalizeApiError.ts
 * deliberately suppresses a >=500 response's own `message` field, using
 * only its `code` for deterministic frontend behaviour (see
 * 'checkout_unavailable'/'billing_disabled'/'geocoding_unavailable').
 */
class EnsureFeatureIsAvailable
{
    public function __construct(private readonly FeatureAvailabilityService $service)
    {
    }

    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        try {
            $this->service->requireAvailable($featureKey, $request->user());
        } catch (FeatureAvailabilityUnavailableException $e) {
            $code = $e->status === FeatureAvailabilityStatus::COMING_SOON
                ? 'feature_coming_soon'
                : 'feature_maintenance';

            $message = $e->status === FeatureAvailabilityStatus::COMING_SOON
                ? 'This feature is not yet available.'
                : 'This feature is temporarily unavailable.';

            return response()->json([
                'message' => $message,
                'code' => $code,
                'feature' => $e->featureKey,
            ], 503);
        }

        return $next($request);
    }
}
