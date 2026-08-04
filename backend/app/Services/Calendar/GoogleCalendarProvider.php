<?php

namespace App\Services\Calendar;

use App\Services\Google\GoogleApiClientInterface;
use App\Services\Google\GoogleConnectionService;
use App\Services\Google\GoogleTokenRefreshService;
use App\Support\Google\CalendarSyncFailureCategory;
use App\Support\Google\CalendarSyncFailureException;

/**
 * Google Integration Foundation, Stage 4A — the Google implementation of
 * both CalendarProviderInterface and MeetingProviderInterface (one class,
 * two interfaces — see MeetingProviderInterface's own docblock for why
 * this is correct specifically for Google, not a general architectural
 * shortcut). Uses the official `google/apiclient` SDK directly — no
 * Consultancy-specific assumption exists anywhere in this class.
 *
 * Stage 4A implemented only connection/health/capability methods. Stage
 * 4B.1 (Google Calendar Event Synchronisation) adds createEvent()/
 * findEventByCorrelationKey() below — still no update/delete/Meet method
 * exists.
 *
 * `sendUpdates` is always 'none' here, unconditionally — this is the
 * approved Stage 4B.1 decision (see CalendarProviderInterface::createEvent()'s
 * own docblock), not something any caller can override.
 */
class GoogleCalendarProvider implements CalendarProviderInterface, MeetingProviderInterface
{
    public function __construct(
        private readonly GoogleConnectionService $connectionService,
        private readonly GoogleTokenRefreshService $refreshService,
        private readonly GoogleApiClientInterface $apiClient,
    ) {
    }

    public function isConnected(): bool
    {
        return $this->connectionService->current() !== null;
    }

    public function supportsMeetGeneration(): bool
    {
        // Google Meet is created via the same Calendar API connection —
        // capability is identical to "is a Google Calendar connected."
        return $this->isConnected();
    }

    /**
     * A real, lightweight, non-destructive call — lists at most one event
     * on the connected account's PRIMARY calendar only (Stage 4A supports
     * only the primary calendar — see
     * internal-docs/super-admin/google-integration.md's "Calendar
     * ownership" section for the documented future expansion to a
     * selectable calendar). Never creates, updates, or deletes anything.
     */
    public function testConnection(): array
    {
        $connection = $this->connectionService->current();
        if (!$connection) {
            return $this->result(healthy: false, tokenValid: false, calendarAccessible: false, latencyMs: null, error: 'Not connected.');
        }

        $start = microtime(true);

        try {
            $accessToken = $this->refreshService->ensureFreshAccessToken($connection);

            $this->apiClient->listPrimaryCalendarEvents($accessToken, 1);

            $latencyMs = (int) round((microtime(true) - $start) * 1000);
            $connection->update(['last_successful_call_at' => now()]);

            return $this->result(healthy: true, tokenValid: true, calendarAccessible: true, latencyMs: $latencyMs, error: null);
        } catch (\Throwable $e) {
            $reason = $this->classifyFailure($e);
            $connection->update(['last_failed_call_at' => now(), 'last_failure_reason' => $reason]);

            return $this->result(healthy: false, tokenValid: !str_starts_with($reason, 'refresh_failed:'), calendarAccessible: false, latencyMs: null, error: 'Unable to reach Google Calendar.');
        }
    }

    /**
     * @return array{healthy: bool, token_valid: bool, calendar_accessible: bool, latency_ms: ?int, checked_at: string, error: ?string}
     */
    private function result(bool $healthy, bool $tokenValid, bool $calendarAccessible, ?int $latencyMs, ?string $error): array
    {
        return [
            'healthy'             => $healthy,
            'token_valid'         => $tokenValid,
            'calendar_accessible' => $calendarAccessible,
            'latency_ms'          => $latencyMs,
            'checked_at'          => now()->toIso8601String(),
            'error'               => $error,
        ];
    }

    /**
     * A coarse, safe-to-store classification — never the raw exception
     * message from Google verbatim into a column an Admin diagnostics
     * page displays without review, but specific enough for
     * GoogleHealthService to distinguish "calendar unavailable" from an
     * ordinary refresh failure.
     */
    private function classifyFailure(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'refresh')) {
            return 'refresh_failed: unable to refresh access token';
        }
        if (str_contains($message, '404') || str_contains($message, 'notFound')) {
            return 'calendar_unavailable: primary calendar not found';
        }
        if (str_contains($message, '403') || str_contains($message, 'insufficientPermissions')) {
            return 'permissions_missing: insufficient Calendar API permissions';
        }

        return 'unknown_error: unable to reach Google Calendar';
    }

    /**
     * sendUpdates is fixed to 'none' — see this class's own docblock.
     */
    public function createEvent(array $payload): array
    {
        $connection = $this->connectionService->current();
        if (!$connection) {
            throw new CalendarSyncFailureException(CalendarSyncFailureCategory::DISCONNECTED, 'No Google connection is currently active.');
        }

        try {
            $accessToken = $this->refreshService->ensureFreshAccessToken($connection);
        } catch (\Throwable $e) {
            // The narrow race documented on CalendarSyncFailureCategory::DISCONNECTED —
            // readiness passed moments earlier, but the token refresh
            // itself failed during this call.
            throw new CalendarSyncFailureException(CalendarSyncFailureCategory::DISCONNECTED, 'Unable to refresh the Google connection.', $e);
        }

        // Organiser-duplication avoidance: the connected account IS the
        // organiser (Google sets this implicitly) — never also add it as
        // an explicit attendee. This is the one place that already has
        // both the payload's attendee list and the connection's own
        // identity, so it's where this dedup belongs (see
        // ConsultancyAppointmentCalendarEventPayloadFactory's own
        // docblock).
        if (!empty($connection->connected_email)) {
            $payload['attendees'] = array_values(array_filter(
                $payload['attendees'],
                fn (array $attendee) => strcasecmp($attendee['email'], $connection->connected_email) !== 0,
            ));
        }

        try {
            $result = $this->apiClient->insertPrimaryCalendarEvent($accessToken, $payload, 'none');
        } catch (\Throwable $e) {
            throw $this->classifyCalendarSyncFailure($e);
        }

        if (empty($result['id'])) {
            // A malformed provider response is never treated as success —
            // classified as uncertain (we do not know whether Google
            // actually created something), never a clean failure.
            throw new CalendarSyncFailureException(CalendarSyncFailureCategory::TRANSPORT_UNCERTAIN, 'Google returned a response with no event ID.');
        }

        $connection->update(['last_successful_call_at' => now()]);

        return [
            'event_id'   => (string) $result['id'],
            'created_at' => (string) ($result['created'] ?? now()->toIso8601String()),
            'conference' => $this->normalizeConference($result['conference'] ?? []),
        ];
    }

    public function findEventByCorrelationKey(string $correlationKey): array
    {
        $connection = $this->connectionService->current();
        if (!$connection) {
            throw new CalendarSyncFailureException(CalendarSyncFailureCategory::DISCONNECTED, 'No Google connection is currently active.');
        }

        try {
            $accessToken = $this->refreshService->ensureFreshAccessToken($connection);
        } catch (\Throwable $e) {
            throw new CalendarSyncFailureException(CalendarSyncFailureCategory::DISCONNECTED, 'Unable to refresh the Google connection.', $e);
        }

        try {
            $events = $this->apiClient->listPrimaryCalendarEventsByPrivateProperty($accessToken, 'suresign_correlation_key', $correlationKey);
        } catch (\Throwable $e) {
            throw $this->classifyCalendarSyncFailure($e);
        }

        $connection->update(['last_successful_call_at' => now()]);

        return array_map(fn (array $event) => [
            'event_id'   => (string) $event['id'],
            'conference' => $this->normalizeConference($event['conference'] ?? []),
        ], $events);
    }

    /**
     * Stage 4B.2 — turns the adapter's raw-but-already-array `conference`
     * shape (status/conference_id/conference_type/entry_points) into the
     * final safe result AppointmentCalendarSyncService consumes to decide
     * `meeting_state`. This is deliberately the ONLY place a Meet entry
     * point's URI is inspected — `join_url` is set ONLY when a `video`
     * entry point exists with a URI matching an approved secure Google
     * Meet host/scheme; any other value (missing, wrong scheme, wrong
     * host, e.g. a malformed or unexpected provider response) is dropped
     * to null rather than trusted. This method never decides
     * `meeting_state` itself — it only ever returns facts; the
     * orchestration layer maps `status`/`join_url` presence into a state,
     * exactly as it already does for Calendar failures via
     * CalendarSyncFailureCategory.
     *
     * @return array{status: ?string, conference_id: ?string, conference_type: ?string, join_url: ?string}
     */
    private function normalizeConference(array $raw): array
    {
        $joinUrl = null;
        $videoEntryPoints = array_values(array_filter(
            $raw['entry_points'] ?? [],
            fn (array $entryPoint) => ($entryPoint['type'] ?? null) === 'video' && !empty($entryPoint['uri']),
        ));

        // Exactly one usable video entry point is required — zero means no
        // link to trust, more than one is itself a signal of a malformed/
        // ambiguous response (AppointmentCalendarSyncService treats a null
        // join_url here as MALFORMED_CONFERENCE_RESPONSE when status
        // claims success).
        if (count($videoEntryPoints) === 1 && $this->isSecureMeetUrl($videoEntryPoints[0]['uri'])) {
            $joinUrl = $videoEntryPoints[0]['uri'];
        }

        return [
            'status'          => $raw['status'] ?? null,
            'conference_id'   => $raw['conference_id'] ?? null,
            'conference_type' => $raw['conference_type'] ?? null,
            'join_url'        => $joinUrl,
        ];
    }

    /**
     * Only `https://meet.google.com/...` is ever trusted as a customer-
     * facing joining link — never an arbitrary URL from a malformed or
     * unexpected provider response.
     */
    private function isSecureMeetUrl(string $uri): bool
    {
        $parts = parse_url($uri);

        return ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === 'meet.google.com';
    }

    /**
     * The single place a raw provider/transport exception is turned into
     * App\Support\Google\CalendarSyncFailureCategory. App\Services\Calendar\AppointmentCalendarSyncService
     * never inspects a raw exception itself — it only ever sees
     * CalendarSyncFailureException::category()/isOutcomeUncertain(), or an
     * unclassified \Throwable it deliberately lets propagate (see that
     * service's own docblock on why an unclassified failure must not be
     * caught here either).
     *
     * A Google 5xx is classified PROVIDER_SERVER_ERROR and treated as
     * UNCERTAIN — per the approved correction, a definitive error response
     * does not by itself prove no event was created (a gateway may have
     * processed the write before failing on the response leg). Only a
     * clean 4xx (validation/malformed) is definitive enough to rule out
     * creation entirely.
     */
    private function classifyCalendarSyncFailure(\Throwable $e): CalendarSyncFailureException
    {
        if ($e instanceof \Google\Service\Exception) {
            $code = $e->getCode();

            return match (true) {
                $code === 429 => new CalendarSyncFailureException(CalendarSyncFailureCategory::RATE_LIMITED, 'Google rate-limited the request.', $e),
                $code === 404 => new CalendarSyncFailureException(CalendarSyncFailureCategory::CALENDAR_ACCESS_DENIED, 'The connected primary calendar could not be found.', $e),
                $code === 401 || $code === 403 => new CalendarSyncFailureException(CalendarSyncFailureCategory::PERMISSIONS_MISSING, 'The Google connection lacks the required permissions.', $e),
                $code >= 500 && $code < 600 => new CalendarSyncFailureException(CalendarSyncFailureCategory::PROVIDER_SERVER_ERROR, 'Google reported an internal server error.', $e),
                $code >= 400 && $code < 500 => new CalendarSyncFailureException(CalendarSyncFailureCategory::REJECTED_REQUEST, 'Google rejected the request as invalid.', $e),
                default => new CalendarSyncFailureException(CalendarSyncFailureCategory::PROVIDER_SERVER_ERROR, 'Google returned an unrecognised error response.', $e),
            };
        }

        if ($e instanceof \GuzzleHttp\Exception\ConnectException) {
            return new CalendarSyncFailureException(CalendarSyncFailureCategory::TRANSPORT_UNCERTAIN, 'Unable to reach Google — no response was received.', $e);
        }

        if ($e instanceof \GuzzleHttp\Exception\RequestException && !$e->hasResponse()) {
            return new CalendarSyncFailureException(CalendarSyncFailureCategory::TRANSPORT_UNCERTAIN, 'Unable to reach Google — no response was received.', $e);
        }

        // Genuinely unclassified — the caller is expected to let this
        // propagate as-is rather than catch CalendarSyncFailureException,
        // so it reaches the queue job as an infrastructure-level failure.
        throw $e;
    }
}
