<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppointmentExternalSync;
use App\Services\Calendar\AppointmentCalendarSyncService;
use App\Support\Google\CalendarSyncPresenter;
use App\Support\Google\CalendarSyncState;
use Illuminate\Http\Request;

/**
 * Stage 4B.1 — Admin diagnostics and authorised retry/reconcile actions
 * for Appointment Calendar synchronisation. Grouped under the existing
 * `/admin/google/*` prefix (Google Integration Foundation's own surface),
 * since this is inherently Google-integration-operational, even though the
 * underlying data model (App\Models\AppointmentExternalSync) is
 * Appointment-domain-owned, not Consultancy-owned.
 *
 * Read: Super Admin OR Admin (matches GoogleIntegrationController's own
 * diagnostics() convention). Retry/reconcile: Super Admin OR Admin —
 * deliberately NOT the stricter Super-Admin-only gate GoogleIntegrationController
 * uses for OAuth connect/disconnect, since a sync retry/reconcile is a
 * safe, idempotent, non-destructive action (mirrors
 * ConsultancySettingsController::retryConversion()'s identical risk
 * profile), not a secret-touching one.
 *
 * No event editing, no raw provider payload access, and no customer-
 * facing response exists anywhere in this controller.
 */
class GoogleCalendarSyncController extends Controller
{
    public function __construct(
        private readonly AppointmentCalendarSyncService $syncService,
    ) {
    }

    /**
     * @return array
     */
    public function index(Request $request)
    {
        $query = AppointmentExternalSync::query()->with('appointment')->latest('id');

        if ($state = $request->query('state')) {
            if (!in_array($state, CalendarSyncState::ALL, true)) {
                return response()->json(['message' => 'Invalid state filter.'], 422);
            }
            $query->where('state', $state);
        }

        $syncs = $query->limit(100)->get();

        return response()->json([
            'data' => $syncs->map(fn (AppointmentExternalSync $sync) => CalendarSyncPresenter::admin($sync))->all(),
        ]);
    }

    public function show(AppointmentExternalSync $sync)
    {
        return response()->json(CalendarSyncPresenter::admin($sync));
    }

    public function retry(AppointmentExternalSync $sync)
    {
        // SYNCED/CANCELLED are genuinely terminal — nothing to retry.
        // Every other state (including a currently-active PROCESSING
        // lease) is left to AppointmentCalendarSyncService::retry()'s own
        // claim logic, which safely no-ops rather than double-claiming.
        if ($sync->isTerminal()) {
            return response()->json(['message' => "Cannot retry a sync in state \"{$sync->state}\"."], 409);
        }

        $this->syncService->retry($sync);

        return response()->json(CalendarSyncPresenter::admin($sync->fresh()));
    }

    public function reconcile(AppointmentExternalSync $sync)
    {
        if ($sync->isTerminal()) {
            return response()->json(['message' => "Cannot reconcile a sync in state \"{$sync->state}\"."], 409);
        }

        $this->syncService->reconcileOnly($sync);

        return response()->json(CalendarSyncPresenter::admin($sync->fresh()));
    }
}
