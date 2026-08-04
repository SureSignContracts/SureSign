<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\SuresignSetting;
use App\Support\Email\EmailComponents;
use Carbon\Carbon;

/**
 * Composes and sends every attendee-facing appointment email. Reuses
 * EmailNotificationService::sendDirect() (single explicit recipient — the
 * attendee is external, not tied to an organisation's Client-role users)
 * rather than a parallel notification system.
 *
 * Attendee communication rules (approved, unchanged by Batch 4):
 *   requested / pending_confirmation → "request received" / "awaiting confirmation"
 *   confirmed                        → confirmation email, ICS attached if enabled
 *   declined                        → declined email
 *   cancelled                       → cancelled email
 *   rescheduled                     → updated confirmation email, replacement ICS
 *   completed / no_show             → no automatic attendee email (this phase)
 *
 * Communications Platform, Batch 4 — this is the family Batch 1's own
 * scope note named as the intended "global (non-Consultancy) email-family
 * visual migration": every method below now builds its HTML via
 * App\Support\Email\EmailComponents (the same premium components
 * Consultancy already uses) and sends a genuine plaintext alternative,
 * rather than the previous `nl2br(e($bodyText))` fallback with raw URLs.
 * No business rule, trigger, subject wording, or ICS condition changed —
 * only how each is rendered.
 *
 * Every method here is expected to be called from SendAppointmentEmailJob
 * (queued, dispatched only after the triggering DB transaction commits) —
 * never called synchronously from a request/response cycle.
 */
class AppointmentEmailService
{
    public function __construct(
        private readonly AppointmentIcsService $icsService,
        private readonly AppointmentPublicLinkService $linkService,
    ) {
    }

    public function isCancellable(Appointment $appointment): bool
    {
        if (!in_array($appointment->status, ['requested', 'pending_confirmation', 'confirmed'], true)) {
            return false;
        }
        $cutoff = SuresignSetting::instance()->appointment_cancellation_cutoff_hours;

        return Carbon::now()->lt($appointment->starts_at->copy()->subHours($cutoff));
    }

    public function isReschedulable(Appointment $appointment): bool
    {
        if (!in_array($appointment->status, ['requested', 'pending_confirmation', 'confirmed'], true)) {
            return false;
        }
        $cutoff = SuresignSetting::instance()->appointment_reschedule_cutoff_hours;

        return Carbon::now()->lt($appointment->starts_at->copy()->subHours($cutoff));
    }

    /**
     * Called right after a new appointment is created (internal or public).
     */
    public function sendForCreation(Appointment $appointment): bool
    {
        return match ($appointment->status) {
            'requested', 'pending_confirmation' => $this->sendAwaitingConfirmation($appointment),
            'confirmed' => $this->sendConfirmed($appointment),
            default => false,
        };
    }

    /**
     * Called after a status transition (confirm/decline/cancel/complete/no_show).
     */
    public function sendForTransition(Appointment $appointment, string $toStatus): bool
    {
        return match ($toStatus) {
            'confirmed' => $this->sendConfirmed($appointment),
            'declined' => $this->sendDeclined($appointment),
            'cancelled' => $this->sendCancelled($appointment),
            default => false, // completed, no_show — no attendee email this phase
        };
    }

    public function sendForReschedule(Appointment $appointment): bool
    {
        return $this->sendConfirmed($appointment, rescheduled: true);
    }

    public function sendReminder(Appointment $appointment, int $offsetMinutes): bool
    {
        $timezone = $appointment->booking_timezone;
        $when     = $appointment->starts_at->copy()->setTimezone($timezone);
        $label    = $offsetMinutes >= 60 ? round($offsetMinutes / 60) . ' hour(s)' : "{$offsetMinutes} minutes";
        $intro    = "This is a reminder that your " . $this->typeName($appointment) . " ({$appointment->reference}) is coming up in about {$label}, on {$when->format('l, j F Y')} at {$when->format('H:i')} ({$timezone}).";

        $htmlParts = [
            EmailComponents::paragraph("Hi {$appointment->attendee_name},"),
            EmailComponents::paragraph($intro),
        ];
        $textLines = [
            "Hi {$appointment->attendee_name},",
            '',
            $intro,
        ];

        $this->appendMeetingDetails($htmlParts, $textLines, $appointment);
        $this->appendManageLinks($htmlParts, $textLines, $appointment);
        $this->appendSupport($htmlParts, $textLines);

        return $this->send($appointment, 'Reminder: ' . $this->typeName($appointment) . " — {$appointment->reference}", $htmlParts, $textLines, withIcs: false);
    }

    private function sendAwaitingConfirmation(Appointment $appointment): bool
    {
        $timezone = $appointment->booking_timezone;
        $when     = $appointment->starts_at->copy()->setTimezone($timezone);
        $metaRows = $this->detailsRows($appointment, $when, $timezone, includeTime: false, label: 'Requested time');

        $htmlParts = [
            EmailComponents::paragraph("Hi {$appointment->attendee_name},"),
            EmailComponents::paragraph('Thanks for requesting a ' . $this->typeName($appointment) . ' with SureSign.'),
            EmailComponents::meta($metaRows),
            EmailComponents::quietNote("Your appointment is awaiting confirmation from our team — we'll be in touch shortly."),
        ];
        $textLines = [
            "Hi {$appointment->attendee_name},",
            '',
            'Thanks for requesting a ' . $this->typeName($appointment) . ' with SureSign.',
            '',
            ...$this->detailsTextLines($metaRows),
            '',
            "Your appointment is awaiting confirmation from our team — we'll be in touch shortly.",
        ];

        $this->appendManageLinks($htmlParts, $textLines, $appointment);
        $this->appendSupport($htmlParts, $textLines);

        return $this->send($appointment, $this->typeName($appointment) . " request received — {$appointment->reference}", $htmlParts, $textLines, withIcs: false);
    }

    private function sendConfirmed(Appointment $appointment, bool $rescheduled = false): bool
    {
        $timezone = $appointment->booking_timezone;
        $when     = $appointment->starts_at->copy()->setTimezone($timezone);
        $metaRows = $this->detailsRows($appointment, $when, $timezone);
        $intro    = $rescheduled
            ? 'Your ' . $this->typeName($appointment) . ' with SureSign has been rescheduled.'
            : 'Your ' . $this->typeName($appointment) . ' with SureSign is confirmed.';

        $htmlParts = [
            EmailComponents::paragraph("Hi {$appointment->attendee_name},"),
            EmailComponents::paragraph($intro),
            EmailComponents::meta($metaRows),
        ];
        $textLines = [
            "Hi {$appointment->attendee_name},",
            '',
            $intro,
            '',
            ...$this->detailsTextLines($metaRows),
        ];

        $this->appendMeetingDetails($htmlParts, $textLines, $appointment);
        $this->appendManageLinks($htmlParts, $textLines, $appointment);

        $withIcs = (bool) SuresignSetting::instance()->appointment_ics_enabled;
        if ($withIcs) {
            $htmlParts[] = EmailComponents::quietNote('A calendar invite is attached.');
            $textLines[] = '';
            $textLines[] = 'A calendar invite is attached.';
        }

        $this->appendSupport($htmlParts, $textLines);

        $subject = $rescheduled
            ? $this->typeName($appointment) . " rescheduled — {$appointment->reference}"
            : $this->typeName($appointment) . " confirmed — {$appointment->reference}";

        return $this->send($appointment, $subject, $htmlParts, $textLines, withIcs: $withIcs);
    }

    private function sendDeclined(Appointment $appointment): bool
    {
        $intro = 'Unfortunately your request for a ' . $this->typeName($appointment) . " ({$appointment->reference}) could not be accommodated.";

        $htmlParts = [
            EmailComponents::paragraph("Hi {$appointment->attendee_name},"),
            EmailComponents::paragraph($intro),
        ];
        $textLines = [
            "Hi {$appointment->attendee_name},",
            '',
            $intro,
        ];

        if ($appointment->cancellation_reason) {
            $htmlParts[] = EmailComponents::paragraph($appointment->cancellation_reason);
            $textLines[] = '';
            $textLines[] = $appointment->cancellation_reason;
        }

        $this->appendSupport($htmlParts, $textLines);

        return $this->send($appointment, $this->typeName($appointment) . " request declined — {$appointment->reference}", $htmlParts, $textLines, withIcs: false);
    }

    private function sendCancelled(Appointment $appointment): bool
    {
        $timezone = $appointment->booking_timezone;
        $when     = $appointment->starts_at->copy()->setTimezone($timezone);
        $intro    = 'Your ' . $this->typeName($appointment) . " ({$appointment->reference}) scheduled for {$when->format('l, j F Y')} at {$when->format('H:i')} ({$timezone}) has been cancelled.";

        $htmlParts = [
            EmailComponents::paragraph("Hi {$appointment->attendee_name},"),
            EmailComponents::paragraph($intro),
        ];
        $textLines = [
            "Hi {$appointment->attendee_name},",
            '',
            $intro,
        ];

        if ($appointment->cancellation_reason) {
            $htmlParts[] = EmailComponents::paragraph($appointment->cancellation_reason);
            $textLines[] = '';
            $textLines[] = $appointment->cancellation_reason;
        }

        $htmlParts[] = EmailComponents::quietNote("If this wasn't expected, " . lcfirst($this->contactLine()));
        $textLines[] = '';
        $textLines[] = "If this wasn't expected, " . lcfirst($this->contactLine());

        $withIcs = (bool) SuresignSetting::instance()->appointment_ics_enabled;
        if ($withIcs) {
            $htmlParts[] = EmailComponents::quietNote('An updated calendar file is attached to remove this from your calendar.');
            $textLines[] = '';
            $textLines[] = 'An updated calendar file is attached to remove this from your calendar.';
        }

        return $this->send($appointment, $this->typeName($appointment) . " cancelled — {$appointment->reference}", $htmlParts, $textLines, withIcs: $withIcs, cancellationIcs: true);
    }

    /**
     * @return array<string, string>
     */
    private function detailsRows(Appointment $appointment, Carbon $when, string $timezone, bool $includeTime = true, string $label = 'Date'): array
    {
        $rows = ['Reference' => $appointment->reference];

        if ($includeTime) {
            $rows['Date'] = $when->format('l, j F Y');
            $rows['Time'] = $when->format('H:i') . " ({$timezone})";
        } else {
            $rows[$label] = $when->format('l, j F Y') . ' at ' . $when->format('H:i') . " ({$timezone})";
        }

        if ($appointment->appointmentType) {
            $rows['Duration'] = "{$appointment->appointmentType->duration_minutes} minutes";
        }

        return $rows;
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

    private function appendMeetingDetails(array &$htmlParts, array &$textLines, Appointment $appointment): void
    {
        if ($appointment->meeting_url) {
            $htmlParts[] = EmailComponents::button('Join Meeting', $appointment->meeting_url, 'primary');
            $textLines[] = '';
            $textLines[] = "Join: {$appointment->meeting_url}";
        } elseif ($appointment->location) {
            $htmlParts[] = EmailComponents::meta(['Location' => $appointment->location]);
            $textLines[] = '';
            $textLines[] = "Location: {$appointment->location}";
        }

        $instructions = SuresignSetting::instance()->appointment_default_meeting_instructions;
        if ($instructions) {
            $htmlParts[] = EmailComponents::quietNote($instructions);
            $textLines[] = '';
            $textLines[] = $instructions;
        }
    }

    private function appendManageLinks(array &$htmlParts, array &$textLines, Appointment $appointment): void
    {
        $reschedule = $this->isReschedulable($appointment) ? $this->linkService->rescheduleMarketingUrl($appointment) : null;
        $cancel     = $this->isCancellable($appointment) ? $this->linkService->cancelMarketingUrl($appointment) : null;

        if (!$reschedule && !$cancel) {
            return;
        }

        $actions = [];
        if ($reschedule) {
            $actions[] = ['label' => 'Reschedule', 'url' => $reschedule];
            $textLines[] = "Reschedule: {$reschedule}";
        }
        if ($cancel) {
            $actions[] = ['label' => 'Cancel', 'url' => $cancel];
            $textLines[] = "Cancel: {$cancel}";
        }

        $htmlParts[] = EmailComponents::textActions($actions);
    }

    private function appendSupport(array &$htmlParts, array &$textLines): void
    {
        $supportEmail = SuresignSetting::instance()->support_email;
        $htmlParts[] = EmailComponents::supportBlock($supportEmail);
        $textLines[] = '';
        $textLines[] = $supportEmail ? "Questions? Contact us at {$supportEmail}." : 'Questions? Please get in touch with us.';
    }

    private function contactLine(): string
    {
        $email = SuresignSetting::instance()->support_email;

        return $email ? "Please contact us at {$email} if you have any questions." : 'Please get in touch if you have any questions.';
    }

    private function typeName(Appointment $appointment): string
    {
        return $appointment->appointmentType?->public_title ?: ($appointment->appointmentType?->name ?: 'appointment');
    }

    private function send(Appointment $appointment, string $subject, array $htmlParts, array $textLines, bool $withIcs, bool $cancellationIcs = false): bool
    {
        $attachments = [];
        if ($withIcs) {
            $attachments[] = [
                'name'    => $this->icsService->filename($appointment),
                'content' => $cancellationIcs
                    ? $this->icsService->generateCancellation($appointment)
                    : $this->icsService->generate($appointment),
            ];
        }

        return EmailNotificationService::sendDirect(
            $appointment->attendee_email,
            $subject,
            implode("\n", $textLines),
            $attachments,
            null,
            'Appointments',
            implode("\n", $htmlParts),
            true,
        );
    }
}
