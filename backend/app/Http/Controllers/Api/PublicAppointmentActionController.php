<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendAppointmentEmailJob;
use App\Models\Appointment;
use App\Services\AppointmentEmailService;
use App\Services\AppointmentPublicLinkService;
use App\Services\AppointmentSchedulingService;
use App\Services\AppointmentWorkflowService;
use App\Services\TimezoneResolver;
use Illuminate\Http\Request;

/**
 * Signed public actions (Phase 4) — the backend for the marketing site's
 * `/appointments/{token}` cancel/reschedule confirmation pages.
 *
 * Every route here, including `reschedule/slots`, sits behind Laravel's
 * built-in `signed` middleware — no custom signature code, and no
 * unsigned public endpoint (routes/api.php). Signature verification is
 * URL-based, not HTTP-verb-based, so the exact same signed link serves
 * both the GET (show confirmation details) and POST (perform the action)
 * requests the marketing page makes. `reschedule/slots` uses `signed:date`
 * specifically — Laravel's built-in mechanism for excluding one legitimately-
 * varying query parameter from the signature (the same feature intended for
 * stripping third-party tracking params like `fbclid`) — so the frontend can
 * freely append `&date=...` as the visitor browses without needing a fresh
 * signature per date.
 */
class PublicAppointmentActionController extends Controller
{
    private const TERMINAL_STATUSES = ['cancelled', 'declined', 'completed', 'no_show'];

    public function __construct(
        private readonly AppointmentEmailService $emailService,
        private readonly AppointmentSchedulingService $schedulingService,
        private readonly AppointmentWorkflowService $workflowService,
        private readonly AppointmentPublicLinkService $linkService,
    ) {
    }

    private function findByToken(string $token): ?Appointment
    {
        return Appointment::where('public_token', $token)->first();
    }

    private function notFound()
    {
        return response()->json(['message' => 'This link is no longer valid.'], 404);
    }

    private function publicView(Appointment $appointment): array
    {
        $appointment->loadMissing('appointmentType');
        $canReschedule = $this->emailService->isReschedulable($appointment);

        return [
            'reference'        => $appointment->reference,
            'status'           => $appointment->status,
            'starts_at'        => $appointment->starts_at,
            'ends_at'          => $appointment->ends_at,
            'booking_timezone' => $appointment->booking_timezone,
            'appointment_type' => [
                'name'             => $appointment->appointmentType?->name,
                'slug'             => $appointment->appointmentType?->slug,
                'duration_minutes' => $appointment->appointmentType?->duration_minutes,
            ],
            'can_cancel'      => $this->emailService->isCancellable($appointment),
            'can_reschedule'  => $canReschedule,
            // Base signed URL (no `date`) the frontend appends `&date=...`
            // to when fetching slots — see class doc.
            'reschedule_slots_url' => $canReschedule ? $this->linkService->rescheduleSlotsApiUrl($appointment) : null,
        ];
    }

    // ─── Cancel ─────────────────────────────────────────────────────────

    public function showCancel(string $token)
    {
        $appointment = $this->findByToken($token);
        if (!$appointment) {
            return $this->notFound();
        }

        return response()->json($this->publicView($appointment));
    }

    public function cancel(Request $request, string $token)
    {
        $appointment = $this->findByToken($token);
        if (!$appointment) {
            return $this->notFound();
        }

        // Idempotent: revisiting an already-cancelled appointment's (still
        // validly signed) link is a friendly no-op, not an error — this is
        // what "prevent replay" means for cancellation specifically.
        if ($appointment->status === 'cancelled') {
            return response()->json(['status' => 'cancelled', 'message' => 'This appointment is already cancelled.']);
        }

        if (in_array($appointment->status, self::TERMINAL_STATUSES, true) || !$this->emailService->isCancellable($appointment)) {
            return response()->json(['message' => 'This appointment can no longer be cancelled online — please contact us directly.'], 422);
        }

        $validated = $request->validate(['reason' => 'nullable|string|max:255']);

        try {
            $appointment = $this->workflowService->transition($appointment, 'cancelled', null, ['reason' => $validated['reason'] ?? null, 'source' => 'public_link']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        SendAppointmentEmailJob::dispatch($appointment->id, 'transition', ['to_status' => 'cancelled'])->afterCommit();

        return response()->json(['status' => 'cancelled']);
    }

    // ─── Reschedule ─────────────────────────────────────────────────────

    public function showReschedule(string $token)
    {
        $appointment = $this->findByToken($token);
        if (!$appointment) {
            return $this->notFound();
        }

        return response()->json($this->publicView($appointment));
    }

    /**
     * Signed via `signed:date` (see class doc). Reuses
     * AppointmentSchedulingService::generateAvailableSlots() directly
     * (the same slot-generation logic the internal and Phase 3 public
     * booking flows use), scoped to this appointment's OWN currently
     * assigned staff member (which may differ from the Appointment
     * Type's configured default if it was reassigned since booking).
     */
    public function rescheduleSlots(Request $request, string $token)
    {
        $appointment = $this->findByToken($token);
        if (!$appointment) {
            return $this->notFound();
        }

        $validated = $request->validate([
            'date'     => 'required|date',
            'timezone' => 'nullable|timezone',
        ]);

        if (in_array($appointment->status, self::TERMINAL_STATUSES, true)) {
            return response()->json(['scheduling_mode' => 'manual', 'slots' => []]);
        }

        $staff = $appointment->assigned_user_id ? $appointment->assignedUser : null;
        if (!$staff) {
            return response()->json(['scheduling_mode' => 'manual', 'slots' => []]);
        }

        $displayTimezone = $validated['timezone'] ?? TimezoneResolver::effectiveTimezone($staff, $staff->organization);
        $slots = $this->schedulingService->generateAvailableSlots($staff, $appointment->appointmentType, $validated['date'], $displayTimezone);

        return response()->json([
            'scheduling_mode' => 'fixed',
            'timezone'        => $displayTimezone,
            'slots'           => $slots,
        ]);
    }

    public function reschedule(Request $request, string $token)
    {
        $appointment = $this->findByToken($token);
        if (!$appointment) {
            return $this->notFound();
        }

        if (in_array($appointment->status, self::TERMINAL_STATUSES, true) || !$this->emailService->isReschedulable($appointment)) {
            return response()->json(['message' => 'This appointment can no longer be rescheduled online — please contact us directly.'], 422);
        }

        $validated = $request->validate([
            'date'       => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'timezone'   => 'required|timezone',
        ]);

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
                $staff, $appointment->appointmentType, $startsAt, $endsAt, $appointment->id, false,
                fn () => $this->workflowService->reschedule($appointment, $startsAt, $endsAt, $validated['timezone'], null, 'Rescheduled by attendee'),
                $appointment->organization,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        // The token just rotated inside reschedule() — this email carries
        // fresh links; the link the visitor just used is now dead.
        SendAppointmentEmailJob::dispatch($appointment->id, 'reschedule')->afterCommit();

        return response()->json($this->publicView($appointment));
    }
}
