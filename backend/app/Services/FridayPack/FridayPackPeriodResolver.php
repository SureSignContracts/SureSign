<?php

namespace App\Services\FridayPack;

use App\Models\Organization;
use App\Services\TimezoneResolver;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Automated Friday Pack, V1B — the single authoritative place a
 * `week_ending` date is validated and turned into a concrete reporting
 * period. No controller/frontend performs its own date math (V1B product
 * decision).
 *
 * Week range (realigned, R1A — Friday Pack Realignment, 2026-08-31):
 * Monday 00:00 through Friday 23:59:59, resolved in the OWNING
 * ORGANISATION's timezone — never `projects.timezone` (which does not
 * exist and is deliberately not being added this phase) and never a
 * second timezone resolver; this delegates to the existing
 * App\Services\TimezoneResolver exactly as every other date-boundary
 * decision in this codebase already does.
 *
 * R1A superseded V1B's original Saturday-through-Friday range — the
 * business-provided Friday Pack reference uses "Week Commencing"
 * (Monday) / "Week Ending" (Friday) throughout, matching how a
 * construction site actually reports its working week. `week_ending`
 * must still be a Friday; only how far back `period_start` reaches has
 * changed (5 days back, not 6).
 *
 * Weekend site activity is NOT excluded by this change — a Saturday/
 * Sunday Site Report still exists and is still queryable exactly as
 * before; it simply falls outside the formal Friday Pack reporting
 * period, matching the business reference's own convention (its
 * workforce table offers Sat/Sun columns for exceptional weekend working,
 * while the report's own Week Commencing/Ending pair is always
 * Monday/Friday). Any future workforce/evidence structure that needs to
 * represent weekend site activity is a later Friday Pack phase's decision
 * — this resolver makes no attempt to special-case it.
 */
class FridayPackPeriodResolver
{
    /**
     * True only when the given date string falls on a Friday, evaluated in
     * the organisation's effective timezone — the same check used both by
     * validation (reject a non-Friday week_ending outright, never silently
     * shift it) and by resolve() itself.
     */
    public static function isFriday(string $date, ?Organization $organization): bool
    {
        $timezone = TimezoneResolver::effectiveTimezone(null, $organization);

        return Carbon::parse($date, $timezone)->dayOfWeekIso === Carbon::FRIDAY;
    }

    /**
     * @return array{week_ending: string, period_start: string, period_end: string, timezone: string}
     *
     * @throws InvalidArgumentException when $weekEndingDate is not a Friday.
     */
    public static function resolve(string $weekEndingDate, ?Organization $organization): array
    {
        $timezone = TimezoneResolver::effectiveTimezone(null, $organization);
        $weekEnding = Carbon::parse($weekEndingDate, $timezone)->startOfDay();

        if ($weekEnding->dayOfWeekIso !== Carbon::FRIDAY) {
            throw new InvalidArgumentException("week_ending ({$weekEndingDate}) must be a Friday.");
        }

        // Monday 00:00 through Friday 23:59:59 — a five-day working-week
        // window (R1A realignment; see class docblock). Weekend site
        // activity is not part of the formal Friday Pack period, but is
        // never hidden, deleted, or otherwise altered by this resolver.
        $periodStart = $weekEnding->copy()->subDays(4);

        return [
            'week_ending'  => $weekEnding->toDateString(),
            'period_start' => $periodStart->toDateString(),
            'period_end'   => $weekEnding->toDateString(),
            'timezone'     => $timezone,
        ];
    }
}
