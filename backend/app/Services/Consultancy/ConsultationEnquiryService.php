<?php

namespace App\Services\Consultancy;

use App\Models\ActivityLog;
use App\Models\Appointment;
use App\Models\ConsultancyService;
use App\Models\ConsultationEnquiry;
use App\Models\Organization;
use App\Models\SuresignNotification;
use App\Models\User;
use App\Services\AppointmentReferenceService;
use App\Services\AppointmentSchedulingService;
use App\Services\Calendar\AppointmentCalendarSyncService;
use App\Services\NotificationService;
use App\Support\Appointments\AvailabilityContext;
use App\Support\Consultancy\ConsultancyNewBookingNotificationRecipients;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single enquiry-creation code path, called by both the authenticated
 * (ConsultationController) and public (PublicConsultationController)
 * booking flows — never a second, divergent implementation. Delegates
 * conflict-checking and appointment persistence entirely to the existing
 * AppointmentSchedulingService/AppointmentReferenceService; this class adds
 * nothing to the scheduling engine itself, only the enquiry row alongside it.
 *
 * Google Calendar sync (Stage 4B.1) is queued here the same way
 * ConsultancyPaymentConversionService queues it for the paid reservation
 * path — after the enclosing transaction commits, never inline — so every
 * confirmed consultation gets a calendar event regardless of whether it
 * came through this free/direct booking path or the paid checkout path.
 */
class ConsultationEnquiryService
{
    public function __construct(
        private readonly AppointmentReferenceService $referenceService,
        private readonly AppointmentSchedulingService $schedulingService,
        private readonly AppointmentCalendarSyncService $calendarSyncService,
    ) {
    }

    public function book(
        ConsultancyService $service,
        Carbon $startsAt,
        Carbon $endsAt,
        array $attendee,
        array $enquiry,
        string $submittedBy,
        ?User $staff,
        string $bookingSource,
        ?int $organizationId = null,
        ?int $linkedUserId = null,
        ?int $projectId = null,
        ?int $createdByUserId = null,
        ?Organization $organization = null,
    ): Appointment {
        $type = $service->appointmentType;

        $create = function () use (
            $service, $type, $attendee, $enquiry, $submittedBy, $staff, $bookingSource,
            $organizationId, $linkedUserId, $projectId, $createdByUserId, $startsAt, $endsAt,
        ) {
            $reference = $this->referenceService->generate();

            $appointment = Appointment::create([
                'reference'           => $reference,
                'appointment_type_id' => $type->id,
                'assigned_user_id'    => $staff?->id,
                'created_by_user_id'  => $createdByUserId,
                'organization_id'     => $organizationId,
                'linked_user_id'      => $linkedUserId,
                'project_id'          => $projectId,
                'attendee_name'       => $attendee['name'],
                'attendee_email'      => $attendee['email'],
                'attendee_phone'      => $attendee['phone'] ?? null,
                'attendee_job_title'  => $attendee['job_title'] ?? null,
                'attendee_company'    => $attendee['company'] ?? null,
                'attendee_timezone'   => $attendee['timezone'],
                'starts_at'           => $startsAt,
                'ends_at'             => $endsAt,
                'booking_timezone'    => $attendee['timezone'],
                'status'              => $type->requires_confirmation ? 'pending_confirmation' : 'confirmed',
                'booking_source'      => $bookingSource,
                'meeting_method'      => $type->meeting_method,
                'location'            => $type->default_location,
                'attendee_message'    => $enquiry['description'] ?? null,
            ]);

            $consultationEnquiry = ConsultationEnquiry::create([
                'appointment_id'          => $appointment->id,
                'consultancy_service_id'  => $service->id,
                'title'                   => $enquiry['title'],
                'description'             => $enquiry['description'],
                'project_stage'           => $enquiry['project_stage'] ?? null,
                'contract_form'           => $enquiry['contract_form'] ?? null,
                'preferred_outcome'       => $enquiry['preferred_outcome'] ?? null,
                'submitted_by'            => $submittedBy,
            ]);

            $this->notifyOperators($appointment, $staff, $consultationEnquiry);

            ActivityLog::record(
                'consultation.created',
                "Consultation {$appointment->reference} created ({$service->display_name}).",
                $createdByUserId ? User::find($createdByUserId) : null,
                $appointment,
                ['consultancy_service_code' => $service->code, 'submitted_by' => $submittedBy],
                $projectId,
                $organizationId,
            );

            DB::afterCommit(function () use ($appointment) {
                $this->calendarSyncService->queueForAppointment($appointment);
            });

            return $appointment;
        };

        // Consultancy is always its own availability context — never
        // Appointments'/Book a Demo's — see AvailabilityContext's docblock.
        return $this->schedulingService->withConflictCheck($staff, $type, $startsAt, $endsAt, null, false, $create, $organization, AvailabilityContext::CONSULTANCY);
    }

    /**
     * The operator-facing "new booking" in-app notification — confirmed
     * absent entirely before this (an operator only ever learned about a
     * new booking by manually checking the Consultancy Queue). Never sent
     * to the customer; see App\Services\Consultancy\ConsultationCommunicationService
     * for that side. Recipient set is configurable
     * (ConsultancyNewBookingNotificationRecipients), not hardcoded, per
     * explicit instruction — this is the single call site, so a future
     * change only ever needs to touch that one Support class.
     *
     * Called from within $create()'s own DB transaction (like ActivityLog::record()
     * just below it), not deferred to DB::afterCommit() the way Calendar
     * sync is — an in-app notification is a plain local DB write, not an
     * external side effect, so there's no reason to defer it.
     */
    private function notifyOperators(Appointment $appointment, ?User $staff, ConsultationEnquiry $consultationEnquiry): void
    {
        $recipients = match (ConsultancyNewBookingNotificationRecipients::current()) {
            ConsultancyNewBookingNotificationRecipients::ASSIGNED_CONSULTANT => $staff ? collect([$staff]) : collect(),
            default => User::query()
                ->where('is_active', true)
                ->whereNull('banned_at')
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['Super Admin', 'Admin']))
                ->get(),
        };

        foreach ($recipients as $recipient) {
            NotificationService::send(
                $recipient,
                NotificationService::CONSULTATION_BOOKED,
                'New consultation booked',
                "{$appointment->attendee_name} booked a consultation ({$appointment->reference}).",
                ['appointment_id' => $appointment->id],
                [
                    'category'     => SuresignNotification::CATEGORY_CONSULTANCY,
                    'source_type'  => 'consultation_enquiry',
                    'source_id'    => $consultationEnquiry->id,
                    'source_field' => 'created',
                    'action_url'   => "/admin/consultancy/queue/{$appointment->id}",
                ],
            );
        }
    }
}
