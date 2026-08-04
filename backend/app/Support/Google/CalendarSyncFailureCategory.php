<?php

namespace App\Support\Google;

/**
 * Stage 4B.1 — the normalised failure-category vocabulary
 * App\Services\Calendar\GoogleCalendarProvider resolves a failed Calendar
 * API call into. App\Services\Calendar\AppointmentCalendarSyncService
 * switches on these constants only — it never parses a raw exception
 * message or string prefix to decide a state transition (corrected
 * approach; supersedes the string-prefix convention
 * GoogleCalendarProvider::classifyFailure() already uses internally for
 * Stage 4A's own connection-health tracking, which is untouched by this
 * stage). Stage 4B.2 (Google Meet Conference Generation) extends this
 * same vocabulary with Meet-specific categories — see `MEET_CATEGORIES`.
 */
final class CalendarSyncFailureCategory
{
    /** No HTTP response was ever received (timeout, connection reset, DNS failure, gateway failure with unknown execution status). Outcome is genuinely UNKNOWN — never treated as proof the event wasn't created. */
    public const TRANSPORT_UNCERTAIN = 'transport_uncertain';

    /** A definitive Google 5xx / internal provider error response. The request definitely reached Google and definitely did not succeed on THIS attempt, but — per the approved correction — this does not by itself prove no event was created (a gateway may have processed the write before failing on the response leg), so it is still treated as uncertain rather than a clean failure. */
    public const PROVIDER_SERVER_ERROR = 'provider_server_error';

    /** HTTP 429 / quota exceeded — a definitive, recoverable rejection. The request did not create an event. */
    public const RATE_LIMITED = 'rate_limited';

    /** The primary calendar could not be reached this attempt but the condition is expected to be transient (e.g. a momentary Calendar API outage distinct from an outright access/permission problem). */
    public const CALENDAR_TEMPORARILY_UNAVAILABLE = 'calendar_temporarily_unavailable';

    /** The connected account's primary calendar is not accessible at all (e.g. removed, never existed) — a configuration problem, not transient. */
    public const CALENDAR_ACCESS_DENIED = 'calendar_access_denied';

    /** The connection lacks the required OAuth scope(s) — configuration, never retried automatically. */
    public const PERMISSIONS_MISSING = 'permissions_missing';

    /** No connection, or the connection's health is REFRESH_FAILED. Normally decided directly from readiness BEFORE any Google call is attempted (AppointmentCalendarSyncService transitions straight to CalendarSyncState::DISCONNECTED without calling the provider at all) — this category exists for the narrow race where readiness passed moments earlier but the token refresh then fails during the call itself. Either path ends in CalendarSyncState::DISCONNECTED with this category recorded. */
    public const DISCONNECTED = 'disconnected';

    /** Google returned a definitive, well-formed 4xx (validation/malformed request) — the one class of response that safely proves the event was NOT created and never will be by retrying the same payload. */
    public const REJECTED_REQUEST = 'rejected_request';

    /** Reconciliation found more than one Calendar event carrying this row's correlation key — never auto-resolved. */
    public const AMBIGUOUS_RECONCILIATION = 'ambiguous_reconciliation';

    /**
     * Stage 4B.2 — Meet-specific categories. These describe the
     * conferenceData PORTION of an otherwise-successful Calendar
     * response (the event itself was created fine) — never used for a
     * general API/transport failure, which is always one of the Calendar
     * categories above (a single insert() call either succeeds enough to
     * produce an event, in which case only these apply to its conference
     * data, or it fails entirely, in which case only the categories above
     * apply and Meet is never separately evaluated).
     */

    /** Google's own conferenceData indicates this account/solution cannot produce a Meet conference (e.g. Meet unsupported for this account type). */
    public const MEET_NOT_SUPPORTED = 'meet_not_supported';

    /** Google rejected the conference-creation request specifically (e.g. a Workspace admin policy restriction) while the Calendar event itself was created. */
    public const CONFERENCE_CREATION_FORBIDDEN = 'conference_creation_forbidden';

    /** `ConferenceRequestStatus::statusCode === 'failure'` — Google attempted and definitively failed to produce a conference for this event. */
    public const CONFERENCE_SOLUTION_UNAVAILABLE = 'conference_solution_unavailable';

    /** Google reported conference success but the entry-point data was missing, unparseable, or failed URL normalisation — never trusted as available. */
    public const MALFORMED_CONFERENCE_RESPONSE = 'malformed_conference_response';

    public const MEET_CATEGORIES = [
        self::MEET_NOT_SUPPORTED, self::CONFERENCE_CREATION_FORBIDDEN,
        self::CONFERENCE_SOLUTION_UNAVAILABLE, self::MALFORMED_CONFERENCE_RESPONSE,
    ];

    public const ALL = [
        self::TRANSPORT_UNCERTAIN, self::PROVIDER_SERVER_ERROR, self::RATE_LIMITED,
        self::CALENDAR_TEMPORARILY_UNAVAILABLE, self::CALENDAR_ACCESS_DENIED,
        self::PERMISSIONS_MISSING, self::DISCONNECTED, self::REJECTED_REQUEST,
        self::AMBIGUOUS_RECONCILIATION,
        self::MEET_NOT_SUPPORTED, self::CONFERENCE_CREATION_FORBIDDEN,
        self::CONFERENCE_SOLUTION_UNAVAILABLE, self::MALFORMED_CONFERENCE_RESPONSE,
    ];

    /** Categories that leave outcome_uncertain = true, requiring reconciliation before the next create attempt. */
    public const UNCERTAIN = [
        self::TRANSPORT_UNCERTAIN, self::PROVIDER_SERVER_ERROR,
    ];

    /** Categories eligible for automatic RETRY_PENDING (within the sync-row retry budget). */
    public const RECOVERABLE = [
        self::TRANSPORT_UNCERTAIN, self::PROVIDER_SERVER_ERROR,
        self::RATE_LIMITED, self::CALENDAR_TEMPORARILY_UNAVAILABLE,
    ];

    /** Categories that always go straight to MANUAL_REVIEW — never counted against the recoverable retry budget. */
    public const CONFIGURATION = [
        self::CALENDAR_ACCESS_DENIED, self::PERMISSIONS_MISSING,
        self::REJECTED_REQUEST, self::AMBIGUOUS_RECONCILIATION,
    ];
}
