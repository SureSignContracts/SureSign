<?php

namespace App\Services\Google;

/**
 * In-memory fake used by the automated test suite and bound whenever
 * app()->environment('testing') is true (see GoogleServiceProvider) — no
 * automated test may ever construct a real \Google\Client or make a real
 * HTTP call to Google. Mirrors App\Services\Billing\FakeBillingProvider's
 * exact convention: deterministic, no network calls, driven entirely by
 * public properties/methods a test sets directly rather than an invented
 * internal state machine.
 */
class FakeGoogleApiClient implements GoogleApiClientInterface
{
    /** @var array<string, array> Issued authorization codes -> the token payload they exchange for. */
    public array $pendingCodes = [];

    /** @var array<string, array> Valid refresh tokens -> the access-token payload they refresh to. */
    public array $refreshableTokens = [];

    /** @var array<string, array> Decoded ID token claims, keyed by the raw id_token string. */
    public array $idTokenClaims = [];

    public bool $revokeShouldFail = false;
    public bool $listEventsShouldFail = false;
    public string $listEventsFailureMessage = 'Simulated Google API failure.';

    /** @var int Number of listPrimaryCalendarEvents() calls made. */
    public int $listEventsCallCount = 0;

    /** @var array<int, string> Revoked tokens, for test assertions. */
    public array $revokedTokens = [];

    public function buildAuthorizationUrl(string $state, array $scopes): string
    {
        return 'https://accounts.google.test/o/oauth2/fake-auth?state=' . urlencode($state) . '&scope=' . urlencode(implode(' ', $scopes));
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        if (!isset($this->pendingCodes[$code])) {
            throw new \RuntimeException('Google rejected the authorization: invalid_grant (fake — no such code configured).');
        }

        return $this->pendingCodes[$code];
    }

    public function decodeIdToken(string $idToken): array
    {
        return $this->idTokenClaims[$idToken] ?? [];
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        if (!isset($this->refreshableTokens[$refreshToken])) {
            throw new \RuntimeException('invalid_grant (fake — refresh token not recognised or revoked).');
        }

        return $this->refreshableTokens[$refreshToken];
    }

    public function revokeToken(string $token): void
    {
        if ($this->revokeShouldFail) {
            throw new \RuntimeException('Simulated Google revoke failure.');
        }
        $this->revokedTokens[] = $token;
    }

    public function listPrimaryCalendarEvents(string $accessToken, int $maxResults): array
    {
        $this->listEventsCallCount++;

        if ($this->listEventsShouldFail) {
            throw new \RuntimeException($this->listEventsFailureMessage);
        }

        return [];
    }

    // ── Stage 4B.1 — Calendar event creation/reconciliation fakes ──────────

    /**
     * Set to one of: null (succeed), 'transport' (no response — simulates
     * a timeout/connection reset), '5xx' (definitive Google server error),
     * '429' (rate limited), '403' (permissions), '404' (calendar not
     * found), '400' (rejected/malformed request), or 'lost_response' — the
     * hardest case: the event IS actually created (and becomes findable
     * via listPrimaryCalendarEventsByPrivateProperty()) but this call
     * itself throws a transport failure, simulating "Google processed the
     * write, we never saw the response."
     */
    public ?string $insertFailureMode = null;

    /** When true, a successful insert() returns an Event resource missing 'id' — a malformed provider response. */
    public bool $insertReturnsMalformedResponse = false;

    /** @var array<string, array{id: string, private_extended_properties: array<string,string>}> Simulated Google-side stored events, keyed by event id. */
    public array $insertedEvents = [];

    /** @var array<string, array<int, array{id: string}>>|null Preset lookup results keyed by correlation key — overrides organic insertedEvents matching when set for a given key. Lets a test simulate an ambiguous (multiple-match) reconciliation without two real inserts. */
    public array $presetLookupResults = [];

    public int $insertCallCount = 0;
    public int $lookupCallCount = 0;
    public ?string $lastInsertSendUpdates = null;
    public ?array $lastInsertEventBody = null;

    private int $nextEventIdSequence = 1;

    public function insertPrimaryCalendarEvent(string $accessToken, array $eventBody, string $sendUpdates): array
    {
        $this->insertCallCount++;
        $this->lastInsertSendUpdates = $sendUpdates;
        $this->lastInsertEventBody = $eventBody;
        $this->lastConferenceRequestId = $eventBody['request_conference'] ?? false ? $eventBody['correlation_key'] : null;

        $eventId = 'fake_event_' . $this->nextEventIdSequence++;
        $conference = $this->buildRawConference($eventBody);

        if ($this->insertFailureMode === 'lost_response') {
            // The write genuinely happens (findable later), but the
            // caller never sees a response at all.
            $this->insertedEvents[$eventId] = [
                'id' => $eventId,
                'private_extended_properties' => ['suresign_correlation_key' => $eventBody['correlation_key']],
                'conference' => $conference,
            ];
            throw new \GuzzleHttp\Exception\ConnectException(
                'Simulated connection reset (fake — response lost after Google processed the request).',
                new \GuzzleHttp\Psr7\Request('POST', 'https://www.googleapis.com/calendar/v3/calendars/primary/events'),
            );
        }

        match ($this->insertFailureMode) {
            null => null,
            'transport' => throw new \GuzzleHttp\Exception\ConnectException(
                'Simulated timeout (fake — no response received).',
                new \GuzzleHttp\Psr7\Request('POST', 'https://www.googleapis.com/calendar/v3/calendars/primary/events'),
            ),
            '5xx' => throw new \Google\Service\Exception('Simulated Google internal server error (fake).', 500),
            '429' => throw new \Google\Service\Exception('Simulated rate limit exceeded (fake).', 429),
            '403' => throw new \Google\Service\Exception('Simulated insufficient permissions (fake).', 403),
            '404' => throw new \Google\Service\Exception('Simulated calendar not found (fake).', 404),
            '400' => throw new \Google\Service\Exception('Simulated invalid request (fake).', 400),
            default => throw new \RuntimeException("Unknown fake insertFailureMode: {$this->insertFailureMode}"),
        };

        if ($this->insertReturnsMalformedResponse) {
            return ['created' => now()->toIso8601String()]; // no 'id'
        }

        $this->insertedEvents[$eventId] = [
            'id' => $eventId,
            'private_extended_properties' => ['suresign_correlation_key' => $eventBody['correlation_key']],
            'conference' => $conference,
        ];

        return ['id' => $eventId, 'created' => now()->toIso8601String(), 'conference' => $conference];
    }

    public function listPrimaryCalendarEventsByPrivateProperty(string $accessToken, string $key, string $value): array
    {
        $this->lookupCallCount++;

        if ($key === 'suresign_correlation_key' && isset($this->presetLookupResults[$value])) {
            return $this->presetLookupResults[$value];
        }

        return array_values(array_filter(
            array_map(
                fn (array $event) => ($event['private_extended_properties'][$key] ?? null) === $value
                    ? ['id' => $event['id'], 'conference' => $event['conference'] ?? ['status' => null, 'conference_id' => null, 'conference_type' => null, 'entry_points' => []]]
                    : null,
                $this->insertedEvents,
            ),
        ));
    }

    // ── Stage 4B.2 — Google Meet conference simulation (raw provider shape) ─

    /** null|'success'|'pending'|'failure' — Google's own ConferenceRequestStatus.statusCode; null = no conferenceData returned at all. */
    public ?string $conferenceStatus = 'success';
    public ?string $conferenceId = 'fake_conference_1';
    public ?string $conferenceType = 'hangoutsMeet';
    public string $conferenceVideoUri = 'https://meet.google.com/abc-defg-hij';

    /** Simulates Google returning more than one 'video' entry point — an ambiguous/malformed response GoogleCalendarProvider must never trust. */
    public bool $conferenceMultipleVideoEntryPoints = false;

    /** Simulates a returned join URI that fails the approved secure-host/scheme check (never surfaced as a customer-facing link). */
    public bool $conferenceUntrustedUrl = false;

    /** Records the requestId (== correlation_key) used on the last conference-requesting insert() call — null when no conference was requested. Lets a test assert stability across retries. */
    public ?string $lastConferenceRequestId = null;

    /**
     * @return array{status: ?string, conference_id: ?string, conference_type: ?string, entry_points: array<int, array{type: string, uri: string}>}
     */
    private function buildRawConference(array $eventBody): array
    {
        if (empty($eventBody['request_conference'])) {
            return ['status' => null, 'conference_id' => null, 'conference_type' => null, 'entry_points' => []];
        }

        $entryPoints = [];
        if ($this->conferenceStatus === 'success') {
            $uri = $this->conferenceUntrustedUrl ? 'https://not-google-meet.example.test/join' : $this->conferenceVideoUri;
            $entryPoints[] = ['type' => 'video', 'uri' => $uri];

            if ($this->conferenceMultipleVideoEntryPoints) {
                $entryPoints[] = ['type' => 'video', 'uri' => 'https://meet.google.com/zzz-zzzz-zzz'];
            }
        }

        return [
            'status'          => $this->conferenceStatus,
            'conference_id'   => $this->conferenceId,
            'conference_type' => $this->conferenceType,
            'entry_points'    => $entryPoints,
        ];
    }
}
