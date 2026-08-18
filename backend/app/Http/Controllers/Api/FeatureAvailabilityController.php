<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FeatureAvailability\FeatureAvailabilityService;
use Illuminate\Http\JsonResponse;

/**
 * SureSign Feature Availability — the customer-facing effective-status
 * endpoint (GET /feature-availability). Authenticated, but role-agnostic:
 * every authenticated user (Client included) may read this, matching
 * SuresignSettingController::publicShow()'s existing "public read — all
 * authenticated users" convention for platform-wide settings.
 *
 * Returns ONLY non-Active override entries for REGISTERED features —
 * never updated_by, audit reason, actor identity, cache internals, or any
 * field a customer doesn't need to render a Maintenance/Coming Soon state.
 * A missing key in the response means Active, by design — the frontend
 * hook must treat absence as Active, never as "unknown."
 */
class FeatureAvailabilityController extends Controller
{
    public function __construct(private readonly FeatureAvailabilityService $service)
    {
    }

    public function status(): JsonResponse
    {
        $features = [];

        // Fails safe to an empty map (i.e. every feature Active) — see
        // FeatureAvailabilityService::allEffective()'s own fail-safe
        // lookup; this catch is an extra safety net in case anything above
        // that layer throws unexpectedly, so this endpoint itself can never
        // 500 the whole app shell.
        try {
            foreach ($this->service->allEffective() as $key => $entry) {
                $features[$key] = [
                    'status' => $entry['status'],
                    'message' => $entry['message'],
                    'available_at' => $entry['available_at']?->toIso8601String(),
                ];
            }
        } catch (\Throwable) {
            $features = [];
        }

        return response()->json(['features' => $features]);
    }
}
