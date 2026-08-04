<?php

namespace App\Support\Consultancy;

use App\Models\Appointment;
use App\Support\Google\CalendarSyncState;
use App\Support\Google\MeetConferenceState;

/**
 * Stage 4B.2 — the single, narrowly-scoped customer-facing surface for a
 * Consultancy Appointment's Google Meet joining link. Deliberately a
 * SEPARATE presenter from App\Support\Consultancy\ConsultationPresenter
 * (never merged into `customerFacing()`, which both `index()` and `show()`
 * share on ConsultationController — merging this in would leak the link
 * into the customer's LIST endpoint, which is explicitly forbidden). Only
 * ConsultationController::show() calls this, appending its result under a
 * `meeting` key.
 *
 * Exposes exactly one of four customer-safe statuses — never a Google
 * internal, provider ID, correlation key, or raw failure detail:
 *
 * - `available`   — a valid, provider-normalised, secure join URL is on
 *                   record. The ONLY status carrying a non-null `join_url`.
 * - `pending`     — the Calendar event exists and Meet is being prepared
 *                   or is still resolving.
 * - `temporarily_unavailable` — the Calendar event itself doesn't exist
 *                   yet (still queued/retrying/disconnected) — a longer,
 *                   less certain wait than `pending`.
 * - `unavailable` — a definitive Meet failure or a condition requiring
 *                   Admin review. Never distinguishes which, to the
 *                   customer.
 *
 * A cancelled Appointment always reports `unavailable` here regardless of
 * the sync row's own true Meet state — Admin diagnostics show the real,
 * independent facts (see App\Support\Google\CalendarSyncPresenter); a
 * customer is never shown a "Join" affordance for a cancelled
 * consultation.
 */
class ConsultationMeetingPresenter
{
    /**
     * @return array{status: string, join_url: ?string}
     */
    public static function customerFacing(Appointment $appointment): array
    {
        if (!$appointment->isEligibleForExternalSync()) {
            return ['status' => 'unavailable', 'join_url' => null];
        }

        $sync = $appointment->externalSync;

        if (!$sync || $sync->state !== CalendarSyncState::SYNCED) {
            return ['status' => 'temporarily_unavailable', 'join_url' => null];
        }

        if ($sync->isMeetingJoinable()) {
            return ['status' => 'available', 'join_url' => $sync->meeting_join_url];
        }

        if (in_array($sync->meeting_state, [MeetConferenceState::NOT_REQUESTED, MeetConferenceState::PENDING], true)) {
            return ['status' => 'pending', 'join_url' => null];
        }

        // UNAVAILABLE / FAILED / MANUAL_REVIEW — never distinguished for the customer.
        return ['status' => 'unavailable', 'join_url' => null];
    }
}
