<?php

namespace App\Services\Calendar;

/**
 * Google Integration Foundation, Stage 4A — the platform-level calendar
 * capability abstraction. Lives alongside App\Services\Billing, not inside
 * Consultancy — Google is AN implementation of this interface, never the
 * architecture itself. Future providers (Microsoft 365, a hypothetical
 * Google Calendar + Zoom split) implement the same contract; nothing in
 * this interface, or in any caller of it, may assume Google specifically.
 *
 * Stage 4A deliberately kept this interface to connection/health/
 * capability concerns only. Stage 4B.1 (Google Calendar Event
 * Synchronisation) added exactly two event-level operations below, once a
 * real caller (Consultancy, via App\Services\Calendar\AppointmentCalendarSyncService)
 * needed them — still additive to this same interface, never a redesign.
 * Update/delete/cancellation methods remain deliberately absent — see
 * internal-docs/super-admin/google-integration.md's Stage 4B.1/4B.2
 * sections for the full scope boundary.
 *
 * Stage 4B.2 (Google Meet Conference Generation) extended `createEvent()`/
 * `findEventByCorrelationKey()`'s return shape with a normalised
 * `conference` result — Meet is requested as part of THIS SAME event
 * creation call (Google Meet rides on the Calendar API's own
 * `conferenceData`, never a separate API or a separate Calendar event —
 * see MeetingProviderInterface's own docblock, unchanged, for why this
 * split still exists architecturally without a second creation path
 * existing today). No update/delete/Meet-regeneration method was added.
 *
 * Every method here must be safe to call at any time, including when not
 * connected — `isConnected()`/`testConnection()` never throw for "not
 * connected," they report it. `createEvent()`/`findEventByCorrelationKey()`
 * DO throw — callers (AppointmentCalendarSyncService) are expected to
 * classify the thrown exception, never to call these while assuming
 * success.
 */
interface CalendarProviderInterface
{
    public function isConnected(): bool;

    /**
     * A real, lightweight, non-destructive API call (Stage 4A: listing at
     * most one event on the connected account's primary calendar) —
     * proves actual reachability, not just "we have a stored token."
     * Never creates, updates, or deletes anything.
     *
     * @return array{
     *     healthy: bool,
     *     token_valid: bool,
     *     calendar_accessible: bool,
     *     latency_ms: ?int,
     *     checked_at: string,
     *     error: ?string,
     * }
     */
    public function testConnection(): array;

    /**
     * Stage 4B.1/4B.2 — creates one event on the connected account's
     * primary calendar, optionally requesting a Google Meet conference as
     * part of THIS SAME insert() call. Never sends attendee updates/
     * invitations (sendUpdates='none' is applied inside the Google
     * implementation, not left to the caller).
     *
     * Every value in $payload must already be trusted/server-derived —
     * this method performs no validation of payload content, only
     * transmits it (see App\Services\Calendar\ConsultancyAppointmentCalendarEventPayloadFactory,
     * the sole authoritative source of $payload). `$payload['correlation_key']`
     * doubles as Google's own conference `createRequest.requestId` when
     * `$payload['request_conference']` is true — stable across every
     * retry of this same logical operation, per Google's own documented
     * requestId semantics (a retry with the same requestId is recognised
     * as the same request, never a fresh conference).
     *
     * @param  array  $payload  {summary, description, start: {date_time, timezone},
     *                          end: {date_time, timezone}, attendees: array<int, array{email:string}>,
     *                          correlation_key: string, request_conference: bool}
     * @return array{
     *     event_id: string,
     *     created_at: string,
     *     conference: array{
     *         status: ?string,
     *         conference_id: ?string,
     *         conference_type: ?string,
     *         join_url: ?string,
     *     },
     * }
     * @throws \App\Support\Google\CalendarSyncFailureException on any classified failure
     *         (see App\Support\Google\CalendarSyncFailureCategory) — the caller inspects
     *         ->category()/->isOutcomeUncertain(), never a raw message. A Meet-specific
     *         problem NEVER throws here if the Calendar event itself was created — it is
     *         reported via a null/failed `conference` sub-result instead (see
     *         GoogleCalendarProvider's own docblock).
     * @throws \Throwable on any UNCLASSIFIED failure — deliberately left unclassified/
     *         rethrown so the caller's own infrastructure-level retry applies (see
     *         AppointmentCalendarSyncService's docblock).
     */
    public function createEvent(array $payload): array;

    /**
     * Stage 4B.1/4B.2 — the reconciliation lookup: finds every event on
     * the primary calendar carrying this correlation key in its private
     * extended properties, each normalised with its own `conference`
     * sub-result exactly like createEvent()'s. Read-only.
     *
     * @return array<int, array{event_id: string, conference: array}> Zero, one, or many matches.
     * @throws \App\Support\Google\CalendarSyncFailureException on any classified failure.
     * @throws \Throwable on any unclassified failure.
     */
    public function findEventByCorrelationKey(string $correlationKey): array;
}
