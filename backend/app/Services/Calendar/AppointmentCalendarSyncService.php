<?php

namespace App\Services\Calendar;

use App\Models\ActivityLog;
use App\Models\Appointment;
use App\Models\AppointmentExternalSync;
use App\Services\Google\GoogleConnectionService;
use App\Services\Google\GoogleIntegrationReadinessService;
use App\Support\Google\CalendarSyncFailureCategory;
use App\Support\Google\CalendarSyncFailureException;
use App\Support\Google\CalendarSyncState;
use App\Support\Google\GoogleConnectionHealth;
use App\Support\Google\MeetConferenceState;
use Illuminate\Support\Facades\DB;

/**
 * Stage 4B.1 — the sole orchestrator of Appointment → Google Calendar
 * synchronisation. Owns: eligibility checks, readiness mapping, claiming
 * (short row-locked transactions only — no Google call ever happens while
 * a lock is held), reconciliation-before-create when a prior outcome may
 * be uncertain, normalised failure handling, state persistence, and
 * Activity Log entries.
 *
 * **Exception discipline (approved correction 2/3)**: this class catches
 * ONLY App\Support\Google\CalendarSyncFailureException — the normalised
 * category CalendarProviderInterface implementations throw. It never
 * parses a raw provider/transport exception message. Any OTHER \Throwable
 * (an unclassified/unexpected failure — a bug, a database error, etc.) is
 * deliberately NOT caught here: it propagates to
 * App\Jobs\SyncAppointmentCalendarEventJob, which is the only place
 * Laravel's own queue-level retry applies. A classified failure always
 * completes this service's call normally (a persisted state, no
 * exception) — Laravel's queue retry must never fire merely because
 * next_retry_at isn't due yet or because a classified failure occurred.
 *
 * **attempt_count** increments exactly once per process() pass that
 * reaches a genuine provider call (the reconciliation lookup and/or
 * createEvent() — never per queue delivery, and never for a pass that
 * short-circuits earlier on eligibility/readiness). reconcileOnly()'s
 * admin-initiated lookup increments separately, only if it fails, since it
 * is itself a genuine provider operation outside the normal attempt()
 * pass.
 *
 * **Stage 4B.2 (Google Meet Conference Generation)**: Meet is requested as
 * part of the SAME `createEvent()` call this class already makes for
 * Calendar — never a second event, never a second provider call, never a
 * separate retry counter. `meeting_state` (App\Support\Google\MeetConferenceState)
 * is an INDEPENDENT fact from `state` (Calendar) — a `synced` Calendar
 * event may have `meeting_state` `pending`/`available`/`unavailable`/
 * `failed`/`manual_review` at the same time. `refreshPendingMeet()` is the
 * one additional entry point: a `synced`-Calendar/`pending`-Meet row is
 * NOT claimable by `process()` (SYNCED isn't in AUTO_CLAIMABLE) — this
 * method re-checks Meet status only, via the same correlation-key lookup,
 * and never touches Calendar `state` at all.
 */
class AppointmentCalendarSyncService
{
    public function __construct(
        private readonly CalendarProviderInterface $calendarProvider,
        private readonly GoogleIntegrationReadinessService $readinessService,
        private readonly GoogleConnectionService $connectionService,
        private readonly ConsultancyAppointmentCalendarEventPayloadFactory $payloadFactory,
    ) {
    }

    /**
     * Idempotently ensures a sync row exists for this Appointment and
     * dispatches the queue job. Called from
     * App\Services\Consultancy\ConsultancyPaymentConversionService::convert()
     * (the paid reservation/checkout path) and from
     * App\Services\Consultancy\ConsultationEnquiryService::book() (the free/
     * direct booking path) — both via DB::afterCommit(), never from inside
     * either method's own transaction, never while any scheduling lock is
     * held.
     */
    public function queueForAppointment(Appointment $appointment): AppointmentExternalSync
    {
        $sync = $this->getOrCreateSyncRow($appointment);

        \App\Jobs\SyncAppointmentCalendarEventJob::dispatch($sync->id)->onQueue('google-integrations');

        return $sync;
    }

    public function getOrCreateSyncRow(Appointment $appointment): AppointmentExternalSync
    {
        $existing = AppointmentExternalSync::where('appointment_id', $appointment->id)
            ->where('provider', 'google')
            ->where('external_resource_type', 'calendar_event')
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            $sync = AppointmentExternalSync::create([
                'appointment_id'   => $appointment->id,
                'provider'         => 'google',
                'external_resource_type' => 'calendar_event',
                'state'            => CalendarSyncState::PENDING,
                'correlation_key'  => ConsultancyAppointmentCalendarEventPayloadFactory::generateCorrelationKey(),
                'payload_version'  => ConsultancyAppointmentCalendarEventPayloadFactory::PAYLOAD_VERSION,
            ]);

            ActivityLog::record(
                'google.calendar_sync_queued',
                "Google Calendar synchronisation queued for appointment {$appointment->reference}.",
                null,
                $appointment,
                ['sync_id' => $sync->id],
            );

            return $sync;
        } catch (\Illuminate\Database\QueryException) {
            // A concurrent caller created the row first (unique constraint
            // race) — fetch it rather than erroring.
            return AppointmentExternalSync::where('appointment_id', $appointment->id)
                ->where('provider', 'google')
                ->where('external_resource_type', 'calendar_event')
                ->firstOrFail();
        }
    }

    /**
     * The main processing algorithm — called by
     * SyncAppointmentCalendarEventJob for automatic (queue/reconciliation)
     * work. Always completes normally for a classified outcome; only an
     * unclassified \Throwable escapes.
     */
    public function attempt(AppointmentExternalSync $sync): array
    {
        return $this->process($sync, CalendarSyncState::AUTO_CLAIMABLE);
    }

    /**
     * Admin-initiated retry — additionally claimable from FAILED/
     * MANUAL_REVIEW (never automatic).
     */
    public function retry(AppointmentExternalSync $sync): array
    {
        return $this->process($sync, CalendarSyncState::ADMIN_CLAIMABLE);
    }

    /**
     * Admin-initiated reconciliation-only check — looks up by correlation
     * key regardless of the row's current outcome_uncertain value, without
     * attempting a fresh create. Restores the prior state on a zero-match
     * result (a no-op check, not a state change).
     */
    public function reconcileOnly(AppointmentExternalSync $sync): array
    {
        $previousState = $sync->state;

        if (!$this->claim($sync, CalendarSyncState::ADMIN_CLAIMABLE)) {
            return $this->resultFor($sync);
        }

        try {
            $matches = $this->calendarProvider->findEventByCorrelationKey($sync->correlation_key);
        } catch (CalendarSyncFailureException $e) {
            $this->handleClassifiedFailure($sync, $e, alreadyCountedThisPass: false);

            return $this->resultFor($sync);
        }

        if (count($matches) === 1) {
            $this->markSynced($sync, $matches[0]['event_id'], reconciled: true);
            $this->applyConferenceResult($sync, $matches[0]['conference'] ?? []);
        } elseif (count($matches) > 1) {
            $this->markAmbiguous($sync);
        } else {
            // No match found — restore exactly what it was; this was a
            // read-only check, not a transition.
            $sync->update(['state' => $previousState, 'processing_started_at' => null]);
        }

        return $this->resultFor($sync);
    }

    private function process(AppointmentExternalSync $sync, array $claimableStates): array
    {
        $appointment = $sync->appointment;

        if (!$appointment || !$appointment->isEligibleForExternalSync()) {
            if (!$sync->isTerminal()) {
                $sync->update(['state' => CalendarSyncState::CANCELLED, 'processing_started_at' => null]);
            }

            return $this->resultFor($sync);
        }

        if (!$this->claim($sync, $claimableStates)) {
            return $this->resultFor($sync);
        }

        // Re-check eligibility immediately after claim, immediately before
        // any Google call — cancellation may have happened in the gap.
        $appointment = $appointment->fresh();
        if (!$appointment || !$appointment->isEligibleForExternalSync()) {
            $sync->update(['state' => CalendarSyncState::CANCELLED, 'processing_started_at' => null]);

            return $this->resultFor($sync);
        }

        $readiness = $this->readinessService->check();
        $decision = $this->mapReadiness($readiness['health_state']);

        if ($decision !== null) {
            $this->applyReadinessDecision($sync, $decision);

            return $this->resultFor($sync);
        }

        // A Meet conference is requested on every Calendar-ready pass,
        // regardless of GoogleIntegrationReadinessService::checkDetailed()'s
        // `meet_ready` — that signal is diagnostic/reporting-only (see its
        // own docblock and the approved Stage 4B.2 architecture decision).
        // Gating the request on it would be self-defeating: a persisted
        // Meet capability blocker would then never have a chance to clear,
        // since nothing would ever try again to discover it had resolved.
        // Always attempting lets the system self-heal the instant Google's
        // real capability changes.
        $requestConference = true;

        $payload = $this->payloadFactory->build($appointment, $sync->correlation_key, $requestConference);
        $sync->update(['last_attempted_at' => now(), 'payload_hash' => md5(json_encode($payload))]);

        // Incremented exactly ONCE per process() pass that reaches a real
        // provider call below (whichever call — reconciliation lookup or
        // create — ends up being made), never per queue delivery. A pass
        // that returns earlier (cancelled/readiness-blocked, above) never
        // reaches this line at all.
        $sync->update(['attempt_count' => $sync->attempt_count + 1]);

        try {
            if ($sync->outcome_uncertain) {
                $matches = $this->calendarProvider->findEventByCorrelationKey($sync->correlation_key);

                if (count($matches) === 1) {
                    $this->markSynced($sync, $matches[0]['event_id'], reconciled: true);
                    $this->applyConferenceResult($sync, $matches[0]['conference'] ?? []);

                    return $this->resultFor($sync);
                }

                if (count($matches) > 1) {
                    $this->markAmbiguous($sync);

                    return $this->resultFor($sync);
                }

                // Zero matches — safe to create, fall through.
            }

            // Set BEFORE the call — this is what makes a crash between
            // Google creating the event and this process recording the
            // outcome safely reconcilable next time.
            $sync->update(['outcome_uncertain' => true]);

            $result = $this->calendarProvider->createEvent($payload);

            $this->markSynced($sync, $result['event_id'], reconciled: false);
            $this->applyConferenceResult($sync, $result['conference'] ?? []);
        } catch (CalendarSyncFailureException $e) {
            $this->handleClassifiedFailure($sync, $e, alreadyCountedThisPass: true);
        }

        // Any other \Throwable propagates unmodified — see class docblock.

        return $this->resultFor($sync);
    }

    /**
     * @return string|null null = healthy enough to proceed; otherwise a
     *                      CalendarSyncState::DISCONNECTED/decision marker
     *                      handled by applyReadinessDecision().
     */
    private function mapReadiness(string $healthState): ?string
    {
        return match ($healthState) {
            GoogleConnectionHealth::NOT_CONNECTED, GoogleConnectionHealth::REFRESH_FAILED => CalendarSyncState::DISCONNECTED,
            GoogleConnectionHealth::PERMISSIONS_MISSING => CalendarSyncState::MANUAL_REVIEW,
            GoogleConnectionHealth::CALENDAR_UNAVAILABLE => CalendarSyncState::RETRY_PENDING,
            // TOKEN_EXPIRED / CONNECTED / HEALTHY — proceed; a token
            // refresh (if needed) self-heals inside createEvent() exactly
            // as it already does for testConnection().
            default => null,
        };
    }

    private function applyReadinessDecision(AppointmentExternalSync $sync, string $decision): void
    {
        if ($decision === CalendarSyncState::DISCONNECTED) {
            $sync->update([
                'state'            => CalendarSyncState::DISCONNECTED,
                'failure_category' => CalendarSyncFailureCategory::DISCONNECTED,
                'failure_message'  => 'Google is not currently connected.',
                'last_attempted_at' => now(),
            ]);

            return;
        }

        if ($decision === CalendarSyncState::MANUAL_REVIEW) {
            $sync->update([
                'state'            => CalendarSyncState::MANUAL_REVIEW,
                'failure_category' => CalendarSyncFailureCategory::PERMISSIONS_MISSING,
                'failure_message'  => 'The Google connection is missing a required permission.',
                'last_attempted_at' => now(),
            ]);
            ActivityLog::record(
                'google.calendar_sync_manual_review',
                "Google Calendar synchronisation for appointment {$sync->appointment->reference} requires manual review.",
                null,
                $sync->appointment,
                ['sync_id' => $sync->id, 'failure_category' => CalendarSyncFailureCategory::PERMISSIONS_MISSING],
            );

            return;
        }

        // RETRY_PENDING via readiness (calendar temporarily unavailable) —
        // this specific pass never calls createEvent(), so it does NOT
        // increment attempt_count (countsAsAttempt=false, preserving
        // "attempt_count = genuine provider operations only"). This still
        // converges rather than looping forever in practice:
        // GoogleConnectionHealth::CALENDAR_UNAVAILABLE is itself only ever
        // set after a REAL prior API failure (via testConnection() or a
        // previous live createEvent() attempt, both of which DO increment
        // this row's attempt_count through the normal failure path below)
        // — so the exhaustion check just below naturally uses whatever
        // genuine attempt count already accumulated, and still escalates
        // to FAILED once that budget is reached.
        $this->scheduleRecoverableRetry($sync, CalendarSyncFailureCategory::CALENDAR_TEMPORARILY_UNAVAILABLE, 'The connected Calendar is temporarily unavailable.', outcomeUncertain: false, countsAsAttempt: false);
    }

    /**
     * @param  bool  $alreadyCountedThisPass  true when the caller already
     *                                        incremented attempt_count for
     *                                        this pass before the call that
     *                                        threw (process()'s createEvent()
     *                                        path); false when no increment
     *                                        has happened yet this pass
     *                                        (reconcileOnly()'s admin-
     *                                        initiated lookup, which never
     *                                        pre-increments).
     */
    private function handleClassifiedFailure(AppointmentExternalSync $sync, CalendarSyncFailureException $e, bool $alreadyCountedThisPass): void
    {
        $category = $e->category();
        $outcomeUncertain = $e->isOutcomeUncertain();

        if ($category === CalendarSyncFailureCategory::DISCONNECTED) {
            $sync->update([
                'state'            => CalendarSyncState::DISCONNECTED,
                'failure_category' => $category,
                'failure_message'  => $e->safeMessage(),
                'outcome_uncertain' => false,
            ]);

            return;
        }

        if (in_array($category, CalendarSyncFailureCategory::CONFIGURATION, true)) {
            $sync->update([
                'state'            => CalendarSyncState::MANUAL_REVIEW,
                'failure_category' => $category,
                'failure_message'  => $e->safeMessage(),
                'outcome_uncertain' => $outcomeUncertain,
            ]);

            if ($category === CalendarSyncFailureCategory::AMBIGUOUS_RECONCILIATION) {
                $this->markAmbiguous($sync, alreadyUpdated: true);
            } else {
                ActivityLog::record(
                    'google.calendar_sync_manual_review',
                    "Google Calendar synchronisation for appointment {$sync->appointment->reference} requires manual review.",
                    null,
                    $sync->appointment,
                    ['sync_id' => $sync->id, 'failure_category' => $category],
                );
            }

            return;
        }

        $this->scheduleRecoverableRetry($sync, $category, $e->safeMessage(), $outcomeUncertain, countsAsAttempt: !$alreadyCountedThisPass);
    }

    /**
     * @param  bool  $countsAsAttempt  Whether this specific occurrence
     *                                 should count against the sync-row
     *                                 retry budget — a live provider
     *                                 failure during createEvent() already
     *                                 incremented attempt_count itself
     *                                 (before the call); a pre-call
     *                                 readiness-driven skip has not, so it
     *                                 increments here instead.
     */
    private function scheduleRecoverableRetry(AppointmentExternalSync $sync, string $category, string $safeMessage, bool $outcomeUncertain, bool $countsAsAttempt): void
    {
        $attemptCount = $countsAsAttempt ? $sync->attempt_count + 1 : $sync->attempt_count;

        if ($attemptCount >= CalendarSyncState::MAX_RECOVERABLE_ATTEMPTS) {
            $sync->update([
                'state'            => CalendarSyncState::FAILED,
                'failure_category' => $category,
                'failure_message'  => $safeMessage,
                'outcome_uncertain' => $outcomeUncertain,
                'attempt_count'    => $attemptCount,
            ]);
            ActivityLog::record(
                'google.calendar_sync_failed',
                "Google Calendar synchronisation for appointment {$sync->appointment->reference} failed after {$attemptCount} attempt(s).",
                null,
                $sync->appointment,
                ['sync_id' => $sync->id, 'failure_category' => $category],
            );

            return;
        }

        $backoffIndex = max($attemptCount - 1, 0);
        $backoffMinutes = CalendarSyncState::BACKOFF_MINUTES[$backoffIndex] ?? end(CalendarSyncState::BACKOFF_MINUTES);

        $sync->update([
            'state'            => CalendarSyncState::RETRY_PENDING,
            'failure_category' => $category,
            'failure_message'  => $safeMessage,
            'outcome_uncertain' => $outcomeUncertain,
            'attempt_count'    => $attemptCount,
            'next_retry_at'    => now()->addMinutes($backoffMinutes),
        ]);
    }

    private function markSynced(AppointmentExternalSync $sync, string $eventId, bool $reconciled): void
    {
        $wasStuck = in_array($sync->state, [CalendarSyncState::FAILED, CalendarSyncState::MANUAL_REVIEW, CalendarSyncState::DISCONNECTED], true);
        $connection = $this->connectionService->current();

        $sync->update([
            'state'               => CalendarSyncState::SYNCED,
            'provider_event_id'   => $eventId,
            'google_connection_id' => $connection?->id,
            'outcome_uncertain'   => false,
            'last_success_at'     => now(),
            'failure_category'    => null,
            'failure_message'     => null,
            'processing_started_at' => null,
        ]);

        $appointment = $sync->appointment;
        $event = $wasStuck ? 'google.calendar_sync_recovered' : ($reconciled ? 'google.calendar_sync_reconciled' : 'google.calendar_event_created');
        $description = match ($event) {
            'google.calendar_sync_recovered' => "Google Calendar synchronisation for appointment {$appointment->reference} recovered.",
            'google.calendar_sync_reconciled' => "Google Calendar synchronisation for appointment {$appointment->reference} reconciled to an existing event.",
            default => "Google Calendar event created for appointment {$appointment->reference}.",
        };

        ActivityLog::record($event, $description, null, $appointment, ['sync_id' => $sync->id, 'event_id' => $eventId]);
    }

    private function markAmbiguous(AppointmentExternalSync $sync, bool $alreadyUpdated = false): void
    {
        if (!$alreadyUpdated) {
            $sync->update([
                'state'            => CalendarSyncState::MANUAL_REVIEW,
                'failure_category' => CalendarSyncFailureCategory::AMBIGUOUS_RECONCILIATION,
                'failure_message'  => 'Multiple matching Calendar events were found.',
                'outcome_uncertain' => true,
            ]);
        }

        ActivityLog::record(
            'google.calendar_sync_manual_review',
            "Google Calendar synchronisation for appointment {$sync->appointment->reference} found multiple matching events and requires manual review.",
            null,
            $sync->appointment,
            ['sync_id' => $sync->id, 'failure_category' => CalendarSyncFailureCategory::AMBIGUOUS_RECONCILIATION],
        );
    }

    /**
     * Stage 4B.2 — the single place a normalised `conference` result
     * (from CalendarProviderInterface::createEvent()/findEventByCorrelationKey(),
     * always already provider-boundary-normalised — see
     * GoogleCalendarProvider::normalizeConference()) is turned into
     * `meeting_state`/conference fields. Called only when a Calendar event
     * was just created or adopted THIS pass — never independently.
     *
     * @param  array  $conference  {status: ?string, conference_id: ?string, conference_type: ?string, join_url: ?string}
     */
    private function applyConferenceResult(AppointmentExternalSync $sync, array $conference): void
    {
        $previousMeetingState = $sync->meeting_state;
        $status = $conference['status'] ?? null;
        $joinUrl = $conference['join_url'] ?? null;

        [$newState, $failureCategory] = match (true) {
            $status === 'success' && $joinUrl !== null => [MeetConferenceState::AVAILABLE, null],
            // Google claimed success but no trustworthy single video entry
            // point was found — never trusted as available.
            $status === 'success' => [MeetConferenceState::MANUAL_REVIEW, CalendarSyncFailureCategory::MALFORMED_CONFERENCE_RESPONSE],
            $status === 'pending' => [MeetConferenceState::PENDING, null],
            $status === 'failure' => [MeetConferenceState::FAILED, CalendarSyncFailureCategory::CONFERENCE_SOLUTION_UNAVAILABLE],
            // No conferenceData at all despite requesting one — Google
            // silently did not produce a conference for this account.
            default => [MeetConferenceState::UNAVAILABLE, CalendarSyncFailureCategory::MEET_NOT_SUPPORTED],
        };

        $sync->update([
            'meeting_state'            => $newState,
            'provider_conference_id'   => $conference['conference_id'] ?? $sync->provider_conference_id,
            'provider_conference_type' => $conference['conference_type'] ?? $sync->provider_conference_type,
            // Only ever populated while AVAILABLE — cleared the instant
            // Meet stops being available, so a stale link can never be
            // read from a row whose state has since regressed.
            'meeting_join_url'         => $newState === MeetConferenceState::AVAILABLE ? $joinUrl : null,
            'meeting_created_at'       => $newState === MeetConferenceState::AVAILABLE ? ($sync->meeting_created_at ?? now()) : $sync->meeting_created_at,
            'meeting_failure_category' => $failureCategory,
        ]);

        $this->logMeetTransition($sync, $previousMeetingState, $newState);

        // Communications Upgrade Batch 1 — the meeting-link-ready customer
        // email fires ONLY on a genuine transition into AVAILABLE (never on
        // an unchanged reconciliation re-observation of an already-available
        // state), covering immediate availability, later reconciliation, and
        // recovery after an uncertain provider response alike, since all
        // three paths call this same method. Idempotency itself is owned by
        // ConsultationCommunicationService's DB unique constraint, not this
        // state-change check — this check is an optimisation to avoid
        // dispatching a job that would immediately no-op, not the actual
        // safety guarantee. Not dispatched inside any DB transaction/lock —
        // this call happens after the $sync->update() above has already
        // committed (Eloquent's own auto-commit per statement; no explicit
        // transaction wraps this method).
        if ($newState === MeetConferenceState::AVAILABLE && $previousMeetingState !== MeetConferenceState::AVAILABLE) {
            \App\Jobs\SendConsultationCommunicationJob::dispatch($sync->appointment_id, 'meeting_link_ready')->afterCommit();
        }
    }

    /**
     * Avoids duplicate Activity Log noise — only a genuine STATE CHANGE is
     * logged, never the same outcome repeated on every unchanged
     * reconciliation pass.
     */
    private function logMeetTransition(AppointmentExternalSync $sync, string $previousState, string $newState): void
    {
        if ($previousState === $newState) {
            return;
        }

        $wasStuck = in_array($previousState, [
            MeetConferenceState::FAILED, MeetConferenceState::MANUAL_REVIEW, MeetConferenceState::UNAVAILABLE,
        ], true);

        $event = match (true) {
            $previousState === MeetConferenceState::NOT_REQUESTED && $newState === MeetConferenceState::PENDING => 'google.meet_requested',
            $newState === MeetConferenceState::AVAILABLE && $wasStuck => 'google.meet_recovered',
            $newState === MeetConferenceState::AVAILABLE => 'google.meet_available',
            $newState === MeetConferenceState::PENDING => 'google.meet_pending',
            $newState === MeetConferenceState::FAILED => 'google.meet_failed',
            $newState === MeetConferenceState::MANUAL_REVIEW => 'google.meet_manual_review',
            default => null,
        };

        if ($event === null) {
            return;
        }

        $appointment = $sync->appointment;

        ActivityLog::record(
            $event,
            "Google Meet for appointment {$appointment->reference}: {$newState}.",
            null,
            $appointment,
            [
                'sync_id' => $sync->id,
                'meeting_state' => $newState,
                'previous_meeting_state' => $previousState,
                // Presence only — never the URL itself (see class docblock's
                // security discipline, mirrored here for Meet).
                'has_join_url' => $sync->meeting_join_url !== null,
            ],
        );
    }

    /**
     * Stage 4B.2 — recovers a `synced`-Calendar/`pending`-Meet row.
     * Deliberately NOT routed through process()/claim() — SYNCED is not an
     * AUTO_CLAIMABLE Calendar state, and this method never touches
     * Calendar `state`/`provider_event_id`/`outcome_uncertain` at all,
     * only Meet fields. Idempotent by nature (re-reads Google's own
     * conference status and re-applies it) — a concurrent duplicate call
     * is a harmless race, not a correctness risk, exactly like every other
     * "dispatch is not itself a mutation" case in this codebase.
     */
    public function refreshPendingMeet(AppointmentExternalSync $sync): array
    {
        $sync->refresh();

        if ($sync->state !== CalendarSyncState::SYNCED || $sync->meeting_state !== MeetConferenceState::PENDING) {
            return $this->resultFor($sync);
        }

        $appointment = $sync->appointment;
        if (!$appointment || !$appointment->isEligibleForExternalSync()) {
            // No reason to keep polling Google for a cancelled booking's
            // Meet status — respects provider quotas, per the approved
            // scope. The Calendar event itself is left exactly as-is.
            return $this->resultFor($sync);
        }

        try {
            $matches = $this->calendarProvider->findEventByCorrelationKey($sync->correlation_key);
        } catch (CalendarSyncFailureException) {
            // A transient failure here must never disturb the already-
            // synced Calendar state — only a future pass gets another
            // chance at the Meet status specifically.
            return $this->resultFor($sync);
        }

        $ourEvent = null;
        foreach ($matches as $match) {
            if ($match['event_id'] === $sync->provider_event_id) {
                $ourEvent = $match;
                break;
            }
        }

        if ($ourEvent === null) {
            // Could not confirm which (if any) result is our own event —
            // leave Meet as pending and try again on a later tick, rather
            // than guess.
            return $this->resultFor($sync);
        }

        $this->applyConferenceResult($sync, $ourEvent['conference'] ?? []);

        return $this->resultFor($sync);
    }

    /**
     * Short, row-locked claim transaction. No Google call happens inside
     * it. Reclaims an abandoned 'processing' row (lease expired) even if
     * 'processing' isn't itself in $allowedStates.
     */
    private function claim(AppointmentExternalSync $sync, array $allowedStates): bool
    {
        $claimed = DB::transaction(function () use ($sync, $allowedStates) {
            $locked = AppointmentExternalSync::where('id', $sync->id)->lockForUpdate()->first();
            if (!$locked) {
                return false;
            }

            if ($locked->state === CalendarSyncState::PROCESSING) {
                $leaseExpired = !$locked->processing_started_at
                    || $locked->processing_started_at->lt(now()->subMinutes(CalendarSyncState::PROCESSING_LEASE_MINUTES));

                if (!$leaseExpired) {
                    return false; // genuinely active elsewhere
                }
                // Abandoned — fall through and reclaim.
            } elseif (!in_array($locked->state, $allowedStates, true)) {
                return false;
            }

            $locked->update(['state' => CalendarSyncState::PROCESSING, 'processing_started_at' => now()]);

            return true;
        });

        if ($claimed) {
            $sync->refresh();
        }

        return $claimed;
    }

    private function resultFor(AppointmentExternalSync $sync): array
    {
        $sync->refresh();

        return [
            'sync_id'          => $sync->id,
            'state'            => $sync->state,
            'meeting_state'    => $sync->meeting_state,
            'outcome_uncertain' => $sync->outcome_uncertain,
            'failure_category' => $sync->failure_category,
        ];
    }
}
