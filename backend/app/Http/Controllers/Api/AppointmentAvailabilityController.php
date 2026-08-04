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
use App\Models\User;
use App\Services\AppointmentAvailabilityService;
use App\Services\TimezoneResolver;
use App\Support\Appointments\AvailabilityContext;
use Illuminate\Http\Request;

/**
 * `/me` routes and `/{user}` routes share every action below — Laravel
 * injects the bound User for `/{user}`, and `$user` is simply null for
 * `/me`, in which case resolveTarget() falls back to the acting user.
 * `/me` routes must stay registered before `/{user}` in routes/api.php or
 * the literal segment "me" gets swallowed by the {user} route-model binding.
 */
class AppointmentAvailabilityController extends Controller
{
    public function __construct(private readonly AppointmentAvailabilityService $service)
    {
    }

    /**
     * Admin may only ever target themselves; Super Admin may target any
     * eligible Admin/Super Admin. Eligibility is checked here so every
     * action (including plain GETs) rejects a Client or otherwise
     * ineligible target consistently.
     */
    private function resolveTarget(Request $request, ?User $user): User
    {
        $target = $user ?? $request->user();
        $actor = $request->user();

        if (!$actor->hasRole('Super Admin') && $target->id !== $actor->id) {
            abort(403, 'You can only manage your own availability.');
        }
        if (!$this->service->isEligibleStaff($target)) {
            abort(422, 'This user is not eligible for appointment scheduling.');
        }

        return $target;
    }

    // ─── Weekly schedule ────────────────────────────────────────────────

    public function showWeekly(Request $request, ?User $user = null)
    {
        $target = $this->resolveTarget($request, $user);

        return response()->json([
            'user_id'  => $target->id,
            'timezone' => TimezoneResolver::effectiveTimezone($target, $target->organization),
            'windows'  => $this->service->getWeeklySchedule($target, AvailabilityContext::APPOINTMENTS),
        ]);
    }

    public function updateWeekly(SetAppointmentAvailabilityRequest $request, ?User $user = null)
    {
        $target = $this->resolveTarget($request, $user);

        try {
            $windows = $this->service->setWeeklySchedule($target, $request->validated()['windows'], $request->user(), AvailabilityContext::APPOINTMENTS);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($windows);
    }

    // ─── Date-specific overrides ────────────────────────────────────────

    public function indexOverrides(Request $request, ?User $user = null)
    {
        $target = $this->resolveTarget($request, $user);

        return response()->json($this->service->getOverrides($target, $request->query('from'), $request->query('to'), AvailabilityContext::APPOINTMENTS));
    }

    public function storeOverride(StoreAppointmentAvailabilityOverrideRequest $request, ?User $user = null)
    {
        $target = $this->resolveTarget($request, $user);

        try {
            $override = $this->service->createOverride($target, $request->validated(), $request->user(), AvailabilityContext::APPOINTMENTS);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($override, 201);
    }

    public function updateOverride(UpdateAppointmentAvailabilityOverrideRequest $request, AppointmentAvailabilityOverride $override, ?User $user = null)
    {
        $target = $this->resolveTarget($request, $user);
        if ($override->user_id !== $target->id || $override->context !== AvailabilityContext::APPOINTMENTS) {
            abort(404, 'Override not found for this staff member.');
        }

        try {
            $override = $this->service->updateOverride($override, $request->validated(), $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($override);
    }

    public function destroyOverride(Request $request, AppointmentAvailabilityOverride $override, ?User $user = null)
    {
        $target = $this->resolveTarget($request, $user);
        if ($override->user_id !== $target->id || $override->context !== AvailabilityContext::APPOINTMENTS) {
            abort(404, 'Override not found for this staff member.');
        }

        $this->service->deleteOverride($override, $request->user());

        return response()->json(null, 204);
    }

    // ─── Blocked periods ────────────────────────────────────────────────

    public function indexBlockedPeriods(Request $request, ?User $user = null)
    {
        $target = $this->resolveTarget($request, $user);

        return response()->json($this->service->getBlockedPeriods($target));
    }

    public function storeBlockedPeriod(StoreAppointmentBlockedPeriodRequest $request, ?User $user = null)
    {
        $target = $this->resolveTarget($request, $user);
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

        $period = $this->service->createBlockedPeriod($target, [
            'starts_at' => $startsAt,
            'ends_at'   => $endsAt,
            'timezone'  => $data['timezone'],
            'reason'    => $data['reason'] ?? null,
        ], $request->user());

        return response()->json($period, 201);
    }

    public function updateBlockedPeriod(UpdateAppointmentBlockedPeriodRequest $request, AppointmentBlockedPeriod $blockedPeriod, ?User $user = null)
    {
        $target = $this->resolveTarget($request, $user);
        if ($blockedPeriod->user_id !== $target->id) {
            abort(404, 'Blocked period not found for this staff member.');
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

    public function destroyBlockedPeriod(Request $request, AppointmentBlockedPeriod $blockedPeriod, ?User $user = null)
    {
        $target = $this->resolveTarget($request, $user);
        if ($blockedPeriod->user_id !== $target->id) {
            abort(404, 'Blocked period not found for this staff member.');
        }

        $this->service->deleteBlockedPeriod($blockedPeriod, $request->user());

        return response()->json(null, 204);
    }
}
