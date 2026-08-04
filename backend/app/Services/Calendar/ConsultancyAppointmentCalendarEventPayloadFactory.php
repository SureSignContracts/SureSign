<?php

namespace App\Services\Calendar;

use App\Models\Appointment;
use Illuminate\Support\Str;

/**
 * Stage 4B.1 — the single authoritative source of the Calendar event
 * payload passed to CalendarProviderInterface::createEvent(). Deliberately
 * named/scoped as Consultancy-specific (approved correction 4) rather than
 * a speculative generic "AppointmentCalendarEventPayloadFactory" — every
 * Appointment eligible for Calendar sync today is a Consultancy booking,
 * and this class's title/description wording says so explicitly. A future
 * second real caller (e.g. Book a Demo) is the trigger to introduce real
 * generality then, not before.
 *
 * Every value originates only from Appointment/AppointmentType/User —
 * never from any request body, and never from Organisation-level branding
 * (not needed for a Calendar event). Excluded categorically:
 * internal_notes, attendee_message, any payment/Stripe field, any AI
 * analysis, and any free-text enquiry content — the description below is
 * a fixed, generated template, never `consultationEnquiry->description`
 * verbatim, so this stays safe even after a future stage that might let
 * customers submit genuine free text into that field.
 *
 * Stage 4B.2 (Google Meet Conference Generation) added `request_conference`
 * — the description/title/attendee content is otherwise unchanged.
 */
class ConsultancyAppointmentCalendarEventPayloadFactory
{
    public const PAYLOAD_VERSION = 'v1';

    /**
     * @param  bool  $requestConference  Stage 4B.2 — whether to request a
     *                                   Google Meet conference as part of
     *                                   this same event creation.
     *                                   Decided by AppointmentCalendarSyncService
     *                                   from Meet readiness, never assumed
     *                                   true unconditionally here — this
     *                                   factory has no readiness knowledge
     *                                   of its own.
     * @return array{
     *     summary: string,
     *     description: string,
     *     start: array{date_time: string, timezone: string},
     *     end: array{date_time: string, timezone: string},
     *     attendees: array<int, array{email: string}>,
     *     correlation_key: string,
     *     request_conference: bool,
     * }
     */
    public function build(Appointment $appointment, string $correlationKey, bool $requestConference = false): array
    {
        $timezone = $appointment->booking_timezone;
        if (empty($timezone)) {
            // Defensive only — the Appointment schema requires this
            // column; never silently fall back to UTC or guess.
            throw new \RuntimeException("Appointment {$appointment->id} has no booking_timezone — cannot build a Calendar payload.");
        }

        $serviceName = $appointment->appointmentType?->name ?? 'Consultation';

        return [
            'summary'     => "SureSign Consultancy — {$serviceName}",
            'description' => $this->buildDescription($appointment, $serviceName),
            'start'       => $this->buildDateTime($appointment->starts_at, $timezone),
            'end'         => $this->buildDateTime($appointment->ends_at, $timezone),
            'attendees'   => $this->buildAttendees($appointment),
            'correlation_key' => $correlationKey,
            // Stage 4B.2 — the SAME correlation_key doubles as Google's
            // conference createRequest.requestId (see
            // GoogleClientAdapter::insertPrimaryCalendarEvent()) — stable
            // across every retry, never regenerated per attempt.
            'request_conference' => $requestConference,
        ];
    }

    /**
     * @return array{date_time: string, timezone: string}
     */
    private function buildDateTime(\Illuminate\Support\Carbon $instant, string $timezone): array
    {
        // Timezone-aware conversion only — Carbon's own setTimezone()
        // preserves the absolute UTC instant while producing the correct
        // local offset; no manual offset arithmetic anywhere (approved
        // correction 6).
        return [
            'date_time' => $instant->copy()->setTimezone($timezone)->toRfc3339String(),
            'timezone'  => $timezone,
        ];
    }

    private function buildDescription(Appointment $appointment, string $serviceName): string
    {
        return implode("\n\n", [
            "Consultation: {$serviceName}",
            "Reference: {$appointment->reference}",
            'Joining details will be sent separately.',
        ]);
    }

    /**
     * @return array<int, array{email: string}>
     */
    private function buildAttendees(Appointment $appointment): array
    {
        $attendees = [];

        $consultantEmail = $appointment->assignedUser?->email;
        $customerEmail = $appointment->attendee_email;

        // Includes both consultant and customer here — this factory only
        // knows Appointment data, never the connected Google account's own
        // identity. The organiser-duplication check (skip re-adding the
        // consultant when their email equals the CONNECTED GOOGLE
        // ACCOUNT's email, since Google already represents the organiser
        // implicitly) is deliberately performed one layer up, in
        // GoogleCalendarProvider::createEvent() — the only place that
        // already loads the GoogleConnection row — not here.
        if ($consultantEmail) {
            $attendees[] = ['email' => $consultantEmail];
        }
        if ($customerEmail && $customerEmail !== $consultantEmail) {
            $attendees[] = ['email' => $customerEmail];
        }

        return $attendees;
    }

    public static function generateCorrelationKey(): string
    {
        return Str::random(40);
    }
}
