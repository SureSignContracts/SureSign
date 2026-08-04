<?php

namespace App\Services\Consultancy;

use App\Models\Appointment;
use App\Models\ConsultationCommunicationDelivery;
use App\Models\SuresignSetting;
use App\Services\AppointmentIcsService;
use App\Services\EmailNotificationService;
use App\Support\Consultancy\ConsultationCommunicationLinks;
use App\Support\Email\EmailComponents;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Consultancy Communications & Global Email Experience Upgrade — Batch 1
 * built `booking_confirmed`/`meeting_link_ready`; Batch 2 added
 * `booking_rescheduled`, `booking_cancelled`, and `meeting_reminder_{offset}`
 * (one distinct type per configured reminder offset — see
 * sendMeetingReminder()'s own docblock); Batch 3 added `consultation_followup`
 * and `summary_published`.
 *
 * `summary_published` was NOT a new dispatch point — it replaces the one
 * that already existed on `ConsultationNotificationService::sendSummaryPublishedNotice()`
 * (via the old `SendConsultationEmailJob`), which this batch's audit found
 * was plain-text, carried no link at all, and read "available in your
 * SureSign account" — wrong for a public no-account customer, which is
 * exactly who Batch 3 exists to serve. `ConsultancyOperationsController::publishSummary()`'s
 * single dispatch call was updated in place to call this service instead;
 * `ConsultationNotificationService` keeps owning only `awaiting_customer`
 * now (an internal "action needed" notice, out of this batch's scope).
 * This is a migration of that one email, not a duplicate of it — see
 * internal-docs/super-admin/consultancy.md's own Batch 3 section.
 *
 * Deliberately a NEW service rather than extending
 * `ConsultationNotificationService` for the REST of its remaining
 * responsibility (`awaiting_customer`) — this one owns the booking/Meet/
 * post-meeting customer-lifecycle communications, a distinct
 * responsibility; mirrored dispatch via `SendConsultationCommunicationJob`.
 *
 * Idempotency is a real DB unique constraint on
 * `consultation_communication_deliveries.idempotency_key`
 * (`{type}:{appointment_id}:{schedule_version}`) — the same "attempt the
 * insert, only send if it succeeds" pattern as
 * `SendAppointmentReminders::claimReminderSend()`. A duplicate call (retried
 * job, reconciliation re-observing the same already-`available` Meet state,
 * two racing queue workers) always collides on INSERT and sends nothing —
 * this is a strict improvement over the old `summary_published` path,
 * which had no idempotency protection at all.
 */
class ConsultationCommunicationService
{
    public function __construct(
        private readonly ConsultationCommunicationLinks $links,
        private readonly AppointmentIcsService $icsService,
    ) {
    }

    public function sendBookingConfirmed(Appointment $appointment): bool
    {
        return $this->sendOnce($appointment, 'booking_confirmed');
    }

    /**
     * Called only from AppointmentCalendarSyncService::applyConferenceResult()
     * on a genuine transition into MeetConferenceState::AVAILABLE — never on
     * an unchanged already-available reconciliation pass (the caller only
     * invokes this when $previousMeetingState !== $newState). The
     * idempotency key here is scoped by schedule_version, not meeting_state,
     * deliberately: if a reschedule later requires a brand-new Calendar/Meet
     * cycle, that's a new schedule_version and a legitimately new
     * "meeting-ready" email, not a resend of this one.
     */
    public function sendMeetingLinkReady(Appointment $appointment): bool
    {
        return $this->sendOnce($appointment, 'meeting_link_ready');
    }

    /**
     * Batch 2 — mirrors AppointmentEmailService::sendForReschedule()'s
     * "updated confirmation" convention for the generic Appointment flow,
     * but through this service's own branded components/ICS/action-link
     * handling. `schedule_version` is bumped by the reschedule itself, so
     * this is always a genuinely new idempotency key, never a resend of the
     * original booking_confirmed.
     */
    public function sendBookingRescheduled(Appointment $appointment): bool
    {
        return $this->sendOnce($appointment, 'booking_rescheduled');
    }

    /**
     * Batch 2. Deliberately does not bump/depend on schedule_version for
     * uniqueness beyond the existing per-type/per-version key — a
     * cancelled appointment's schedule_version doesn't change, so this can
     * only ever be claimed once per appointment (cancellation is terminal).
     */
    public function sendBookingCancelled(Appointment $appointment): bool
    {
        return $this->sendOnce($appointment, 'booking_cancelled');
    }

    /**
     * Batch 2. Reminder idempotency is already fully owned upstream by
     * AppointmentReminderSend's own (appointment_id, offset_minutes,
     * schedule_version) unique constraint — SendAppointmentReminders claims
     * that row before this is ever called. The offset is folded into this
     * communication's own type string purely so
     * consultation_communication_deliveries' unique constraint stays
     * meaningful per-offset too: a 24h and a 1h reminder for the same
     * schedule_version are two distinct communications, not a duplicate of
     * each other.
     */
    public function sendMeetingReminder(Appointment $appointment, int $offsetMinutes): bool
    {
        return $this->sendOnce($appointment, "meeting_reminder_{$offsetMinutes}", $offsetMinutes);
    }

    /**
     * Batch 3. Fires exactly once, at the one canonical business event
     * this batch's architecture audit confirmed represents the
     * consultation having actually taken place:
     * `Appointment::status` transitioning to `completed` (terminal — see
     * `AppointmentWorkflowService::TRANSITIONS`, which has no path back
     * out of `completed`) — never `ConsultationEnquiry::engagement_status`
     * reaching `completed`, which is a separate, later event (the
     * consultant's own post-meeting admin work) that would collapse this
     * email and `summary_published` into the same moment in the common
     * case. Deliberately no summary content here — see sendSummaryPublished().
     */
    public function sendConsultationFollowUp(Appointment $appointment): bool
    {
        return $this->sendOnce($appointment, 'consultation_followup');
    }

    /**
     * Batch 3 — see this class's own docblock for why this replaces the
     * old `ConsultationNotificationService::sendSummaryPublishedNotice()`
     * dispatch rather than sitting alongside it.
     *
     * Must fire again on a genuine REPUBLISH, not just the first publish —
     * confirmed against the old (pre-Batch-3) behaviour, which resent
     * unconditionally on every `publishSummary()` call with no dedup at
     * all. `schedule_version` alone can't be the idempotency key here the
     * way it is for every other type: a republish doesn't change it,
     * which would silently suppress the republish notification — a real
     * regression, not a safety improvement. `customer_summary_published_at`
     * (freshly stamped by `publishSummary()` before this is ever
     * dispatched) is folded into the type string instead, the same way
     * `sendMeetingReminder()` folds in its offset — each publish/republish
     * therefore gets its own genuinely distinct idempotency key, while a
     * retried job for the SAME publish (identical timestamp) still
     * collides and sends nothing twice.
     */
    public function sendSummaryPublished(Appointment $appointment): bool
    {
        $publishedAt = $appointment->consultationEnquiry?->customer_summary_published_at;
        $suffix = $publishedAt?->timestamp ?? 0;

        return $this->sendOnce($appointment, "summary_published_{$suffix}");
    }

    private function sendOnce(Appointment $appointment, string $type, ?int $reminderOffsetMinutes = null): bool
    {
        $idempotencyKey = ConsultationCommunicationDelivery::idempotencyKeyFor(
            $appointment->id, $type, $appointment->schedule_version,
        );

        try {
            $delivery = ConsultationCommunicationDelivery::create([
                'appointment_id'    => $appointment->id,
                'communication_type' => $type,
                'recipient'         => $appointment->attendee_email,
                'schedule_version'  => $appointment->schedule_version,
                'status'            => 'queued',
                'queued_at'         => now(),
                'idempotency_key'   => $idempotencyKey,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already claimed (sent, failed, or genuinely in-flight
            // elsewhere) for this exact appointment/type/schedule_version —
            // never a second send.
            return false;
        }

        $content = match (true) {
            $type === 'booking_confirmed'        => $this->buildBookingConfirmed($appointment),
            $type === 'meeting_link_ready'        => $this->buildMeetingLinkReady($appointment),
            $type === 'booking_rescheduled'        => $this->buildBookingRescheduled($appointment),
            $type === 'booking_cancelled'           => $this->buildBookingCancelled($appointment),
            $type === 'consultation_followup'      => $this->buildConsultationFollowUp($appointment),
            str_starts_with($type, 'summary_published_') => $this->buildSummaryPublished($appointment),
            $reminderOffsetMinutes !== null        => $this->buildMeetingReminder($appointment, $reminderOffsetMinutes),
            default => null,
        };

        if ($content === null) {
            $delivery->update(['status' => 'failed', 'failed_at' => now(), 'failure_category' => 'unknown_communication_type', 'attempt_count' => 1]);
            return false;
        }

        $result = EmailNotificationService::sendDirectWithMessageId(
            toEmail: $appointment->attendee_email,
            subject: $content['subject'],
            bodyText: $content['text'],
            attachments: $content['attachments'],
            category: 'Consultancy',
            htmlBody: $content['html'],
            sendPlainTextAlternative: true,
        );

        $delivery->update($result['sent']
            ? ['status' => 'sent', 'sent_at' => now(), 'provider_message_id' => $result['provider_message_id'], 'attempt_count' => 1]
            : ['status' => 'failed', 'failed_at' => now(), 'failure_category' => 'provider_delivery_failed', 'attempt_count' => 1]);

        return $result['sent'];
    }

    /**
     * @return array{subject: string, html: string, text: string, attachments: array}
     */
    private function buildBookingConfirmed(Appointment $appointment): array
    {
        $appointment->loadMissing('appointmentType', 'assignedUser', 'consultationEnquiry.consultancyService', 'externalSync');
        $serviceName = $appointment->consultationEnquiry?->consultancyService?->display_name
            ?: ($appointment->appointmentType?->name ?: 'Consultation');

        [$detailsRows, $when, $timezone] = $this->detailsRowsFor($appointment, $serviceName);
        $joinUrl = $this->links->joinMeetUrl($appointment);

        $htmlParts = [
            EmailComponents::paragraph("Hi {$appointment->attendee_name},"),
            EmailComponents::paragraph("Your {$serviceName} with SureSign is confirmed."),
            EmailComponents::detailsTable($detailsRows),
        ];
        $textLines = [
            "Hi {$appointment->attendee_name},",
            '',
            "Your {$serviceName} with SureSign is confirmed.",
            '',
            ...$this->detailsTextLines($detailsRows),
        ];

        if ($joinUrl) {
            $htmlParts[] = EmailComponents::button('Join Google Meet', $joinUrl, 'primary');
            $textLines[] = '';
            $textLines[] = "Join Google Meet: {$joinUrl}";
        } else {
            $htmlParts[] = EmailComponents::statusCallout(
                'Your Google Meet link is being prepared. We will send it as soon as it is ready.',
                'info',
            );
            $textLines[] = '';
            $textLines[] = 'Your Google Meet link is being prepared. We will send it as soon as it is ready.';
        }

        $this->appendManageActions($appointment, $htmlParts, $textLines);
        $this->appendSupport($htmlParts, $textLines);

        $withIcs = (bool) SuresignSetting::instance()->appointment_ics_enabled;
        $attachments = $withIcs ? [[
            'name'    => $this->icsService->filename($appointment),
            'content' => $this->icsService->generate($appointment, $joinUrl),
        ]] : [];

        return [
            'subject'     => "{$serviceName} confirmed — {$appointment->reference}",
            'html'        => implode("\n", $htmlParts),
            'text'        => implode("\n", $textLines),
            'attachments' => $attachments,
        ];
    }

    private function buildMeetingLinkReady(Appointment $appointment): array
    {
        $appointment->loadMissing('appointmentType', 'assignedUser', 'consultationEnquiry.consultancyService', 'externalSync');
        $serviceName = $appointment->consultationEnquiry?->consultancyService?->display_name
            ?: ($appointment->appointmentType?->name ?: 'Consultation');

        [$detailsRows] = $this->detailsRowsFor($appointment, $serviceName);
        $joinUrl = $this->links->joinMeetUrl($appointment);

        $htmlParts = [
            EmailComponents::paragraph("Hi {$appointment->attendee_name},"),
            EmailComponents::paragraph('Your meeting is ready. Use the button below to join at the scheduled time.'),
            EmailComponents::detailsTable($detailsRows),
        ];
        $textLines = [
            "Hi {$appointment->attendee_name},",
            '',
            'Your meeting is ready. Use the link below to join at the scheduled time.',
            '',
            ...$this->detailsTextLines($detailsRows),
        ];

        if ($joinUrl) {
            $htmlParts[] = EmailComponents::button('Join Google Meet', $joinUrl, 'primary');
            $textLines[] = '';
            $textLines[] = "Join Google Meet: {$joinUrl}";
        }

        $this->appendManageActions($appointment, $htmlParts, $textLines);
        $this->appendSupport($htmlParts, $textLines);

        $withIcs = (bool) SuresignSetting::instance()->appointment_ics_enabled;
        $attachments = $withIcs ? [[
            'name'    => $this->icsService->filename($appointment),
            'content' => $this->icsService->generate($appointment, $joinUrl),
        ]] : [];

        return [
            'subject'     => "Your Google Meet link is ready — {$appointment->reference}",
            'html'        => implode("\n", $htmlParts),
            'text'        => implode("\n", $textLines),
            'attachments' => $attachments,
        ];
    }

    private function buildBookingRescheduled(Appointment $appointment): array
    {
        $appointment->loadMissing('appointmentType', 'assignedUser', 'consultationEnquiry.consultancyService', 'externalSync');
        $serviceName = $appointment->consultationEnquiry?->consultancyService?->display_name
            ?: ($appointment->appointmentType?->name ?: 'Consultation');

        [$detailsRows] = $this->detailsRowsFor($appointment, $serviceName);
        $joinUrl = $this->links->joinMeetUrl($appointment);

        $htmlParts = [
            EmailComponents::paragraph("Hi {$appointment->attendee_name},"),
            EmailComponents::paragraph("Your {$serviceName} with SureSign has been rescheduled. The new details are below."),
            EmailComponents::detailsTable($detailsRows),
        ];
        $textLines = [
            "Hi {$appointment->attendee_name},",
            '',
            "Your {$serviceName} with SureSign has been rescheduled. The new details are below.",
            '',
            ...$this->detailsTextLines($detailsRows),
        ];

        if ($joinUrl) {
            $htmlParts[] = EmailComponents::button('Join Google Meet', $joinUrl, 'primary');
            $textLines[] = '';
            $textLines[] = "Join Google Meet: {$joinUrl}";
        } else {
            $htmlParts[] = EmailComponents::statusCallout(
                'Your Google Meet link is being prepared. We will send it as soon as it is ready.',
                'info',
            );
            $textLines[] = '';
            $textLines[] = 'Your Google Meet link is being prepared. We will send it as soon as it is ready.';
        }

        $this->appendManageActions($appointment, $htmlParts, $textLines);
        $this->appendSupport($htmlParts, $textLines);

        $withIcs = (bool) SuresignSetting::instance()->appointment_ics_enabled;
        $attachments = $withIcs ? [[
            'name'    => $this->icsService->filename($appointment),
            'content' => $this->icsService->generate($appointment, $joinUrl),
        ]] : [];

        return [
            'subject'     => "{$serviceName} rescheduled — {$appointment->reference}",
            'html'        => implode("\n", $htmlParts),
            'text'        => implode("\n", $textLines),
            'attachments' => $attachments,
        ];
    }

    private function buildBookingCancelled(Appointment $appointment): array
    {
        $appointment->loadMissing('appointmentType', 'consultationEnquiry.consultancyService');
        $serviceName = $appointment->consultationEnquiry?->consultancyService?->display_name
            ?: ($appointment->appointmentType?->name ?: 'Consultation');

        $timezone = $appointment->booking_timezone;
        $when     = $appointment->starts_at->copy()->setTimezone($timezone);
        $summary  = "Your {$serviceName} ({$appointment->reference}) scheduled for {$when->format('l, j F Y')} at {$when->format('H:i')} ({$timezone}) has been cancelled.";

        $htmlParts = [
            EmailComponents::paragraph("Hi {$appointment->attendee_name},"),
            EmailComponents::paragraph($summary),
        ];
        $textLines = [
            "Hi {$appointment->attendee_name},",
            '',
            $summary,
        ];

        if ($appointment->cancellation_reason) {
            $htmlParts[] = EmailComponents::paragraph($appointment->cancellation_reason);
            $textLines[] = '';
            $textLines[] = $appointment->cancellation_reason;
        }

        $this->appendSupport($htmlParts, $textLines);

        $withIcs = (bool) SuresignSetting::instance()->appointment_ics_enabled;
        $attachments = $withIcs ? [[
            'name'    => $this->icsService->filename($appointment),
            'content' => $this->icsService->generateCancellation($appointment),
        ]] : [];

        return [
            'subject'     => "{$serviceName} cancelled — {$appointment->reference}",
            'html'        => implode("\n", $htmlParts),
            'text'        => implode("\n", $textLines),
            'attachments' => $attachments,
        ];
    }

    /**
     * No ICS attachment on a reminder — matches
     * AppointmentEmailService::sendReminder()'s existing convention (the
     * event was already sent to the attendee's calendar at booking time).
     */
    private function buildMeetingReminder(Appointment $appointment, int $offsetMinutes): array
    {
        $appointment->loadMissing('appointmentType', 'consultationEnquiry.consultancyService', 'externalSync');
        $serviceName = $appointment->consultationEnquiry?->consultancyService?->display_name
            ?: ($appointment->appointmentType?->name ?: 'Consultation');

        [$detailsRows, $when, $timezone] = $this->detailsRowsFor($appointment, $serviceName);
        $label   = $offsetMinutes >= 60 ? round($offsetMinutes / 60) . ' hour(s)' : "{$offsetMinutes} minutes";
        $joinUrl = $this->links->joinMeetUrl($appointment);
        $intro   = "This is a reminder that your {$serviceName} ({$appointment->reference}) is coming up in about {$label}, on {$when->format('l, j F Y')} at {$when->format('H:i')} ({$timezone}).";

        $htmlParts = [
            EmailComponents::paragraph("Hi {$appointment->attendee_name},"),
            EmailComponents::paragraph($intro),
            EmailComponents::detailsTable($detailsRows),
        ];
        $textLines = [
            "Hi {$appointment->attendee_name},",
            '',
            $intro,
            '',
            ...$this->detailsTextLines($detailsRows),
        ];

        if ($joinUrl) {
            $htmlParts[] = EmailComponents::button('Join Google Meet', $joinUrl, 'primary');
            $textLines[] = '';
            $textLines[] = "Join Google Meet: {$joinUrl}";
        }

        $this->appendManageActions($appointment, $htmlParts, $textLines);
        $this->appendSupport($htmlParts, $textLines);

        return [
            'subject'     => "Reminder: {$serviceName} — {$appointment->reference}",
            'html'        => implode("\n", $htmlParts),
            'text'        => implode("\n", $textLines),
            'attachments' => [],
        ];
    }

    /**
     * Batch 3, Scope C. Deliberately no summary content and no ICS
     * (nothing left to add to a calendar for a consultation that's already
     * happened) — just a thank-you, a light recap, and an honest "what
     * happens next." Uses the new premium components (meta()/hairline()/
     * quietNote()) introduced this batch, reusing the existing button()/
     * paragraph()/supportBlock() primitives rather than forking them — see
     * EmailComponents' own Batch 3 section for why.
     */
    private function buildConsultationFollowUp(Appointment $appointment): array
    {
        $appointment->loadMissing('appointmentType', 'assignedUser', 'consultationEnquiry.consultancyService');
        $serviceName = $appointment->consultationEnquiry?->consultancyService?->display_name
            ?: ($appointment->appointmentType?->name ?: 'Consultation');
        $consultantName = $appointment->assignedUser?->name;
        $when = $appointment->starts_at->copy()->setTimezone($appointment->booking_timezone);

        $metaRows = array_filter([
            'Consultation' => $serviceName,
            'Consultant'   => $consultantName,
            'Date'         => $when->format('j F Y'),
        ]);

        $htmlParts = [
            EmailComponents::paragraph("Hi {$appointment->attendee_name},"),
            EmailComponents::paragraph("Thank you for meeting with us for your {$serviceName}. We hope it was valuable."),
            EmailComponents::meta($metaRows),
            EmailComponents::hairline(),
            EmailComponents::quietNote("Your consultant is preparing a written summary of your consultation. We'll email you as soon as it's ready — there's nothing further you need to do in the meantime."),
        ];
        $textLines = [
            "Hi {$appointment->attendee_name},",
            '',
            "Thank you for meeting with us for your {$serviceName}. We hope it was valuable.",
            '',
            ...$this->detailsTextLines($metaRows),
            '',
            "Your consultant is preparing a written summary of your consultation. We'll email you as soon as it's ready — there's nothing further you need to do in the meantime.",
        ];

        $viewUrl = $this->links->viewUrl($appointment);
        $htmlParts[] = EmailComponents::button('View Consultation', $viewUrl, 'secondary');
        $textLines[] = '';
        $textLines[] = "View Consultation: {$viewUrl}";

        $this->appendSupport($htmlParts, $textLines);

        return [
            'subject'     => "Thank you for your consultation — {$appointment->reference}",
            'html'        => implode("\n", $htmlParts),
            'text'        => implode("\n", $textLines),
            'attachments' => [],
        ];
    }

    /**
     * Batch 3, Scope D. Never includes the summary text itself (that's the
     * public summary page's job, Scope E) — just enough context (title,
     * consultant, date) to orient the customer, and one secure button.
     * Returns null (a failed delivery) if there is genuinely no enquiry to
     * summarise for — defensive only, since the one real caller
     * (`ConsultancyOperationsController::publishSummary()`) can never reach
     * this without one.
     */
    private function buildSummaryPublished(Appointment $appointment): ?array
    {
        $appointment->loadMissing('appointmentType', 'assignedUser', 'consultationEnquiry.consultancyService');
        $enquiry = $appointment->consultationEnquiry;
        if (!$enquiry || $enquiry->customer_summary_published === null) {
            return null;
        }

        $serviceName = $enquiry->consultancyService?->display_name ?: ($appointment->appointmentType?->name ?: 'Consultation');
        $consultantName = $appointment->assignedUser?->name;
        $when = $appointment->starts_at->copy()->setTimezone($appointment->booking_timezone);

        $metaRows = array_filter([
            'Consultation' => $enquiry->title ?: $serviceName,
            'Consultant'   => $consultantName,
            'Date'         => $when->format('j F Y'),
        ]);

        $summaryUrl = $this->links->summaryUrl($appointment);

        $htmlParts = [
            EmailComponents::paragraph("Hi {$appointment->attendee_name},"),
            EmailComponents::paragraph("A written summary of your consultation is ready to view."),
            EmailComponents::meta($metaRows),
            EmailComponents::hairline(),
            EmailComponents::button('View Consultation Summary', $summaryUrl, 'primary'),
        ];
        $textLines = [
            "Hi {$appointment->attendee_name},",
            '',
            'A written summary of your consultation is ready to view.',
            '',
            ...$this->detailsTextLines($metaRows),
            '',
            "View Consultation Summary: {$summaryUrl}",
        ];

        $this->appendSupport($htmlParts, $textLines);

        return [
            'subject'     => "Your consultation summary is ready — {$appointment->reference}",
            'html'        => implode("\n", $htmlParts),
            'text'        => implode("\n", $textLines),
            'attachments' => [],
        ];
    }

    /**
     * @return array{0: array<string, string>, 1: \Illuminate\Support\Carbon, 2: string}
     */
    private function detailsRowsFor(Appointment $appointment, string $serviceName): array
    {
        $timezone = $appointment->booking_timezone;
        $when     = $appointment->starts_at->copy()->setTimezone($timezone);

        $rows = [
            'Service'      => $serviceName,
            'Reference'    => $appointment->reference,
            'Consultant'   => $appointment->assignedUser?->name ?: 'To be assigned',
            'Date'         => $when->format('l, j F Y'),
            'Time'         => $when->format('H:i') . " ({$timezone})",
        ];
        if ($appointment->appointmentType) {
            $rows['Duration'] = "{$appointment->appointmentType->duration_minutes} minutes";
        }
        $rows['Status'] = ucfirst($appointment->status);

        return [$rows, $when, $timezone];
    }

    /**
     * @param  array<string, string>  $rows
     * @return array<int, string>
     */
    private function detailsTextLines(array $rows): array
    {
        $lines = [];
        foreach ($rows as $label => $value) {
            $lines[] = "{$label}: {$value}";
        }

        return $lines;
    }

    private function appendManageActions(Appointment $appointment, array &$htmlParts, array &$textLines): void
    {
        $manageUrl = $this->links->manageUrl($appointment);
        if ($manageUrl) {
            $htmlParts[] = EmailComponents::button('Manage Consultation', $manageUrl, 'secondary');
            $textLines[] = '';
            $textLines[] = "Manage Consultation: {$manageUrl}";
        }

        $tertiary = [];
        $rescheduleUrl = $this->links->rescheduleUrl($appointment);
        $cancelUrl     = $this->links->cancelUrl($appointment);

        if ($rescheduleUrl) {
            $tertiary[] = ['label' => 'Reschedule', 'url' => $rescheduleUrl];
            $textLines[] = "Reschedule: {$rescheduleUrl}";
        }
        if ($cancelUrl) {
            $tertiary[] = ['label' => 'Cancel Booking', 'url' => $cancelUrl];
            $textLines[] = "Cancel Booking: {$cancelUrl}";
        }
        if (!empty($tertiary)) {
            $htmlParts[] = EmailComponents::textActions($tertiary);
        }
    }

    private function appendSupport(array &$htmlParts, array &$textLines): void
    {
        $supportEmail = SuresignSetting::instance()->support_email;
        $htmlParts[] = EmailComponents::supportBlock($supportEmail);
        $textLines[] = '';
        $textLines[] = $supportEmail ? "Questions? Contact us at {$supportEmail}." : 'Questions? Please get in touch with us.';
    }
}
