<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePublicAppointmentRequest;
use App\Jobs\SendAppointmentEmailJob;
use App\Models\ActivityLog;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\User;
use App\Services\AppointmentAvailabilityService;
use App\Services\AppointmentReferenceService;
use App\Services\AppointmentSchedulingService;
use App\Services\TimezoneResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Public, unauthenticated booking surface (Phase 3) — the backend for
 * `/book/{slug}` on the marketing site. Every method here must:
 *   - never expose a non-public or inactive Appointment Type (same generic
 *     404 whether the slug doesn't exist or simply isn't public — no
 *     confirming/denying existence of a private type to an outside caller);
 *   - never expose assigned-staff identity, internal notes, or any other
 *     appointment's data;
 *   - remain rate-limited (routes/api.php) and re-validate everything
 *     server-side — the frontend's date/slot UI is advisory only.
 *
 * Known, recognised booking sources — anything else falls back to
 * 'public_booking_page'. This is a label for reporting, not a security
 * boundary, so an unrecognised value is just normalised rather than
 * rejected outright.
 */
class PublicAppointmentController extends Controller
{
    private const KNOWN_SOURCES = [
        'marketing_homepage', 'marketing_navigation', 'pricing_page',
        'contact_page', 'public_booking_page',
    ];

    public function __construct(
        private readonly AppointmentReferenceService $referenceService,
        private readonly AppointmentSchedulingService $schedulingService,
        private readonly AppointmentAvailabilityService $availabilityService,
    ) {
    }

    private function findPublicType(string $slug): ?AppointmentType
    {
        return AppointmentType::where('slug', $slug)->where('is_public', true)->where('is_active', true)->first();
    }

    /**
     * A type's booking is handled by a specific staff member only when it's
     * explicitly configured that way (assignment_mode=fixed + an eligible
     * default assignee). Otherwise the booking is created unassigned — an
     * Admin/Super Admin assigns it manually afterwards (mirrors the
     * existing "Super-Admin-created unassigned appointment" path from
     * Phase 1/2, just arriving from the public form instead).
     */
    private function fixedStaffFor(AppointmentType $type): ?User
    {
        if ($type->assignment_mode !== 'fixed' || !$type->default_assigned_user_id) {
            return null;
        }
        $candidate = User::find($type->default_assigned_user_id);

        return ($candidate && $this->availabilityService->isEligibleStaff($candidate)) ? $candidate : null;
    }

    public function showType(string $slug)
    {
        $type = $this->findPublicType($slug);
        if (!$type) {
            return response()->json(['message' => 'This booking page is not available.'], 404);
        }

        return response()->json([
            'name'                => $type->name,
            'slug'                => $type->slug,
            'public_title'        => $type->public_title ?: $type->name,
            'public_description'  => $type->public_description ?: $type->description,
            'duration_minutes'    => $type->duration_minutes,
            'meeting_method'      => $type->meeting_method,
            'requires_confirmation' => $type->requires_confirmation,
            'min_notice_hours'    => $type->min_notice_hours,
            'max_advance_days'    => $type->max_advance_days,
            // 'fixed' when a real staff calendar backs slot generation below,
            // 'manual' when the visitor instead proposes a time for staff to
            // review and assign afterwards.
            'scheduling_mode'     => $this->fixedStaffFor($type) ? 'fixed' : 'manual',
        ]);
    }

    public function slots(Request $request, string $slug)
    {
        $type = $this->findPublicType($slug);
        if (!$type) {
            return response()->json(['message' => 'This booking page is not available.'], 404);
        }

        $validated = $request->validate([
            'date'     => 'required|date',
            'timezone' => 'nullable|timezone',
        ]);

        $staff = $this->fixedStaffFor($type);
        if (!$staff) {
            return response()->json(['scheduling_mode' => 'manual', 'slots' => []]);
        }

        // Slots are always generated against the staff member's own
        // availability window (unchanged) — $displayTimezone only affects
        // the label each bookable slot is returned under, per
        // generateAvailableSlots()'s own doc. Falls back to the staff's
        // timezone when the visitor's isn't supplied, matching the
        // pre-existing behaviour exactly.
        $displayTimezone = $validated['timezone'] ?? TimezoneResolver::effectiveTimezone($staff, $staff->organization);
        $slots = $this->schedulingService->generateAvailableSlots($staff, $type, $validated['date'], $displayTimezone);

        return response()->json([
            'scheduling_mode' => 'fixed',
            'timezone'        => $displayTimezone,
            'slots'           => $slots,
        ]);
    }

    /**
     * Which dates in the given month have at least one bookable slot —
     * powers the public booking calendar's available/unavailable colouring.
     * Reuses AppointmentSchedulingService::bookableDatesInMonth() (itself
     * just generateAvailableSlots() per day) — no separate availability
     * calculation. 'manual' mode has no staff calendar to check against, so
     * every day within the type's own notice/advance window is reported
     * bookable, matching the existing manual-mode UX (any in-window day
     * proceeds to a free-text time proposal).
     */
    public function availability(Request $request, string $slug)
    {
        $type = $this->findPublicType($slug);
        if (!$type) {
            return response()->json(['message' => 'This booking page is not available.'], 404);
        }

        $validated = $request->validate([
            'year'     => 'required|integer|min:2020|max:2100',
            'month'    => 'required|integer|min:1|max:12',
            'timezone' => 'nullable|timezone',
        ]);

        $staff = $this->fixedStaffFor($type);
        if (!$staff) {
            return response()->json(['scheduling_mode' => 'manual', 'dates' => []]);
        }

        $staffTimezone = TimezoneResolver::effectiveTimezone($staff, $staff->organization);
        $displayTimezone = $validated['timezone'] ?? $staffTimezone;
        // Notice/advance bounds stay anchored to the staff's own "now" —
        // this is only a coarse pre-filter to skip obviously-out-of-range
        // staff days before even querying (see bookableDatesInMonth's own
        // doc); the actual per-slot notice/advance enforcement happens
        // inside generateAvailableSlots() via assertBookable(), unaffected
        // by which timezone the result is later labelled in.
        $earliest = Carbon::now($staffTimezone)->toDateString();
        $latest = Carbon::now($staffTimezone)->addDays($type->max_advance_days)->toDateString();

        $dates = $this->schedulingService->bookableDatesInMonth(
            $staff, $type, $validated['year'], $validated['month'], $earliest, $latest, $displayTimezone,
        );

        return response()->json(['scheduling_mode' => 'fixed', 'dates' => $dates]);
    }

    public function store(StorePublicAppointmentRequest $request, string $slug)
    {
        $type = $this->findPublicType($slug);
        if (!$type) {
            return response()->json(['message' => 'This booking page is not available.'], 404);
        }

        $validated = $request->validated();

        // Honeypot tripped — respond as if it succeeded, without creating
        // anything or revealing that bot detection happened.
        if (!empty($validated['website'])) {
            return response()->json(['message' => 'Received.'], 201);
        }

        try {
            $startsAt = TimezoneResolver::buildLocalInstant($validated['date'], $validated['start_time'], $validated['timezone']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $endsAt = $startsAt->copy()->addMinutes($type->duration_minutes);

        $staff = $this->fixedStaffFor($type);
        $source = in_array($validated['source'] ?? null, self::KNOWN_SOURCES, true) ? $validated['source'] : 'public_booking_page';

        $create = function () use ($validated, $type, $staff, $startsAt, $endsAt, $source) {
            $reference = $this->referenceService->generate();

            $appointment = Appointment::create([
                'reference'           => $reference,
                'appointment_type_id' => $type->id,
                'assigned_user_id'    => $staff?->id,
                'created_by_user_id'  => null,
                'organization_id'     => null,
                'attendee_name'       => $validated['attendee_name'],
                'attendee_email'      => $validated['attendee_email'],
                'attendee_phone'      => $validated['attendee_phone'] ?? null,
                'attendee_job_title'  => $validated['attendee_job_title'] ?? null,
                'attendee_company'    => $validated['attendee_company'] ?? null,
                'attendee_timezone'   => $validated['attendee_timezone'],
                'starts_at'           => $startsAt,
                'ends_at'             => $endsAt,
                'booking_timezone'    => $validated['timezone'],
                'status'              => $type->requires_confirmation ? 'requested' : 'confirmed',
                'booking_source'      => $source,
                'meeting_method'      => $type->meeting_method,
                'location'            => $type->default_location,
                'attendee_message'    => $validated['attendee_message'] ?? null,
            ]);

            ActivityLog::record(
                'appointment.created',
                "Appointment {$appointment->reference} created ({$type->name}) via public booking.",
                null,
                $appointment,
                ['booking_source' => $source],
            );

            return $appointment;
        };

        try {
            $appointment = $this->schedulingService->withConflictCheck($staff, $type, $startsAt, $endsAt, null, false, $create);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'That time is no longer available — please choose another.'], 409);
        }

        SendAppointmentEmailJob::dispatch($appointment->id, 'created')->afterCommit();

        return response()->json([
            'reference'        => $appointment->reference,
            'status'           => $appointment->status,
            'starts_at'        => $appointment->starts_at,
            'ends_at'          => $appointment->ends_at,
            'booking_timezone' => $appointment->booking_timezone,
            'appointment_type' => [
                'name'           => $type->name,
                'meeting_method' => $type->meeting_method,
            ],
        ], 201);
    }
}
