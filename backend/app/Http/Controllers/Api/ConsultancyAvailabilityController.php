<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SetAppointmentAvailabilityRequest;
use App\Http\Requests\StoreAppointmentAvailabilityOverrideRequest;
use App\Http\Requests\StoreAppointmentBlockedPeriodRequest;
use App\Http\Requests\UpdateAppointmentAvailabilityOverrideRequest;
use App\Http\Requests\UpdateAppointmentBlockedPeriodRequest;
use App\Models\AppointmentAvailabilityOverride;
use App\Models\AppointmentBlockedPeriod;
use App\Services\AppointmentAvailabilityService;
use App\Services\Consultancy\ConsultancyConsultantResolver;
use App\Services\TimezoneResolver;
use App\Support\Appointments\AvailabilityContext;
use Illuminate\Http\Request;

/**
 * Consultancy Live Booking Upgrade, Stage 1 — the dedicated Consultancy
 * Availability admin surface (Admin -> Consultancy -> Availability). Unlike
 * AppointmentAvailabilityController (self-only for Admin, any eligible
 * staff for Super Admin), there is no {user}/me selection here at all —
 * this always operates on whichever single consultant is currently
 * configured (App\Services\Consultancy\ConsultancyConsultantResolver), and
 * both Admin and Super Admin may view/manage it, matching this codebase's
 * existing Consultancy-specific "platform-wide, not self-scoped" visibility
 * rule (see ConsultancyOperationsController).
 *
 * Reuses AppointmentAvailabilityService entirely — weekly/override methods
 * called with AvailabilityContext::CONSULTANCY; blocked-period methods
 * called exactly as AppointmentAvailabilityController does (deliberately
 * context-free — see AvailabilityContext's docblock for why a blocked
 * period must apply to every context for this consultant).
 *
 * When no consultant is configured, every read endpoint responds with
 * `ready: false` rather than erroring or fabricating an editable schedule;
 * every write endpoint responds 422 without ever creating an orphaned row.
 */
class ConsultancyAvailabilityController extends Controller
{
    public function __construct(
        private readonly AppointmentAvailabilityService $service,
        private readonly ConsultancyConsultantResolver $consultantResolver,
    ) {
    }

    private function notReadyResponse()
    {
        return response()->json([
            'ready'   => false,
            'message' => 'No Consultancy consultant is configured yet.',
        ], 200);
    }

    private function notReadyErrorResponse()
    {
        return response()->json(['message' => 'No Consultancy consultant is configured yet.'], 422);
    }

    // ─── Weekly schedule ────────────────────────────────────────────────

    public function showWeekly(Request $request)
    {
        $consultant = $this->consultantResolver->resolve();
        if (!$consultant) {
            return $this->notReadyResponse();
        }

        return response()->json([
            'ready'    => true,
            'user_id'  => $consultant->id,
            'timezone' => TimezoneResolver::effectiveTimezone($consultant, $consultant->organization),
            'windows'  => $this->service->getWeeklySchedule($consultant, AvailabilityContext::CONSULTANCY),
        ]);
    }

    public function updateWeekly(SetAppointmentAvailabilityRequest $request)
    {
        $consultant = $this->consultantResolver->resolve();
        if (!$consultant) {
            return $this->notReadyErrorResponse();
        }

        try {
            $windows = $this->service->setWeeklySchedule($consultant, $request->validated()['windows'], $request->user(), AvailabilityContext::CONSULTANCY);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($windows);
    }

    // ─── Date-specific overrides ────────────────────────────────────────

    public function indexOverrides(Request $request)
    {
        $consultant = $this->consultantResolver->resolve();
        if (!$consultant) {
            return response()->json([]);
        }

        return response()->json($this->service->getOverrides($consultant, $request->query('from'), $request->query('to'), AvailabilityContext::CONSULTANCY));
    }

    public function storeOverride(StoreAppointmentAvailabilityOverrideRequest $request)
    {
        $consultant = $this->consultantResolver->resolve();
        if (!$consultant) {
            return $this->notReadyErrorResponse();
        }

        try {
            $override = $this->service->createOverride($consultant, $request->validated(), $request->user(), AvailabilityContext::CONSULTANCY);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($override, 201);
    }

    public function updateOverride(UpdateAppointmentAvailabilityOverrideRequest $request, AppointmentAvailabilityOverride $override)
    {
        $consultant = $this->consultantResolver->resolve();
        if (!$consultant || $override->user_id !== $consultant->id || $override->context !== AvailabilityContext::CONSULTANCY) {
            abort(404, 'Override not found.');
        }

        try {
            $override = $this->service->updateOverride($override, $request->validated(), $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($override);
    }

    public function destroyOverride(Request $request, AppointmentAvailabilityOverride $override)
    {
        $consultant = $this->consultantResolver->resolve();
        if (!$consultant || $override->user_id !== $consultant->id || $override->context !== AvailabilityContext::CONSULTANCY) {
            abort(404, 'Override not found.');
        }

        $this->service->deleteOverride($override, $request->user());

        return response()->json(null, 204);
    }

    // ─── Blocked periods (shared — no context) ───────────────────────────

    public function indexBlockedPeriods(Request $request)
    {
        $consultant = $this->consultantResolver->resolve();
        if (!$consultant) {
            return response()->json([]);
        }

        return response()->json($this->service->getBlockedPeriods($consultant));
    }

    public function storeBlockedPeriod(StoreAppointmentBlockedPeriodRequest $request)
    {
        $consultant = $this->consultantResolver->resolve();
        if (!$consultant) {
            return $this->notReadyErrorResponse();
        }

        $data = $request->validated();

        try {
            $startsAt = TimezoneResolver::buildLocalInstant($data['start_date'], $data['start_time'], $data['timezone']);
            $endsAt   = TimezoneResolver::buildLocalInstant($data['end_date'], $data['end_time'], $data['timezone']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if ($endsAt->lte($startsAt)) {
            return response()->json(['message' => 'End must be after start.'], 422);
        }

        $period = $this->service->createBlockedPeriod($consultant, [
            'starts_at' => $startsAt,
            'ends_at'   => $endsAt,
            'timezone'  => $data['timezone'],
            'reason'    => $data['reason'] ?? null,
        ], $request->user());

        return response()->json($period, 201);
    }

    public function updateBlockedPeriod(UpdateAppointmentBlockedPeriodRequest $request, AppointmentBlockedPeriod $blockedPeriod)
    {
        $consultant = $this->consultantResolver->resolve();
        if (!$consultant || $blockedPeriod->user_id !== $consultant->id) {
            abort(404, 'Blocked period not found.');
        }

        $data = $request->validated();
        $timezone = $data['timezone'] ?? $blockedPeriod->timezone;
        $updates = [];

        if (isset($data['start_date']) || isset($data['start_time'])) {
            $startDate = $data['start_date'] ?? $blockedPeriod->starts_at->copy()->setTimezone($blockedPeriod->timezone)->toDateString();
            $startTime = $data['start_time'] ?? $blockedPeriod->starts_at->copy()->setTimezone($blockedPeriod->timezone)->format('H:i');
            try {
                $updates['starts_at'] = TimezoneResolver::buildLocalInstant($startDate, $startTime, $timezone);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }
        if (isset($data['end_date']) || isset($data['end_time'])) {
            $endDate = $data['end_date'] ?? $blockedPeriod->ends_at->copy()->setTimezone($blockedPeriod->timezone)->toDateString();
            $endTime = $data['end_time'] ?? $blockedPeriod->ends_at->copy()->setTimezone($blockedPeriod->timezone)->format('H:i');
            try {
                $updates['ends_at'] = TimezoneResolver::buildLocalInstant($endDate, $endTime, $timezone);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }
        if (isset($data['timezone'])) {
            $updates['timezone'] = $data['timezone'];
        }
        if (array_key_exists('reason', $data)) {
            $updates['reason'] = $data['reason'];
        }

        $startsAt = $updates['starts_at'] ?? $blockedPeriod->starts_at;
        $endsAt   = $updates['ends_at'] ?? $blockedPeriod->ends_at;
        if ($endsAt->lte($startsAt)) {
            return response()->json(['message' => 'End must be after start.'], 422);
        }

        $blockedPeriod = $this->service->updateBlockedPeriod($blockedPeriod, $updates, $request->user());

        return response()->json($blockedPeriod);
    }

    public function destroyBlockedPeriod(Request $request, AppointmentBlockedPeriod $blockedPeriod)
    {
        $consultant = $this->consultantResolver->resolve();
        if (!$consultant || $blockedPeriod->user_id !== $consultant->id) {
            abort(404, 'Blocked period not found.');
        }

        $this->service->deleteBlockedPeriod($blockedPeriod, $request->user());

        return response()->json(null, 204);
    }
}
