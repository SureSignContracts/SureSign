<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Requests\UpdateAppointmentRequest;
use App\Jobs\SendAppointmentEmailJob;
use App\Models\ActivityLog;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Organization;
use App\Models\User;
use App\Services\AppointmentAvailabilityService;
use App\Services\AppointmentReferenceService;
use App\Services\AppointmentSchedulingService;
use App\Services\AppointmentWorkflowService;
use App\Services\TimezoneResolver;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function __construct(
        private readonly AppointmentReferenceService $referenceService,
        private readonly AppointmentSchedulingService $schedulingService,
        private readonly AppointmentWorkflowService $workflowService,
        private readonly AppointmentAvailabilityService $availabilityService,
    ) {
    }

    /**
     * View access: Super Admin sees everything. Admin sees appointments
     * assigned to them, plus unassigned ones (approved permission matrix).
     */
    private function authorizeView(Request $request, Appointment $appointment): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin')) return;
        if ($user->hasRole('Admin') && ($appointment->assigned_user_id === null || $appointment->assigned_user_id === $user->id)) return;
        abort(403, 'Access denied.');
    }

    /**
     * Manage access (edit / confirm / decline / cancel / complete / no-show):
     * Super Admin any appointment; Admin only one assigned to them.
     */
    private function authorizeManage(Request $request, Appointment $appointment): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin')) return;
        if ($user->hasRole('Admin') && $appointment->assigned_user_id === $user->id) return;
        abort(403, 'Access denied.');
    }

    private function requireSuperAdmin(Request $request): void
    {
        if (!$request->user()->hasRole('Super Admin')) {
            abort(403, 'Access denied.');
        }
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $query = Appointment::query()->with(['appointmentType:id,name,slug,color', 'assignedUser:id,name']);

        if (!$user->hasRole('Super Admin')) {
            $query->where(function ($q) use ($user) {
                $q->whereNull('assigned_user_id')->orWhere('assigned_user_id', $user->id);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('appointment_type_id')) {
            $query->where('appointment_type_id', $request->integer('appointment_type_id'));
        }
        if ($request->filled('assigned_user_id')) {
            $query->where('assigned_user_id', $request->integer('assigned_user_id'));
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $query->where(function ($q) use ($search) {
                $q->where('attendee_name', 'like', "%{$search}%")
                    ->orWhere('attendee_email', 'like', "%{$search}%")
                    ->orWhere('attendee_company', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%");
            });
        }

        $appointments = $query->orderByDesc('starts_at')->paginate($request->integer('per_page', 25));

        return response()->json($appointments);
    }

    public function show(Request $request, Appointment $appointment)
    {
        $this->authorizeView($request, $appointment);

        return response()->json($appointment->load(['appointmentType', 'assignedUser:id,name,email', 'createdBy:id,name', 'organization:id,name', 'linkedUser:id,name,email', 'project:id,name']));
    }

    public function store(StoreAppointmentRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        $type = AppointmentType::findOrFail($validated['appointment_type_id']);
        if (!$type->is_active) {
            return response()->json(['message' => 'This appointment type is not active.'], 422);
        }

        $isSuperAdmin = $user->hasRole('Super Admin');

        // Admin must always create appointments assigned to themselves — a
        // Type's default_assigned_user_id is a Super-Admin-only convenience
        // and is ignored for Admin entirely (approved Phase 2 decision).
        // Reassigning to someone else, or leaving it unassigned, is a
        // Super-Admin-only action.
        if ($isSuperAdmin) {
            $assignedUserId = $validated['assigned_user_id'] ?? $type->default_assigned_user_id;
        } else {
            if (!empty($validated['assigned_user_id']) && (int) $validated['assigned_user_id'] !== $user->id) {
                abort(403, 'Admins can only create appointments assigned to themselves.');
            }
            $assignedUserId = $user->id;
        }

        $override = $validated['override'] ?? false;
        if ($override && !$isSuperAdmin) {
            abort(403, 'Only Super Admin may override scheduling validation.');
        }

        $staff = null;
        if ($assignedUserId) {
            $staff = User::find($assignedUserId);
            try {
                $this->availabilityService->assertEligibleStaff($staff);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        // Only relevant for the unassigned (Super-Admin-only) path — supplies
        // the timezone fallback for the type-level notice/advance check when
        // there's no staff member to resolve a timezone from.
        $organization = !empty($validated['organization_id']) ? Organization::find($validated['organization_id']) : null;

        try {
            $startsAt = TimezoneResolver::buildLocalInstant($validated['date'], $validated['start_time'], $validated['timezone']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $endsAt = $startsAt->copy()->addMinutes($type->duration_minutes);

        $create = function () use ($validated, $type, $assignedUserId, $startsAt, $endsAt, $user) {
            $reference = $this->referenceService->generate();

            $appointment = Appointment::create(array_merge($validated, [
                'reference'           => $reference,
                'assigned_user_id'    => $assignedUserId,
                'created_by_user_id'  => $user->id,
                'booking_source'      => $validated['booking_source'] ?? 'admin_created',
                'meeting_method'      => $validated['meeting_method'] ?? $type->meeting_method,
                'location'            => $validated['location'] ?? $type->default_location,
                'starts_at'           => $startsAt,
                'ends_at'             => $endsAt,
                'booking_timezone'    => $validated['timezone'],
                'status'              => $type->requires_confirmation ? 'pending_confirmation' : 'confirmed',
            ]));

            ActivityLog::record(
                'appointment.created',
                "Appointment {$appointment->reference} created ({$type->name}).",
                $user,
                $appointment,
                [],
                $appointment->project_id,
                $appointment->organization_id,
            );

            return $appointment;
        };

        try {
            $appointment = $this->schedulingService->withConflictCheck($staff, $type, $startsAt, $endsAt, null, $override, $create, $organization);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        if ($staff && $override) {
            ActivityLog::record(
                'appointment.availability_override_used',
                "Availability validation overridden while creating {$appointment->reference}.",
                $user,
                $appointment,
                ['context' => 'create', 'override_reason' => $validated['override_reason'] ?? null],
                $appointment->project_id,
                $appointment->organization_id,
            );
        }

        SendAppointmentEmailJob::dispatch($appointment->id, 'created')->afterCommit();

        return response()->json($appointment, 201);
    }

    public function update(UpdateAppointmentRequest $request, Appointment $appointment)
    {
        $this->authorizeManage($request, $appointment);

        $appointment->update($request->validated());

        ActivityLog::record(
            'appointment.updated',
            "Appointment {$appointment->reference} details updated.",
            $request->user(),
            $appointment,
            [],
            $appointment->project_id,
            $appointment->organization_id,
        );

        return response()->json($appointment);
    }

    public function destroy(Request $request, Appointment $appointment)
    {
        $this->requireSuperAdmin($request);

        $appointment->delete();

        ActivityLog::record(
            'appointment.deleted',
            "Appointment {$appointment->reference} deleted.",
            $request->user(),
            $appointment,
            [],
            $appointment->project_id,
            $appointment->organization_id,
        );

        return response()->json(null, 204);
    }

    public function assign(Request $request, Appointment $appointment)
    {
        $this->requireSuperAdmin($request);

        $validated = $request->validate([
            'assigned_user_id' => 'nullable|integer|exists:users,id',
            'override'         => 'sometimes|boolean',
            'override_reason'  => 'required_if:override,true|string|max:255',
        ]);
        $userId = $validated['assigned_user_id'] ?? null;
        $override = $validated['override'] ?? false;

        $staff = null;
        if ($userId) {
            $staff = User::find($userId);
            try {
                $this->availabilityService->assertEligibleStaff($staff);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        // Assigning a previously unassigned (or reassigning an existing)
        // appointment must pass the same full scheduling validation as
        // creating/rescheduling one — approved Phase 2 decision.
        try {
            $appointment = $this->schedulingService->withConflictCheck(
                $staff, $appointment->appointmentType, $appointment->starts_at, $appointment->ends_at, $appointment->id, $override,
                fn () => $this->workflowService->assign($appointment, $userId, $request->user()),
                $appointment->organization,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        if ($staff && $override) {
            ActivityLog::record(
                'appointment.availability_override_used',
                "Availability validation overridden while assigning {$appointment->reference}.",
                $request->user(),
                $appointment,
                ['context' => 'assign', 'override_reason' => $validated['override_reason'] ?? null],
                $appointment->project_id,
                $appointment->organization_id,
            );
        }

        return response()->json($appointment);
    }

    public function reschedule(Request $request, Appointment $appointment)
    {
        $this->authorizeManage($request, $appointment);

        if (in_array($appointment->status, ['cancelled', 'declined', 'completed', 'no_show'], true)) {
            return response()->json(['message' => "Cannot reschedule an appointment that is {$appointment->status}."], 422);
        }

        $validated = $request->validate([
            'date'             => 'required|date',
            'start_time'       => 'required|date_format:H:i',
            'timezone'         => 'required|timezone',
            'reason'           => 'nullable|string|max:255',
            'override'         => 'sometimes|boolean',
            'override_reason'  => 'required_if:override,true|string|max:255',
        ]);

        $override = $validated['override'] ?? false;
        if ($override && !$request->user()->hasRole('Super Admin')) {
            abort(403, 'Only Super Admin may override scheduling validation.');
        }

        try {
            $startsAt = TimezoneResolver::buildLocalInstant($validated['date'], $validated['start_time'], $validated['timezone']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $durationMinutes = $appointment->starts_at->diffInMinutes($appointment->ends_at);
        $endsAt = $startsAt->copy()->addMinutes($durationMinutes);

        $staff = $appointment->assigned_user_id ? $appointment->assignedUser : null;

        try {
            $appointment = $this->schedulingService->withConflictCheck(
                $staff, $appointment->appointmentType, $startsAt, $endsAt, $appointment->id, $override,
                fn () => $this->workflowService->reschedule($appointment, $startsAt, $endsAt, $validated['timezone'], $request->user(), $validated['reason'] ?? null),
                $appointment->organization,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        if ($staff && $override) {
            ActivityLog::record(
                'appointment.availability_override_used',
                "Availability validation overridden while rescheduling {$appointment->reference}.",
                $request->user(),
                $appointment,
                ['context' => 'reschedule', 'override_reason' => $validated['override_reason'] ?? null],
                $appointment->project_id,
                $appointment->organization_id,
            );
        }

        SendAppointmentEmailJob::dispatch($appointment->id, 'reschedule')->afterCommit();

        return response()->json($appointment);
    }

    /**
     * Read-only dry-run for internal create/reschedule forms — always
     * responds 200 with {available: bool, reason?: string} rather than an
     * error status, since "not available" is an expected, normal answer.
     */
    public function checkAvailability(Request $request)
    {
        $validated = $request->validate([
            'appointment_type_id'     => 'required|integer|exists:appointment_types,id',
            'assigned_user_id'        => 'nullable|integer|exists:users,id',
            'organization_id'         => 'nullable|integer|exists:organizations,id',
            'date'                    => 'required|date',
            'start_time'              => 'required|date_format:H:i',
            'timezone'                => 'required|timezone',
            'exclude_appointment_id'  => 'nullable|integer|exists:appointments,id',
        ]);

        // Admin may only ever preview their own availability; Super Admin
        // may preview anyone's. Checked before anything else so an
        // unauthorized target's blocked periods/buffers/name are never
        // computed or returned at all — mirrors the same rule
        // AppointmentAvailabilityController enforces for the real
        // availability-management endpoints.
        if (!empty($validated['assigned_user_id'])) {
            $callingUser = $request->user();
            if (!$callingUser->hasRole('Super Admin') && (int) $validated['assigned_user_id'] !== $callingUser->id) {
                abort(403, 'Access denied.');
            }
        }

        $type = AppointmentType::findOrFail($validated['appointment_type_id']);

        try {
            $startsAt = TimezoneResolver::buildLocalInstant($validated['date'], $validated['start_time'], $validated['timezone']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['available' => false, 'reason' => $e->getMessage()]);
        }
        $endsAt = $startsAt->copy()->addMinutes($type->duration_minutes);

        if (empty($validated['assigned_user_id'])) {
            // Unassigned preview — no staff to check overlap/buffer/availability
            // against, but the Appointment Type's own notice/advance rules
            // still apply, exactly as the real (unassigned) booking path
            // now enforces via AppointmentSchedulingService::withConflictCheck().
            $organization = !empty($validated['organization_id']) ? Organization::find($validated['organization_id']) : null;
            try {
                $this->availabilityService->assertTypeBookable($type, $startsAt, $endsAt, null, $organization);
            } catch (\RuntimeException $e) {
                return response()->json(['available' => false, 'reason' => $e->getMessage()]);
            }
            return response()->json(['available' => true]);
        }

        $staff = User::find($validated['assigned_user_id']);
        $excludeId = $validated['exclude_appointment_id'] ?? null;

        try {
            $this->availabilityService->assertEligibleStaff($staff);
            // Same authoritative, never-overridable checks used by every
            // mutation path (create/reschedule/assign) — this preview must
            // never diverge from what the final transaction actually
            // enforces (see AppointmentSchedulingService's class doc).
            if (!$this->schedulingService->isSlotFree($staff->id, $startsAt, $endsAt, $excludeId)) {
                throw new \RuntimeException('This time overlaps another appointment for this staff member.');
            }
            if ($this->schedulingService->hasBufferedConflict($staff->id, $type, $startsAt, $endsAt, $excludeId)) {
                throw new \RuntimeException("This time does not leave the required buffer around another appointment for {$staff->name}.");
            }
            $this->availabilityService->assertBookable($staff, $type, $startsAt, $endsAt, $excludeId);
        } catch (\RuntimeException $e) {
            return response()->json(['available' => false, 'reason' => $e->getMessage()]);
        }

        return response()->json(['available' => true]);
    }

    public function confirm(Request $request, Appointment $appointment)
    {
        return $this->applyTransition($request, $appointment, 'confirmed');
    }

    public function decline(Request $request, Appointment $appointment)
    {
        $validated = $request->validate(['reason' => 'nullable|string|max:255']);
        return $this->applyTransition($request, $appointment, 'declined', $validated);
    }

    public function cancel(Request $request, Appointment $appointment)
    {
        $validated = $request->validate(['reason' => 'nullable|string|max:255']);
        return $this->applyTransition($request, $appointment, 'cancelled', $validated);
    }

    public function complete(Request $request, Appointment $appointment)
    {
        $validated = $request->validate(['notes' => 'nullable|string|max:5000']);
        return $this->applyTransition($request, $appointment, 'completed', $validated);
    }

    public function noShow(Request $request, Appointment $appointment)
    {
        return $this->applyTransition($request, $appointment, 'no_show');
    }

    private function applyTransition(Request $request, Appointment $appointment, string $toStatus, array $meta = [])
    {
        $this->authorizeManage($request, $appointment);

        try {
            $appointment = $this->workflowService->transition($appointment, $toStatus, $request->user(), $meta);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // completed/no_show intentionally send no attendee email this phase.
        if (in_array($toStatus, ['confirmed', 'declined', 'cancelled'], true)) {
            SendAppointmentEmailJob::dispatch($appointment->id, 'transition', ['to_status' => $toStatus])->afterCommit();
        }

        return response()->json($appointment);
    }
}
