<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreConsultationRequest;
use App\Jobs\SendConsultationCommunicationJob;
use App\Models\Appointment;
use App\Models\ConsultancyService;
use App\Models\Organization;
use App\Models\User;
use App\Services\AppointmentSchedulingService;
use App\Services\AppointmentWorkflowService;
use App\Services\Consultancy\ConsultancyConsultantResolver;
use App\Services\Consultancy\ConsultationEnquiryService;
use App\Services\TimezoneResolver;
use App\Support\Appointments\AvailabilityContext;
use App\Support\Consultancy\ConsultationMeetingPresenter;
use App\Support\Consultancy\ConsultationPresenter;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Authenticated (Client/Admin/Super Admin) Consultancy surface — a
 * genuinely new authorization boundary, deliberately separate from
 * AppointmentController: every query here is scoped strictly to the
 * caller's own organisation, never opening up general Appointments access
 * to the Client role. See
 * internal-docs/commercial/suresign-consultancy-specification-v1.md, Phase C1.
 */
class ConsultationController extends Controller
{
    /**
     * The relations every ConsultationPresenter::customerFacing() response
     * needs eager-loaded — kept in one place (Phase C2, Batch 2) so
     * index()/show()/store()/cancel() can never drift into inconsistent
     * shapes for what is now the same presenter boundary.
     */
    private const CUSTOMER_RELATIONS = ['appointmentType', 'assignedUser:id,name', 'consultationEnquiry.consultancyService'];

    public function __construct(
        private readonly ConsultationEnquiryService $enquiryService,
        private readonly AppointmentSchedulingService $schedulingService,
        private readonly AppointmentWorkflowService $workflowService,
        private readonly ConsultancyConsultantResolver $consultantResolver,
    ) {
    }

    private function consultationsQuery(Request $request)
    {
        return Appointment::query()
            ->whereHas('consultationEnquiry')
            ->where('organization_id', $request->user()->organization_id);
    }

    public function bookableServices(Request $request)
    {
        $services = ConsultancyService::query()
            ->where('enabled', true)
            ->where('available_to_existing_customers', true)
            ->with('appointmentType')
            ->orderBy('display_order')
            ->orderBy('display_name')
            ->get();

        return response()->json($services);
    }

    private function resolveBookableService(string $code): ConsultancyService
    {
        return ConsultancyService::where('code', $code)
            ->where('enabled', true)
            ->where('available_to_existing_customers', true)
            ->with('appointmentType')
            ->firstOrFail();
    }

    /**
     * The single source of truth for which staff member (if any) a
     * Consultancy Service's scheduling is fixed to. Consultancy Live
     * Booking Upgrade, Stage 1: no longer reads AppointmentType's own
     * assignment_mode/default_assigned_user_id at all — resolved
     * exclusively through ConsultancyConsultantResolver, the single
     * authoritative Consultancy consultant setting. Mirrors
     * PublicConsultationController's identical private method — the two
     * controllers intentionally don't share a base class for this
     * (Appointments' Public/Admin controllers follow the same
     * duplication-over-shared-base pattern), but both must always resolve
     * the same answer.
     */
    private function fixedStaffFor(ConsultancyService $service): ?User
    {
        return $this->consultantResolver->resolve();
    }

    /**
     * Scheduling info for a single bookable service — tells the caller
     * whether to render a manual date/time proposal or the real
     * fixed-staff slot-selection UI, exactly mirroring the public booking
     * flow's own `showType()`/`show()` shape. Never exposes staff identity.
     */
    public function serviceDetail(string $code)
    {
        $service = $this->resolveBookableService($code);
        $type = $service->appointmentType;

        return response()->json([
            'code'                  => $service->code,
            'display_name'          => $service->display_name,
            'public_description'    => $service->public_description,
            'duration_minutes'      => $type->duration_minutes,
            'requires_confirmation' => $type->requires_confirmation,
            'min_notice_hours'      => $type->min_notice_hours,
            'max_advance_days'      => $type->max_advance_days,
            'price_minor_units'     => $service->price_minor_units,
            'currency'              => $service->currency,
            'is_introductory'       => $service->is_introductory,
            'scheduling_mode'       => $this->fixedStaffFor($service) ? 'fixed' : 'manual',
        ]);
    }

    /**
     * Fixed-mode slot generation for the authenticated flow — a thin
     * Consultancy-scoped wrapper delegating every calculation to the exact
     * same AppointmentSchedulingService::generateAvailableSlots() the public
     * flow (PublicConsultationController::slots()) and internal Appointments
     * admin tooling already use. No Consultancy-specific scheduling rule is
     * introduced here; a manual-mode service simply has no staff to
     * generate slots for and always returns an empty list.
     */
    public function serviceSlots(Request $request, string $code)
    {
        $service = $this->resolveBookableService($code);

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
     * Month-level bookable dates for the authenticated flow — mirrors
     * PublicConsultationController::availability() exactly (same bounded
     * AppointmentSchedulingService::bookableDatesInMonth() call, no
     * separate calculation).
     */
    public function serviceAvailability(Request $request, string $code)
    {
        $service = $this->resolveBookableService($code);

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

    public function index(Request $request)
    {
        $appointments = $this->consultationsQuery($request)
            ->with(self::CUSTOMER_RELATIONS)
            ->orderByDesc('starts_at')
            ->paginate($request->integer('per_page', 25));

        $appointments->getCollection()->transform(fn (Appointment $a) => ConsultationPresenter::customerFacing($a));

        return response()->json($appointments);
    }

    public function show(Request $request, Appointment $appointment)
    {
        $this->authorizeOwnOrganization($request, $appointment);
        $appointment->load([...self::CUSTOMER_RELATIONS, 'externalSync']);

        // Stage 4B.2 — the Meet joining link is deliberately appended only
        // here, never inside ConsultationPresenter::customerFacing()
        // itself (shared by index() above) — this is the one authorised
        // detail response, never a list. See
        // ConsultationMeetingPresenter's own docblock.
        return response()->json([
            ...ConsultationPresenter::customerFacing($appointment),
            'meeting' => ConsultationMeetingPresenter::customerFacing($appointment),
        ]);
    }

    private function authorizeOwnOrganization(Request $request, Appointment $appointment): void
    {
        if (!$appointment->consultationEnquiry
            || $appointment->organization_id !== $request->user()->organization_id) {
            abort(403, 'Access denied.');
        }
    }

    public function store(StoreConsultationRequest $request)
    {
        $validated = $request->validated();
        $user = $request->user();

        $service = $this->resolveBookableService($validated['consultancy_service_code']);
        $type = $service->appointmentType;
        $staff = $this->fixedStaffFor($service);

        try {
            $startsAt = TimezoneResolver::buildLocalInstant($validated['date'], $validated['start_time'], $validated['timezone']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $endsAt = $startsAt->copy()->addMinutes($type->duration_minutes);

        $organization = Organization::find($user->organization_id);

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
                submittedBy: 'authenticated',
                staff: $staff,
                bookingSource: 'consultancy_in_app',
                organizationId: $user->organization_id,
                linkedUserId: $user->id,
                createdByUserId: $user->id,
                organization: $organization,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        // Communications Upgrade Batch 1 — Consultancy bookings now send
        // their own confirmation (SendConsultationCommunicationJob), not the
        // generic AppointmentEmailService one; see that job's docblock.
        SendConsultationCommunicationJob::dispatch($appointment->id, 'booking_confirmed')->afterCommit();

        return response()->json(ConsultationPresenter::customerFacing($appointment->load(self::CUSTOMER_RELATIONS)), 201);
    }

    public function cancel(Request $request, Appointment $appointment)
    {
        $this->authorizeOwnOrganization($request, $appointment);

        $validated = $request->validate(['reason' => 'nullable|string|max:255']);

        try {
            $appointment = $this->workflowService->transition($appointment, 'cancelled', $request->user(), $validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Communications Upgrade Batch 2 — replaces the generic transition
        // email with Consultancy's own branded cancellation (see
        // store()'s identical Batch 1 precedent above for booking_confirmed).
        SendConsultationCommunicationJob::dispatch($appointment->id, 'booking_cancelled')->afterCommit();

        return response()->json(ConsultationPresenter::customerFacing($appointment->load(self::CUSTOMER_RELATIONS)));
    }
}
