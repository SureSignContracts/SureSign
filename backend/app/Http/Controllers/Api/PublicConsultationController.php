<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePublicConsultationRequest;
use App\Jobs\SendConsultationCommunicationJob;
use App\Models\ConsultancyService;
use App\Models\User;
use App\Services\AppointmentSchedulingService;
use App\Services\Consultancy\ConsultancyConsultantResolver;
use App\Services\Consultancy\ConsultationEnquiryService;
use App\Services\TimezoneResolver;
use App\Support\Appointments\AvailabilityContext;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Public, unauthenticated Consultancy booking surface — the counterpart to
 * PublicAppointmentController for services flagged publicly_bookable. Kept
 * as its own controller (rather than folding into PublicAppointmentController)
 * so the enquiry fields and Consultancy-specific eligibility rule
 * (publicly_bookable, not just is_public on the linked type) stay in one
 * place. Same security posture as PublicAppointmentController: generic 404
 * for any non-bookable/nonexistent code, no assigned-staff identity or
 * internal data ever returned, rate-limited in routes/api.php.
 */
class PublicConsultationController extends Controller
{
    private const KNOWN_SOURCES = [
        'marketing_homepage', 'marketing_navigation', 'pricing_page',
        'contact_page', 'consultancy_page', 'public_booking_page',
    ];

    public function __construct(
        private readonly AppointmentSchedulingService $schedulingService,
        private readonly ConsultationEnquiryService $enquiryService,
        private readonly ConsultancyConsultantResolver $consultantResolver,
    ) {
    }

    private function findPublicService(string $code): ?ConsultancyService
    {
        return ConsultancyService::where('code', $code)
            ->where('enabled', true)
            ->where('publicly_bookable', true)
            ->with('appointmentType')
            ->first();
    }

    /**
     * Consultancy Live Booking Upgrade, Stage 1 — Consultancy no longer
     * reads AppointmentType::assignment_mode/default_assigned_user_id at
     * all (approved decision: the consultant is an operational setting, not
     * a per-service property). This is now the ONLY place
     * PublicConsultationController resolves a consultant from — a null
     * result means Consultancy scheduling is not ready, which the existing
     * scheduling_mode: 'manual' fork below already handles correctly with
     * no further special-casing needed.
     */
    private function fixedStaffFor(ConsultancyService $service): ?User
    {
        $type = $service->appointmentType;
        if (!$type->is_public || !$type->is_active) {
            return null;
        }

        return $this->consultantResolver->resolve();
    }

    /**
     * The public Consultancy catalogue — every enabled + publicly_bookable
     * service, for the marketing page's pricing/service list.
     */
    public function index()
    {
        $services = ConsultancyService::where('enabled', true)
            ->where('publicly_bookable', true)
            ->with('appointmentType')
            ->orderBy('display_order')
            ->orderBy('display_name')
            ->get()
            ->map(fn (ConsultancyService $s) => [
                'code'               => $s->code,
                'display_name'       => $s->display_name,
                'public_description' => $s->public_description,
                'duration_minutes'   => $s->appointmentType->duration_minutes,
                'price_minor_units'  => $s->price_minor_units,
                'currency'           => $s->currency,
                'is_introductory'    => $s->is_introductory,
            ]);

        return response()->json($services);
    }

    public function show(string $code)
    {
        $service = $this->findPublicService($code);
        if (!$service || !$service->appointmentType->is_active) {
            return response()->json(['message' => 'This booking page is not available.'], 404);
        }

        $type = $service->appointmentType;

        return response()->json([
            'code'                  => $service->code,
            'display_name'          => $service->display_name,
            'public_description'    => $service->public_description,
            'duration_minutes'      => $type->duration_minutes,
            'meeting_method'        => $type->meeting_method,
            'requires_confirmation' => $type->requires_confirmation,
            'min_notice_hours'      => $type->min_notice_hours,
            'max_advance_days'      => $type->max_advance_days,
            'price_minor_units'     => $service->price_minor_units,
            'currency'              => $service->currency,
            'is_introductory'       => $service->is_introductory,
            'scheduling_mode'       => $this->fixedStaffFor($service) ? 'fixed' : 'manual',
        ]);
    }

    public function slots(Request $request, string $code)
    {
        $service = $this->findPublicService($code);
        if (!$service || !$service->appointmentType->is_active) {
            return response()->json(['message' => 'This booking page is not available.'], 404);
        }

        $validated = $request->validate([
            'date'     => 'required|date',
            'timezone' => 'nullable|timezone',
        ]);

        $staff = $this->fixedStaffFor($service);
        if (!$staff) {
            return response()->json(['scheduling_mode' => 'manual', 'slots' => []]);
        }

        $displayTimezone = $validated['timezone'] ?? TimezoneResolver::effectiveTimezone($staff, $staff->organization);
        $slots = $this->schedulingService->generateAvailableSlots($staff, $service->appointmentType, $validated['date'], $displayTimezone, AvailabilityContext::CONSULTANCY);

        return response()->json([
            'scheduling_mode' => 'fixed',
            'timezone'        => $displayTimezone,
            'slots'           => $slots,
        ]);
    }

    /**
     * Month-level bookable dates — the Consultancy counterpart to
     * PublicAppointmentController::availability(). Same bounded-scan
     * approach (AppointmentSchedulingService::bookableDatesInMonth(), itself
     * just generateAvailableSlots() per candidate day) — no separate
     * availability calculation, no unbounded date generation.
     */
    public function availability(Request $request, string $code)
    {
        $service = $this->findPublicService($code);
        if (!$service || !$service->appointmentType->is_active) {
            return response()->json(['message' => 'This booking page is not available.'], 404);
        }

        $validated = $request->validate([
            'year'     => 'required|integer|min:2020|max:2100',
            'month'    => 'required|integer|min:1|max:12',
            'timezone' => 'nullable|timezone',
        ]);

        $type = $service->appointmentType;
        $staff = $this->fixedStaffFor($service);
        if (!$staff) {
            return response()->json(['scheduling_mode' => 'manual', 'dates' => []]);
        }

        $staffTimezone = TimezoneResolver::effectiveTimezone($staff, $staff->organization);
        $displayTimezone = $validated['timezone'] ?? $staffTimezone;
        $earliest = Carbon::now($staffTimezone)->toDateString();
        $latest = Carbon::now($staffTimezone)->addDays($type->max_advance_days)->toDateString();

        $dates = $this->schedulingService->bookableDatesInMonth(
            $staff, $type, $validated['year'], $validated['month'], $earliest, $latest, $displayTimezone, AvailabilityContext::CONSULTANCY,
        );

        return response()->json(['scheduling_mode' => 'fixed', 'dates' => $dates]);
    }

    public function store(StorePublicConsultationRequest $request, string $code)
    {
        $service = $this->findPublicService($code);
        if (!$service || !$service->appointmentType->is_active) {
            return response()->json(['message' => 'This booking page is not available.'], 404);
        }

        $validated = $request->validated();

        // Honeypot tripped — respond as if it succeeded, without creating
        // anything or revealing that bot detection happened. Mirrors
        // PublicAppointmentController::store()'s identical rationale.
        if (!empty($validated['website'])) {
            return response()->json(['message' => 'Received.'], 201);
        }

        try {
            $startsAt = TimezoneResolver::buildLocalInstant($validated['date'], $validated['start_time'], $validated['timezone']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $endsAt = $startsAt->copy()->addMinutes($service->appointmentType->duration_minutes);

        $staff = $this->fixedStaffFor($service);
        $source = in_array($validated['source'] ?? null, self::KNOWN_SOURCES, true) ? $validated['source'] : 'public_booking_page';

        try {
            $appointment = $this->enquiryService->book(
                service: $service,
                startsAt: $startsAt,
                endsAt: $endsAt,
                attendee: [
                    'name'      => $validated['attendee_name'],
                    'email'     => $validated['attendee_email'],
                    'phone'     => $validated['attendee_phone'] ?? null,
                    'job_title' => $validated['attendee_job_title'] ?? null,
                    'company'   => $validated['attendee_company'] ?? null,
                    'timezone'  => $validated['attendee_timezone'],
                ],
                enquiry: [
                    'title'             => $validated['title'],
                    'description'       => $validated['description'],
                    'project_stage'     => $validated['project_stage'] ?? null,
                    'contract_form'     => $validated['contract_form'] ?? null,
                    'preferred_outcome' => $validated['preferred_outcome'] ?? null,
                ],
                submittedBy: 'public',
                staff: $staff,
                bookingSource: $source,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'That time is no longer available — please choose another.'], 409);
        }

        // Communications Upgrade Batch 1 — Consultancy bookings now send
        // their own confirmation (SendConsultationCommunicationJob), not the
        // generic AppointmentEmailService one; see that job's docblock.
        SendConsultationCommunicationJob::dispatch($appointment->id, 'booking_confirmed')->afterCommit();

        return response()->json([
            'reference'        => $appointment->reference,
            'status'           => $appointment->status,
            'starts_at'        => $appointment->starts_at,
            'ends_at'          => $appointment->ends_at,
            'booking_timezone' => $appointment->booking_timezone,
            'consultancy_service' => [
                'display_name'   => $service->display_name,
                'meeting_method' => $service->appointmentType->meeting_method,
            ],
        ], 201);
    }
}
