<?php

namespace App\Services\Calendar;

/**
 * Google Integration Foundation, Stage 4A — the platform-level meeting/
 * video-conferencing capability abstraction, deliberately kept SEPARATE
 * from CalendarProviderInterface even though, for Google specifically, one
 * class (App\Services\Calendar\GoogleCalendarProvider) implements both —
 * Google Meet creation happens through the Calendar API's own
 * `conferenceData` field on an event, not a separate API. This split is an
 * architectural boundary, not a statement about Google's API surface: a
 * future provider pairing (e.g. Google Calendar + Zoom, or Microsoft 365
 * paired with Teams) may need calendar and meeting-hosting to be genuinely
 * separate services, and this interface split is what allows that without
 * redesigning CalendarProviderInterface.
 *
 * Stage 4A intentionally exposes capability-reporting only — actual
 * meeting creation is Stage 4B's own addition, once Consultancy exists as
 * a real caller.
 */
interface MeetingProviderInterface
{
    public function supportsMeetGeneration(): bool;
}
