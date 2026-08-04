<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\SuresignSetting;
use Carbon\Carbon;

/**
 * Hand-rolled RFC5545 (iCalendar) generator for a single, non-recurring
 * VEVENT per appointment — no external library, since the requirements here
 * (one event, no recurrence, no two-way sync) don't justify the dependency.
 *
 * Correctness points RFC5545 requires and this deliberately gets right:
 *   - CRLF ("\r\n") line endings throughout, not "\n".
 *   - Line folding: any content line over 75 octets is folded onto a
 *     continuation line starting with a single space (§3.1).
 *   - TEXT value escaping: backslash, semicolon, comma, and newline are
 *     escaped per §3.3.11 — never passed through raw.
 *   - All DATE-TIME values are UTC ("...Z" form) — reusing the appointment's
 *     already-UTC starts_at/ends_at directly; no separate timezone
 *     component is embedded, sidestepping VTIMEZONE complexity entirely.
 *   - UID is a stable, opaque value derived from the appointment's
 *     `reference` — deliberately NOT `public_token`, which rotates on
 *     reschedule (Phase 4 review finding): a UID that changed across a
 *     reschedule would make Google/Apple/Outlook treat the updated invite
 *     as a brand-new, separate event rather than an update to the one
 *     already on the attendee's calendar. `reference` is immutable for the
 *     appointment's lifetime, so the UID never changes across
 *     regenerations of the SAME appointment, including after a reschedule.
 *   - SEQUENCE reuses Appointment::schedule_version, which is bumped on
 *     every reschedule — this is exactly what SEQUENCE is for (telling a
 *     calendar client "this replaces the previous version of this event").
 *   - Cancellation uses METHOD:CANCEL (RFC5546 iTIP), not METHOD:PUBLISH —
 *     see generateCancellation(). PUBLISH has no "remove this" semantics;
 *     CANCEL (with the same UID) is what tells a calendar client to
 *     actually remove/grey-out the previously-added event, rather than
 *     just showing STATUS:CANCELLED as inert metadata on an event that
 *     otherwise still looks live.
 */
class AppointmentIcsService
{
    private const PRODID = '-//SureSign//Appointments//EN';

    /**
     * @param  ?string  $meetingUrl  Communications Upgrade Batch 1 — the
     *                               trusted, provider-normalised Google Meet
     *                               join URL (`AppointmentExternalSync::meeting_join_url`,
     *                               via `ConsultationCommunicationLinks::joinMeetUrl()`),
     *                               passed in explicitly by the caller — this
     *                               service has no knowledge of Consultancy/
     *                               Google itself. Takes priority over
     *                               `Appointment::meeting_url` for LOCATION
     *                               when provided; every existing caller
     *                               passes nothing and gets today's exact
     *                               unchanged output. Never a placeholder —
     *                               omit this argument entirely while Meet is
     *                               pending, per the approved architecture.
     */
    public function generate(Appointment $appointment, ?string $meetingUrl = null): string
    {
        return $this->build($appointment, 'PUBLISH', $this->icsStatus($appointment->status), $meetingUrl);
    }

    /**
     * A dedicated cancellation notice — METHOD:CANCEL, same UID, same
     * SEQUENCE convention, STATUS:CANCELLED unconditionally. Intended to be
     * sent alongside (or instead of) the plain-text cancellation email so
     * calendar clients that understand iTIP CANCEL semantics actually
     * remove the event, not just receive an updated PUBLISH they may
     * ignore.
     */
    public function generateCancellation(Appointment $appointment): string
    {
        return $this->build($appointment, 'CANCEL', 'CANCELLED');
    }

    public function filename(Appointment $appointment): string
    {
        return 'appointment-' . $appointment->reference . '.ics';
    }

    private function build(Appointment $appointment, string $method, string $status, ?string $meetingUrl = null): string
    {
        $appointment->loadMissing('appointmentType', 'assignedUser');
        $settings = SuresignSetting::instance();

        $organizerName  = $appointment->assignedUser?->name ?: ($settings->email_sender_name ?: 'SureSign Contracts');
        $organizerEmail = $appointment->assignedUser?->email ?: ($settings->email_sender_email ?: 'noreply@suresigncontracts.app');

        $summary = trim(($appointment->appointmentType?->name ?: 'Appointment') . " ({$appointment->reference})");

        $descriptionLines = array_filter([
            "Reference: {$appointment->reference}",
            $appointment->appointmentType ? "Duration: {$appointment->appointmentType->duration_minutes} minutes" : null,
            $appointment->meeting_method && $appointment->meeting_method !== 'tbc' ? 'Meeting method: ' . str_replace('_', ' ', $appointment->meeting_method) : null,
            $meetingUrl ? "Join Google Meet: {$meetingUrl}" : null,
            $settings->appointment_default_meeting_instructions,
        ]);
        $description = implode('\n', $descriptionLines);

        $location = $meetingUrl ?: ($appointment->meeting_url ?: ($appointment->location ?: ''));

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . self::PRODID,
            'CALSCALE:GREGORIAN',
            'METHOD:' . $method,
            'BEGIN:VEVENT',
            'UID:' . $this->uidFor($appointment),
            'DTSTAMP:' . $this->utc($appointment->updated_at ?? Carbon::now()),
            'DTSTART:' . $this->utc($appointment->starts_at),
            'DTEND:' . $this->utc($appointment->ends_at),
            'SUMMARY:' . $this->escape($summary),
            'DESCRIPTION:' . $this->escape($description),
            'LOCATION:' . $this->escape($location),
            'ORGANIZER;CN=' . $this->escape($organizerName) . ':mailto:' . $organizerEmail,
            'ATTENDEE;CN=' . $this->escape($appointment->attendee_name) . ';ROLE=REQ-PARTICIPANT:mailto:' . $appointment->attendee_email,
            'STATUS:' . $status,
            'SEQUENCE:' . (int) $appointment->schedule_version,
            'TRANSP:OPAQUE',
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        $folded = [];
        foreach ($lines as $line) {
            $folded[] = $this->fold($line);
        }

        return implode("\r\n", $folded) . "\r\n";
    }

    private function uidFor(Appointment $appointment): string
    {
        return $appointment->reference . '@suresigncontracts.app';
    }

    private function utc(Carbon $moment): string
    {
        return $moment->copy()->setTimezone('UTC')->format('Ymd\THis\Z');
    }

    private function icsStatus(string $status): string
    {
        return match ($status) {
            'confirmed', 'completed' => 'CONFIRMED',
            'cancelled', 'declined' => 'CANCELLED',
            default => 'TENTATIVE', // requested, pending_confirmation, no_show
        };
    }

    /**
     * Escapes a TEXT value per RFC5545 §3.3.11 — backslash first (so it
     * doesn't double-escape the characters escaped after it), then
     * semicolon, comma, and newline.
     */
    private function escape(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(';', '\;', $value);
        $value = str_replace(',', '\,', $value);
        $value = str_replace(["\r\n", "\n", "\r"], '\n', $value);

        return $value;
    }

    /**
     * Folds a content line at 75 octets per RFC5545 §3.1 — continuation
     * lines start with a single space, which the reader strips back out.
     * Operates on bytes (not multibyte characters), matching the RFC's
     * own octet-based limit.
     */
    private function fold(string $line): string
    {
        $limit = 75;
        if (strlen($line) <= $limit) {
            return $line;
        }

        $chunks = [];
        $chunks[] = substr($line, 0, $limit);
        $rest = substr($line, $limit);

        while (strlen($rest) > 0) {
            // Continuation lines get one fewer usable octet, since the
            // leading space itself counts toward nothing extra being
            // available on that line beyond the limit.
            $chunkSize = $limit - 1;
            $chunks[] = ' ' . substr($rest, 0, $chunkSize);
            $rest = substr($rest, $chunkSize);
        }

        return implode("\r\n", $chunks);
    }
}
