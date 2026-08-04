<?php

namespace App\Support\Google;

/**
 * Stage 4B.1 — the explicit synchronisation lifecycle for
 * App\Models\AppointmentExternalSync. Deliberately never reduced to a
 * boolean. See App\Services\Calendar\AppointmentCalendarSyncService for
 * the full transition table.
 *
 * - PENDING: created, not yet claimed for processing.
 * - PROCESSING: claimed by a worker; a Google call may be in flight.
 * - SYNCED: a Google Calendar event is confirmed to exist for this
 *   Appointment — an accurate statement about EXTERNAL reality, which
 *   remains true even if the local Appointment is later cancelled (see
 *   AppointmentCalendarSyncService's cancellation-after-reconciliation
 *   handling).
 * - RETRY_PENDING: a recoverable provider failure occurred; automatic
 *   retry is still within budget.
 * - FAILED: a recoverable-category failure whose retry budget is
 *   exhausted — terminal until an explicit Admin retry.
 * - MANUAL_REVIEW: a genuinely ambiguous condition (multiple correlation
 *   matches) or a configuration failure requiring investigation — never
 *   auto-retried.
 * - DISCONNECTED: blocked purely because Google integration itself is not
 *   connected/healthy right now — auto-recoverable the moment readiness
 *   improves, distinguished from MANUAL_REVIEW because no ambiguous data
 *   problem exists here.
 * - CANCELLED: the Appointment became ineligible
 *   (Appointment::isEligibleForExternalSync() === false) before any
 *   Google event was confirmed to exist. Never applied once state is
 *   SYNCED — see point 5 of the approved corrections.
 */
final class CalendarSyncState
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const SYNCED = 'synced';
    public const RETRY_PENDING = 'retry_pending';
    public const FAILED = 'failed';
    public const MANUAL_REVIEW = 'manual_review';
    public const DISCONNECTED = 'disconnected';
    public const CANCELLED = 'cancelled';

    public const ALL = [
        self::PENDING, self::PROCESSING, self::SYNCED, self::RETRY_PENDING,
        self::FAILED, self::MANUAL_REVIEW, self::DISCONNECTED, self::CANCELLED,
    ];

    /** States an automatic (queue/reconciliation) claim may pick up. */
    public const AUTO_CLAIMABLE = [
        self::PENDING, self::RETRY_PENDING, self::DISCONNECTED,
    ];

    /** States an explicit Admin retry/reconcile action may additionally claim. */
    public const ADMIN_CLAIMABLE = [
        self::PENDING, self::RETRY_PENDING, self::DISCONNECTED,
        self::FAILED, self::MANUAL_REVIEW,
    ];

    /** Sync-row-level retry budget for RETRY_PENDING before moving to FAILED. */
    public const MAX_RECOVERABLE_ATTEMPTS = 4;

    /** Minutes-based backoff sequence for RETRY_PENDING, indexed by attempt number (1-based). */
    public const BACKOFF_MINUTES = [5, 15, 60, 240];

    /** A claimed 'processing' row older than this is considered abandoned (crashed worker). */
    public const PROCESSING_LEASE_MINUTES = 15;
}
