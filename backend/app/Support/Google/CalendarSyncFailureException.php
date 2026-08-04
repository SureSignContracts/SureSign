<?php

namespace App\Support\Google;

/**
 * Stage 4B.1 — the ONE exception type carrying a classified Calendar
 * sync failure from App\Services\Calendar\GoogleCalendarProvider to
 * App\Services\Calendar\AppointmentCalendarSyncService. The service
 * switches on ->category() only — it never parses ->getMessage() or any
 * underlying provider exception to decide a state transition (the
 * approved correction). ->safeMessage() is the only string ever written
 * to the sync row's failure_message column or exposed to an Admin
 * diagnostics response.
 *
 * Any exception NOT wrapped in this type is, by construction, an
 * unclassified/unexpected failure — AppointmentCalendarSyncService lets
 * it propagate rather than catching it, so it reaches the queue job as a
 * genuine infrastructure-level failure (see the job's own docblock).
 */
class CalendarSyncFailureException extends \RuntimeException
{
    public function __construct(
        private readonly string $category,
        string $safeMessage,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($safeMessage, 0, $previous);
    }

    public function category(): string
    {
        return $this->category;
    }

    public function safeMessage(): string
    {
        return $this->getMessage();
    }

    public function isOutcomeUncertain(): bool
    {
        return in_array($this->category, CalendarSyncFailureCategory::UNCERTAIN, true);
    }
}
