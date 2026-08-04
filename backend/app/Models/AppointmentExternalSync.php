<?php

namespace App\Models;

use App\Support\Google\CalendarSyncState;
use App\Support\Google\MeetConferenceState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stage 4B.1 — the provider-neutral record of one Appointment's external-
 * representation lifecycle (today: exactly one Google Calendar event).
 * Appointment remains the sole source of truth for booking state; this
 * model owns only synchronisation state — see
 * App\Support\Google\CalendarSyncState for the full lifecycle and
 * App\Services\Calendar\AppointmentCalendarSyncService for the
 * orchestration that mutates it. Never mutated directly by a controller.
 *
 * Stage 4B.2 (Google Meet Conference Generation) extends this SAME row
 * with independent Meet fields (`meeting_state` etc., see
 * App\Support\Google\MeetConferenceState) rather than a second sync
 * table/model — Meet rides on the same Calendar event this row already
 * tracks. `state`/`meeting_state` are deliberately separate columns: a
 * `synced` Calendar event and a `pending`/`unavailable` Meet conference
 * are both simultaneously true facts.
 */
class AppointmentExternalSync extends Model
{
    protected $fillable = [
        'appointment_id', 'google_connection_id',
        'provider', 'external_resource_type', 'state',
        'provider_event_id', 'correlation_key',
        'payload_version', 'payload_hash',
        'attempt_count',
        'processing_started_at', 'last_attempted_at', 'last_success_at', 'next_retry_at',
        'failure_category', 'failure_message',
        'outcome_uncertain',
        'meeting_state', 'provider_conference_id', 'provider_conference_type',
        'meeting_join_url', 'meeting_created_at', 'meeting_failure_category',
    ];

    protected $casts = [
        'attempt_count'          => 'integer',
        'processing_started_at'  => 'datetime',
        'last_attempted_at'      => 'datetime',
        'last_success_at'        => 'datetime',
        'next_retry_at'          => 'datetime',
        'outcome_uncertain'      => 'boolean',
        'meeting_created_at'     => 'datetime',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function googleConnection(): BelongsTo
    {
        return $this->belongsTo(GoogleConnection::class);
    }

    public function isSynced(): bool
    {
        return $this->state === CalendarSyncState::SYNCED;
    }

    public function isTerminal(): bool
    {
        return in_array($this->state, [CalendarSyncState::SYNCED, CalendarSyncState::CANCELLED], true);
    }

    /**
     * The single authoritative check for whether a customer may be shown
     * a joining link — true only when the Calendar event is synced AND a
     * provider-normalised, secure Meet URL is on record. Never true from
     * `meeting_join_url` presence alone without also checking
     * `meeting_state`, since the column is cleared whenever `meeting_state`
     * moves away from `available` (see AppointmentCalendarSyncService).
     */
    public function isMeetingJoinable(): bool
    {
        return $this->isSynced()
            && $this->meeting_state === MeetConferenceState::AVAILABLE
            && !empty($this->meeting_join_url);
    }
}
