<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\SuresignSetting;
use Carbon\Carbon;

/**
 * Composes and sends every attendee-facing appointment email. Reuses
 * EmailNotificationService::sendDirect() (single explicit recipient — the
 * attendee is external, not tied to an organisation's Client-role users)
 * rather than a parallel notification system.
 *
 * Attendee communication rules (approved):
 *   requested / pending_confirmation → "request received" / "awaiting confirmation"
 *   confirmed                        → confirmation email, ICS attached if enabled
 *   declined                        → declined email
 *   cancelled                       → cancelled email
 *   rescheduled                     → updated confirmation email, replacement ICS
 *   completed / no_show             → no automatic attendee email (this phase)
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

        $lines = [
            "Hi {$appointment->attendee_name},",
            '',
            "This is a reminder that your " . $this->typeName($appointment) . " ({$appointment->reference}) is coming up in about {$label}, on {$when->format('l, j F Y')} at {$when->format('H:i')} ({$timezone}).",
        ];
        $this->appendMeetingDetails($lines, $appointment);
        $this->appendManageLinks($lines, $appointment);

        return $this->send($appointment, 'Reminder: ' . $this->typeName($appointment) . " — {$appointment->reference}", $lines, withIcs: false);
    }

    private function sendAwaitingConfirmation(Appointment $appointment): bool
    {
        $timezone = $appointment->booking_timezone;
        $when     = $appointment->starts_at->copy()->setTimezone($timezone);

        $lines = [
            "Hi {$appointment->attendee_name},",
            '',
            "Thanks for requesting a " . $this->typeName($appointment) . " with SureSign.",
            '',
            "Reference: {$appointment->reference}",
            "Requested time: {$when->format('l, j F Y')} at {$when->format('H:i')} ({$timezone})",
        ];
        if ($appointment->appointmentType) {
            $lines[] = "Duration: {$appointment->appointmentType->duration_minutes} minutes";
        }
        $lines[] = '';
        $lines[] = 'Your appointment is awaiting confirmation from our team — we\'ll be in touch shortly.';
        $this->appendManageLinks($lines, $appointment);

        return $this->send($appointment, $this->typeName($appointment) . " request received — {$appointment->reference}", $lines, withIcs: false);
    }

    private function sendConfirmed(Appointment $appointment, bool $rescheduled = false): bool
    {
        $timezone = $appointment->booking_timezone;
        $when     = $appointment->starts_at->copy()->setTimezone($timezone);

        $lines = [
            "Hi {$appointment->attendee_name},",
            '',
            $rescheduled
                ? 'Your ' . $this->typeName($appointment) . ' with SureSign has been rescheduled.'
                : 'Your ' . $this->typeName($appointment) . ' with SureSign is confirmed.',
            '',
            "Reference: {$appointment->reference}",
            "Date: {$when->format('l, j F Y')}",
            "Time: {$when->format('H:i')} ({$timezone})",
        ];
        if ($appointment->appointmentType) {
            $lines[] = "Duration: {$appointment->appointmentType->duration_minutes} minutes";
        }
        $this->appendMeetingDetails($lines, $appointment);
        $this->appendManageLinks($lines, $appointment);

        $withIcs = (bool) SuresignSetting::instance()->appointment_ics_enabled;
        if ($withIcs) {
            $lines[] = '';
            $lines[] = 'A calendar invite is attached.';
        }

        $subject = $rescheduled
            ? $this->typeName($appointment) . " rescheduled — {$appointment->reference}"
            : $this->typeName($appointment) . " confirmed — {$appointment->reference}";

        return $this->send($appointment, $subject, $lines, withIcs: $withIcs);
    }

    private function sendDeclined(Appointment $appointment): bool
    {
        $lines = [
            "Hi {$appointment->attendee_name},",
            '',
            "Unfortunately your request for a " . $this->typeName($appointment) . " ({$appointment->reference}) could not be accommodated.",
        ];
        if ($appointment->cancellation_reason) {
            $lines[] = $appointment->cancellation_reason;
        }
        $lines[] = '';
        $lines[] = $this->contactLine();

        return $this->send($appointment, $this->typeName($appointment) . " request declined — {$appointment->reference}", $lines, withIcs: false);
    }

    private function sendCancelled(Appointment $appointment): bool
    {
        $timezone = $appointment->booking_timezone;
        $when     = $appointment->starts_at->copy()->setTimezone($timezone);

        $lines = [
            "Hi {$appointment->attendee_name},",
            '',
            "Your " . $this->typeName($appointment) . " ({$appointment->reference}) scheduled for {$when->format('l, j F Y')} at {$when->format('H:i')} ({$timezone}) has been cancelled.",
        ];
        if ($appointment->cancellation_reason) {
            $lines[] = $appointment->cancellation_reason;
        }
        $lines[] = '';
        $lines[] = 'If this wasn\'t expected, ' . lcfirst($this->contactLine());

        $withIcs = (bool) SuresignSetting::instance()->appointment_ics_enabled;
        if ($withIcs) {
            $lines[] = '';
            $lines[] = 'An updated calendar file is attached to remove this from your calendar.';
        }

        return $this->send($appointment, $this->typeName($appointment) . " cancelled — {$appointment->reference}", $lines, withIcs: $withIcs, cancellationIcs: true);
    }

    private function appendMeetingDetails(array &$lines, Appointment $appointment): void
    {
        if ($appointment->meeting_url) {
            $lines[] = "Join: {$appointment->meeting_url}";
        } elseif ($appointment->location) {
            $lines[] = "Location: {$appointment->location}";
        }
        $instructions = SuresignSetting::instance()->appointment_default_meeting_instructions;
        if ($instructions) {
            $lines[] = '';
            $lines[] = $instructions;
        }
    }

    private function appendManageLinks(array &$lines, Appointment $appointment): void
    {
        $reschedule = $this->isReschedulable($appointment) ? $this->linkService->rescheduleMarketingUrl($appointment) : null;
        $cancel     = $this->isCancellable($appointment) ? $this->linkService->cancelMarketingUrl($appointment) : null;

        if ($reschedule || $cancel) {
            $lines[] = '';
            $lines[] = 'Need to make changes?';
            if ($reschedule) {
                $lines[] = "Reschedule: {$reschedule}";
            }
            if ($cancel) {
                $lines[] = "Cancel: {$cancel}";
            }
        }
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

    private function send(Appointment $appointment, string $subject, array $bodyLines, bool $withIcs, bool $cancellationIcs = false): bool
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
            implode("\n", $bodyLines),
            $attachments,
        );
    }
}
