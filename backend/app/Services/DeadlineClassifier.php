<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Single authoritative overdue/due-today/due-soon/upcoming classification —
 * extracted from CommercialOverviewService (Global Commercial, Batch 1) so
 * the Dashboard's Needs Attention queue can share the exact same rule
 * rather than copying the match expression a second time.
 *
 * Deliberately narrower than CalendarEvent::computeStatusFromDays(), which
 * has its own 30-day "upcoming" bucket and no "due soon" state — that method
 * serves a different consumer (Calendar/Notifications) with a different
 * bucketing scheme and is left untouched.
 *
 * Comparison is always against calendar-date strings (toDateString()), never
 * against a date's own attached timezone — every $date passed in here is a
 * DATE-only business value (a deadline, not a precise instant), matching the
 * convention already established by
 * OperationalIntelligenceService::daysFromToday().
 */
class DeadlineClassifier
{
    /**
     * Beyond this many calendar days, a deadline is "upcoming" rather than
     * "due soon." The one platform-wide authoritative threshold — do not
     * introduce a second value anywhere else.
     */
    public const DUE_SOON_THRESHOLD_DAYS = 7;

    /**
     * @return array{status: string, days: int}
     */
    public static function classify(Carbon $today, Carbon $date): array
    {
        // Both sides are re-parsed from their calendar-date STRING before
        // diffing — never diff the Carbon instances directly. $today (from
        // TimezoneResolver::today()) carries the organisation's real IANA
        // offset (e.g. +01:00 for Europe/London in BST); Carbon::parse() on
        // a bare date string always uses the app's default UTC timezone
        // (+00:00). Diffing two Carbon instants with different offsets
        // computes the true elapsed time between them, not the calendar-day
        // difference — an hour's offset is enough to truncate the wrong way
        // and misclassify a date as one day early/late. Matches the exact
        // convention already established by
        // OperationalIntelligenceService::daysFromToday().
        $days = (int) Carbon::parse($today->toDateString())
            ->diffInDays(Carbon::parse($date->toDateString()), false);

        $status = match (true) {
            $days < 0                              => 'overdue',
            $days === 0                             => 'due_today',
            $days <= self::DUE_SOON_THRESHOLD_DAYS  => 'due_soon',
            default                                 => 'upcoming',
        };

        return ['status' => $status, 'days' => $days];
    }
}
