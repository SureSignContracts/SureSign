<?php

namespace App\Services\Google;

use App\Models\AppointmentExternalSync;
use App\Services\Calendar\CalendarProviderInterface;
use App\Services\Calendar\MeetingProviderInterface;
use App\Support\Google\CalendarSyncFailureCategory;
use App\Support\Google\GoogleConnectionHealth;
use App\Support\Google\MeetConferenceState;

/**
 * Google Integration Foundation, Stage 4A — the single authoritative
 * answer to "may downstream Google automation execute right now." Future
 * Consultancy (Stage 4B) automation must depend on this service rather
 * than checking configuration/connection state directly — mirrors
 * App\Services\Consultancy\ConsultancyBookingReadinessService's identical
 * role for Consultancy's own configuration readiness.
 *
 * Deliberately read-only and cheap — reads GoogleHealthService's own
 * cached-state computation, never makes a live Google API call itself.
 *
 * Stage 4B.2 note (important, do not re-litigate): `check()` below is
 * UNCHANGED and remains exactly what
 * App\Services\Calendar\AppointmentCalendarSyncService reads (via
 * `health_state`, never the aggregate `ready` field — see that class's
 * own Stage 4B.1 docblock). `checkDetailed()` is a pure ADDITION for
 * Admin diagnostics/operational monitoring — it is NOT a Checkout gate.
 * `App\Services\Consultancy\ConsultancyBookingReadinessService::checkoutAvailability()`
 * deliberately does not call this service at all, by explicit product
 * decision: external-provider availability must not interrupt payment
 * acceptance or valid Consultancy booking creation. See
 * internal-docs/super-admin/google-integration.md's Stage 4B.2 section
 * for the full architecture decision record.
 */
class GoogleIntegrationReadinessService
{
    public function __construct(
        private readonly GoogleHealthService $healthService,
        private readonly CalendarProviderInterface $calendarProvider,
        private readonly MeetingProviderInterface $meetingProvider,
        private readonly GoogleConnectionService $connectionService,
    ) {
    }

    /**
     * @return array{connected: bool, healthy: bool, health_state: string, meet_available: bool, ready: bool}
     */
    public function check(): array
    {
        $health = $this->healthService->currentHealth();
        $healthy = $health['state'] === GoogleConnectionHealth::HEALTHY;
        $meetAvailable = $this->meetingProvider->supportsMeetGeneration();

        return [
            'connected'      => $this->calendarProvider->isConnected(),
            'healthy'        => $healthy,
            'health_state'   => $health['state'],
            'meet_available' => $meetAvailable,
            'ready'          => $healthy && $meetAvailable,
        ];
    }

    /**
     * Stage 4B.2 — separate, machine-readable Calendar/Meet capability
     * results for Super Admin/Admin diagnostics, operational monitoring,
     * and pre-launch readiness review. Honest about a real constraint:
     * Google exposes no independent pre-flight "can this account create
     * Meet" endpoint — `meet_ready` is therefore derived from the SAME
     * connection health as `calendar_ready`, MINUS any known persisted
     * Meet-specific capability blocker (a real prior failure recorded on
     * an AppointmentExternalSync row for the current connection — e.g.
     * `meet_not_supported`/`conference_creation_forbidden` — that no later
     * successful Meet result has since superseded). This is never a
     * manufactured live capability check.
     *
     * @return array{calendar_ready: bool, meet_ready: bool, google_overall_ready: bool, blockers: array<int, string>, warnings: array<int, string>}
     */
    public function checkDetailed(): array
    {
        $health = $this->healthService->currentHealth();
        $calendarReady = $health['state'] === GoogleConnectionHealth::HEALTHY;

        $blockers = [];
        if (!$calendarReady) {
            $blockers[] = "calendar_{$health['state']}";
        }

        $meetBlocker = $this->persistedMeetCapabilityBlocker();
        if ($meetBlocker !== null) {
            $blockers[] = $meetBlocker;
        }

        $meetReady = $calendarReady && $meetBlocker === null;

        return [
            'calendar_ready'       => $calendarReady,
            'meet_ready'           => $meetReady,
            'google_overall_ready' => $calendarReady && $meetReady,
            'blockers'             => $blockers,
            'warnings'             => [],
        ];
    }

    /**
     * The most recent AppointmentExternalSync row for the CURRENT
     * connection whose `meeting_state` is either a persistent-blocker
     * category or `available` — whichever is chronologically last wins.
     * `available` always clears a prior blocker (proof the account can in
     * fact produce Meet); a persistent-blocker category with nothing
     * later still blocks. Rows with no determinate Meet outcome yet
     * (`not_requested`/`pending`) are not evidence either way and are
     * ignored.
     */
    private function persistedMeetCapabilityBlocker(): ?string
    {
        $connection = $this->connectionService->current();
        if (!$connection) {
            return null;
        }

        $persistentBlockerCategories = [
            CalendarSyncFailureCategory::MEET_NOT_SUPPORTED,
            CalendarSyncFailureCategory::CONFERENCE_CREATION_FORBIDDEN,
        ];

        $latest = AppointmentExternalSync::where('google_connection_id', $connection->id)
            ->where(function ($query) use ($persistentBlockerCategories) {
                $query->where('meeting_state', MeetConferenceState::AVAILABLE)
                    ->orWhereIn('meeting_failure_category', $persistentBlockerCategories);
            })
            // `updated_at` alone is not a safe sole tiebreaker — MySQL
            // `timestamp` columns default to whole-second precision, so
            // two rows updated within the same test/request can tie.
            // `id` breaks the tie deterministically in insertion order.
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if (!$latest || $latest->meeting_state === MeetConferenceState::AVAILABLE) {
            return null;
        }

        return $latest->meeting_failure_category;
    }
}
