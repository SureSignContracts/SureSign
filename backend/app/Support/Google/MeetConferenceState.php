<?php

namespace App\Support\Google;

/**
 * Stage 4B.2 — the independent Meet-conference lifecycle for
 * App\Models\AppointmentExternalSync. Deliberately a SEPARATE dimension
 * from App\Support\Google\CalendarSyncState: a Calendar event can be
 * `synced` while its Meet conference is `pending`, `unavailable`, or
 * `failed` — Calendar truth and Meet truth must never be collapsed into
 * one field. See App\Services\Calendar\AppointmentCalendarSyncService for
 * the full transition rules.
 *
 * - NOT_REQUESTED: Meet was never requested — the Calendar event doesn't
 *   exist yet, or the Appointment/connection wasn't eligible at the time
 *   of the create call.
 * - PENDING: Meet was requested during Calendar event creation, but
 *   Google's own conference-creation status was not yet `success` (Google
 *   may return a Calendar event immediately while conference generation
 *   is still asynchronous — see `ConferenceRequestStatus::statusCode`).
 * - AVAILABLE: a valid, provider-normalised, secure joining URI is known.
 *   The only state in which the customer-facing surface ever shows a
 *   link.
 * - UNAVAILABLE: the Calendar event exists, but the connected account/
 *   integration cannot supply Meet for it (e.g. Meet unsupported for this
 *   account) — not necessarily a transient condition, but not a hard
 *   provider error either.
 * - FAILED: a definitive Meet-specific provider failure occurred
 *   (permission denied for conference creation, etc.) — retryable/
 *   reviewable via the same Calendar sync row.
 * - MANUAL_REVIEW: the provider's conference data was contradictory,
 *   duplicated, or malformed — unsafe to resolve automatically.
 */
final class MeetConferenceState
{
    public const NOT_REQUESTED = 'not_requested';
    public const PENDING = 'pending';
    public const AVAILABLE = 'available';
    public const UNAVAILABLE = 'unavailable';
    public const FAILED = 'failed';
    public const MANUAL_REVIEW = 'manual_review';

    public const ALL = [
        self::NOT_REQUESTED, self::PENDING, self::AVAILABLE,
        self::UNAVAILABLE, self::FAILED, self::MANUAL_REVIEW,
    ];
}
