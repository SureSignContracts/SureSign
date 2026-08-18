<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateFeatureAvailabilityRequest;
use App\Models\ActivityLog;
use App\Models\FeatureAvailability;
use App\Services\FeatureAvailability\FeatureAvailabilityService;
use App\Support\FeatureAvailability\FeatureAvailabilityCacheInvalidator;
use App\Support\FeatureAvailability\FeatureAvailabilityStatus;
use Illuminate\Http\JsonResponse;

/**
 * The Super-Admin-ONLY Feature Availability management surface
 * (GET/PUT /admin/feature-availability...). Deliberately stricter than
 * this codebase's usual "Super Admin OR Admin may read, Super Admin only
 * may write" convention (e.g. AiCreditsOperationsController/
 * AiTelemetryReportingController) — both endpoints here require
 * `role:Super Admin` only, per explicit Phase A instruction. Admin retains
 * its separate, unrelated ability to BYPASS a Maintenance/Coming Soon state
 * as an ordinary user for internal testing (see
 * FeatureAvailabilityService::isAvailableToUser()) — that is an access
 * bypass only and grants no visibility into or control over this
 * management surface. This route group's own `role:Super Admin` middleware
 * is what actually enforces that separation; it is never inferred from
 * frontend state.
 */
class FeatureAvailabilityAdminController extends Controller
{
    public function __construct(private readonly FeatureAvailabilityService $service)
    {
    }

    /**
     * The full code registry combined with each feature's current effective
     * state — sufficient for the future Phase B management screen, and for
     * any Super Admin diagnostic use today.
     */
    public function index(): JsonResponse
    {
        $features = [];

        foreach ($this->service->fullRegistryWithState() as $key => $entry) {
            $features[$key] = [
                'label' => $entry['label'],
                'description' => $entry['description'],
                'category' => $entry['category'],
                'frontend_routes' => $entry['frontend_routes'],
                'maintenance_supported' => $entry['maintenance_supported'],
                'coming_soon_supported' => $entry['coming_soon_supported'],
                'status' => $entry['status'],
                'message' => $entry['message'],
                'available_at' => $entry['available_at']?->toIso8601String(),
                'updated_by' => $entry['updated_by'],
                'updated_at' => $entry['updated_at']?->toIso8601String(),
            ];
        }

        return response()->json(['features' => $features]);
    }

    /**
     * Applies a status change. Restoring to Active DELETES the override
     * row rather than keeping a redundant "Active" row around — this
     * preserves the architecture's own central invariant ("no row =
     * Active") rather than accumulating rows that mean the same thing as
     * having none. The full before/after transition is still captured in
     * ActivityLog regardless of whether the row survives the request.
     */
    public function update(UpdateFeatureAvailabilityRequest $request, string $featureKey): JsonResponse
    {
        $newStatus = $request->validated('status');
        $newMessage = $request->validated('message');
        $newAvailableAt = $request->validated('available_at');
        $reason = $request->validated('reason');

        $existing = FeatureAvailability::query()->where('feature_key', $featureKey)->first();

        $previousStatus = $existing ? FeatureAvailabilityStatus::normalize($existing->status) : FeatureAvailabilityStatus::ACTIVE;
        $previousMessage = $existing->message ?? null;
        $previousAvailableAt = $existing?->available_at?->toIso8601String();

        if ($newStatus === FeatureAvailabilityStatus::ACTIVE) {
            $existing?->delete();
        } else {
            FeatureAvailability::query()->updateOrCreate(
                ['feature_key' => $featureKey],
                [
                    'status' => $newStatus,
                    'message' => $newMessage,
                    'available_at' => $newAvailableAt,
                    'updated_by' => $request->user()->id,
                ]
            );
        }

        FeatureAvailabilityCacheInvalidator::forget();

        ActivityLog::record(
            'feature_availability.status_changed',
            "Feature \"{$featureKey}\" changed from \"{$previousStatus}\" to \"{$newStatus}\": {$reason}",
            $request->user(),
            null, // no natural Eloquent subject once an Active-restore deletes the row — see class docblock
            [
                'feature_key' => $featureKey,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'previous_message' => $previousMessage,
                'new_message' => $newStatus === FeatureAvailabilityStatus::ACTIVE ? null : $newMessage,
                'previous_available_at' => $previousAvailableAt,
                'new_available_at' => $newStatus === FeatureAvailabilityStatus::ACTIVE ? null : $newAvailableAt,
                'reason' => $reason,
                'changed_by' => $request->user()->id,
                'changed_at' => now()->toIso8601String(),
            ],
        );

        return response()->json(['feature_key' => $featureKey, 'status' => $newStatus]);
    }
}
