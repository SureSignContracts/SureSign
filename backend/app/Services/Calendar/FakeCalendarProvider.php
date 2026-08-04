<?php

namespace App\Services\Calendar;

use App\Support\Google\CalendarSyncFailureCategory;
use App\Support\Google\CalendarSyncFailureException;

/**
 * In-memory fake used by the automated test suite and bound whenever
 * app()->environment('testing') is true (see GoogleServiceProvider) — no
 * automated test may ever construct a real \Google\Client. Mirrors
 * App\Services\Billing\FakeBillingProvider's exact convention:
 * deterministic, no network calls, no wall-clock reliance beyond what a
 * caller passes in — tests drive behaviour by setting the public
 * properties directly rather than this class inventing its own state
 * machine.
 */
class FakeCalendarProvider implements CalendarProviderInterface, MeetingProviderInterface
{
    public bool $connected = false;
    public bool $healthy = true;
    public bool $tokenValid = true;
    public bool $calendarAccessible = true;
    public bool $meetCapable = true;
    public ?string $error = null;
    public ?int $latencyMs = 12;

    /** @var int Number of testConnection() calls made — lets a test assert no duplicate/unnecessary calls occurred. */
    public int $testConnectionCallCount = 0;

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function supportsMeetGeneration(): bool
    {
        return $this->connected && $this->meetCapable;
    }

    public function testConnection(): array
    {
        $this->testConnectionCallCount++;

        if (!$this->connected) {
            return [
                'healthy' => false, 'token_valid' => false, 'calendar_accessible' => false,
                'latency_ms' => null, 'checked_at' => now()->toIso8601String(), 'error' => 'Not connected.',
            ];
        }

        return [
            'healthy'             => $this->healthy,
            'token_valid'         => $this->tokenValid,
            'calendar_accessible' => $this->calendarAccessible,
            'latency_ms'          => $this->healthy ? $this->latencyMs : null,
            'checked_at'          => now()->toIso8601String(),
            'error'               => $this->error,
        ];
    }

    // ── Stage 4B.1 ──────────────────────────────────────────────────────────

    /** Set to a CalendarSyncFailureCategory::* value to make createEvent() throw a classified failure; null = succeed. */
    public ?string $createEventFailureCategory = null;

    /** Set to make createEvent()/findEventByCorrelationKey() throw a genuinely UNCLASSIFIED exception instead (simulates an infrastructure-level bug). */
    public bool $throwUnclassifiedException = false;

    /** @var array<int, array{event_id: string}> Preset findEventByCorrelationKey() results, keyed by correlation key. */
    public array $correlationLookupResults = [];

    public int $createEventCallCount = 0;
    public int $findEventCallCount = 0;
    public ?array $lastCreateEventPayload = null;

    private int $nextFakeEventIdSequence = 1;

    public function createEvent(array $payload): array
    {
        $this->createEventCallCount++;
        $this->lastCreateEventPayload = $payload;
        $this->lastRequestConference = $payload['request_conference'] ?? false;
        $this->lastConferenceRequestId = $payload['correlation_key'];

        if ($this->throwUnclassifiedException) {
            throw new \RuntimeException('Simulated unclassified/unexpected failure (fake).');
        }

        if ($this->createEventFailureCategory !== null) {
            throw new CalendarSyncFailureException($this->createEventFailureCategory, "Simulated {$this->createEventFailureCategory} failure (fake).");
        }

        return [
            'event_id'   => 'fake_event_' . $this->nextFakeEventIdSequence++,
            'created_at' => now()->toIso8601String(),
            'conference' => ($payload['request_conference'] ?? false) ? $this->currentConference() : $this->noConference(),
        ];
    }

    public function findEventByCorrelationKey(string $correlationKey): array
    {
        $this->findEventCallCount++;

        if ($this->throwUnclassifiedException) {
            throw new \RuntimeException('Simulated unclassified/unexpected failure (fake).');
        }

        return $this->correlationLookupResults[$correlationKey] ?? [];
    }

    // ── Stage 4B.2 — Google Meet conference simulation ──────────────────────

    /** 'success'|'pending'|'failure'|null (null = Google returned no conferenceData at all — simulates Meet-not-supported). */
    public ?string $conferenceStatus = 'success';
    public ?string $conferenceId = 'fake_conference_1';
    public ?string $conferenceType = 'hangoutsMeet';
    public string $conferenceJoinUrl = 'https://meet.google.com/abc-defg-hij';

    /** Simulates Google claiming success with no usable/trustworthy entry point — join_url is null despite status='success'. */
    public bool $conferenceMalformed = false;

    /** Records whether the last createEvent() call actually requested a conference — lets a test assert this without inspecting the payload directly. */
    public ?bool $lastRequestConference = null;

    /** Records the requestId (== correlation_key) used on the last createEvent() call — a test asserts this stays IDENTICAL across retries. */
    public ?string $lastConferenceRequestId = null;

    /**
     * @return array{status: ?string, conference_id: ?string, conference_type: ?string, join_url: ?string}
     */
    private function currentConference(): array
    {
        if ($this->conferenceStatus === null) {
            return $this->noConference();
        }

        return [
            'status'          => $this->conferenceStatus,
            'conference_id'   => $this->conferenceId,
            'conference_type' => $this->conferenceType,
            // Only a 'success' status ever carries a join_url — mirrors
            // GoogleCalendarProvider::normalizeConference()'s own rule that
            // a claimed-success-with-no-URL is never trusted as available.
            'join_url'        => ($this->conferenceStatus === 'success' && !$this->conferenceMalformed) ? $this->conferenceJoinUrl : null,
        ];
    }

    /**
     * @return array{status: null, conference_id: null, conference_type: null, join_url: null}
     */
    private function noConference(): array
    {
        return ['status' => null, 'conference_id' => null, 'conference_type' => null, 'join_url' => null];
    }
}
