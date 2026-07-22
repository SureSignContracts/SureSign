<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single authoritative source of same-staff scheduling conflict rules —
 * direct time overlap and effective (buffer-expanded) interval overlap.
 * Both are checked here, unconditionally, for every create/reschedule/
 * assign path (via withConflictCheck()) and for the read-only
 * check-availability preview (via isSlotFree()/hasBufferedConflict()
 * directly) — there is no second, independent conflict calculation
 * anywhere else. Both rules are NEVER overridable, even by a Super Admin
 * override (which may only bypass AppointmentAvailabilityService's
 * checks — weekly/override availability, blocked periods, notice, advance).
 *
 * Effective interval formula, applied to BOTH the proposed appointment and
 * every existing candidate — each using its OWN Appointment Type's own
 * buffer minutes, since two appointments being compared may use different
 * types:
 *
 *   effective_start = starts_at - buffer_before_minutes
 *   effective_end   = ends_at   + buffer_after_minutes
 *
 * Half-open interval semantics [effective_start, effective_end): a conflict
 * exists only when
 *
 *   proposed_effective_start < existing_effective_end
 *   AND
 *   proposed_effective_end   > existing_effective_start
 *
 * Exact boundary contact (proposed_effective_start === existing_effective_end,
 * or proposed_effective_end === existing_effective_start) is explicitly
 * ALLOWED — the strict `<`/`>` comparisons below never treat equal
 * boundaries as a conflict.
 */
class AppointmentSchedulingService
{
    private const ACTIVE_STATUSES = ['requested', 'pending_confirmation', 'confirmed'];

    // Matches the max buffer_before_minutes/buffer_after_minutes allowed by
    // StoreAppointmentTypeRequest (max:480) — widens the SQL candidate
    // range so no true buffered-conflict candidate is ever missed, without
    // scanning a staff member's entire appointment history.
    private const MAX_BUFFER_MINUTES = 480;

    public function __construct(private readonly AppointmentAvailabilityService $availabilityService)
    {
    }

    /**
     * True if $userId has no active appointment with a RAW (unbuffered)
     * time overlap with [$startsAt, $endsAt). $excludeAppointmentId lets a
     * reschedule check against itself excluded.
     */
    public function isSlotFree(int $userId, Carbon $startsAt, Carbon $endsAt, ?int $excludeAppointmentId = null): bool
    {
        return $this->rawOverlapQuery($userId, $startsAt, $endsAt, $excludeAppointmentId)->doesntExist();
    }

    /**
     * True if $userId has an active appointment whose OWN effective
     * (buffer-expanded) interval overlaps the proposed effective interval —
     * see class doc for the formula and boundary semantics. Never
     * overridable.
     */
    public function hasBufferedConflict(int $userId, AppointmentType $type, Carbon $startsAt, Carbon $endsAt, ?int $excludeAppointmentId = null): bool
    {
        $proposedStart = $startsAt->copy()->subMinutes($type->buffer_before_minutes);
        $proposedEnd   = $endsAt->copy()->addMinutes($type->buffer_after_minutes);

        $candidateRangeStart = $proposedStart->copy()->subMinutes(self::MAX_BUFFER_MINUTES);
        $candidateRangeEnd   = $proposedEnd->copy()->addMinutes(self::MAX_BUFFER_MINUTES);

        $candidates = $this->candidateQuery($userId, $candidateRangeStart, $candidateRangeEnd, $excludeAppointmentId)->get();

        foreach ($candidates as $candidate) {
            $candidateType = $candidate->appointmentType;
            $candidateStart = $candidate->starts_at->copy()->subMinutes($candidateType?->buffer_before_minutes ?? 0);
            $candidateEnd   = $candidate->ends_at->copy()->addMinutes($candidateType?->buffer_after_minutes ?? 0);

            if ($proposedStart->lt($candidateEnd) && $proposedEnd->gt($candidateStart)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run $callback inside a transaction with the assigned staff member's
     * relevant existing appointments locked, re-checking direct overlap and
     * buffered conflict (both never overridable) and, unless $override is
     * true, full availability (always overridable) before executing.
     *
     * $staff is null for an unassigned appointment (Super-Admin-only) —
     * there is no staff member to validate against, so the staff-availability
     * checks (weekly schedule, overrides, blocked periods) are skipped
     * entirely. Appointment Type business rules (minimum notice, maximum
     * advance) are NOT staff-availability rules, though, and must still
     * apply regardless of assignment — see
     * AppointmentAvailabilityService::assertTypeBookable(). $organization
     * supplies the timezone fallback for that unassigned case (via the
     * existing TimezoneResolver user→organisation→platform chain); pass null
     * when there's no organisation context (e.g. a public booking).
     *
     * @throws \RuntimeException if the slot is no longer free or unavailable
     */
    public function withConflictCheck(?User $staff, ?AppointmentType $type, Carbon $startsAt, Carbon $endsAt, ?int $excludeAppointmentId, bool $override, \Closure $callback, ?Organization $organization = null): mixed
    {
        return DB::transaction(function () use ($staff, $type, $startsAt, $endsAt, $excludeAppointmentId, $override, $callback, $organization) {
            if ($staff) {
                // Lock a range wide enough to cover both the raw-overlap and
                // the buffer-widened candidate set in one pass, so a
                // concurrent request can't slip in between the check and
                // the write for either rule.
                $lockRangeStart = $startsAt->copy()->subMinutes(self::MAX_BUFFER_MINUTES);
                $lockRangeEnd   = $endsAt->copy()->addMinutes(self::MAX_BUFFER_MINUTES);
                $this->candidateQuery($staff->id, $lockRangeStart, $lockRangeEnd, $excludeAppointmentId)->lockForUpdate()->get();

                if (!$this->isSlotFree($staff->id, $startsAt, $endsAt, $excludeAppointmentId)) {
                    throw new \RuntimeException('The selected time is no longer available for this staff member. Please choose another time.');
                }

                if ($type && $this->hasBufferedConflict($staff->id, $type, $startsAt, $endsAt, $excludeAppointmentId)) {
                    throw new \RuntimeException("This time does not leave the required buffer around another appointment for {$staff->name}.");
                }

                if (!$override) {
                    $this->availabilityService->assertBookable($staff, $type, $startsAt, $endsAt, $excludeAppointmentId);
                }
            } elseif ($type && !$override) {
                $this->availabilityService->assertTypeBookable($type, $startsAt, $endsAt, null, $organization);
            }

            return $callback();
        });
    }

    /**
     * Phase 3: generates the list of bookable start times on $localDate
     * (interpreted as the STAFF member's own calendar day — unchanged) for
     * $staff/$type, passing the exact same
     * isSlotFree()/hasBufferedConflict()/assertBookable() checks a real
     * booking would run through. There is no separate, looser rule set for
     * the public slot list; a slot returned here is guaranteed bookable a
     * moment later (barring a genuine race, which the real booking
     * transaction still guards against).
     *
     * Candidate start times step through each window at a fixed 15-minute
     * granularity — a reasonable default, not a new configurable
     * Appointment Type setting. A candidate whose local time doesn't exist
     * (DST spring-forward gap) is silently skipped rather than offered.
     *
     * Each returned entry is `['date' => 'Y-m-d', 'time' => 'H:i']` —
     * labelled in $displayTimezone when given, otherwise in the staff's own
     * effective timezone (unchanged default). This is a PRESENTATION-ONLY
     * conversion of the already-determined canonical UTC instant
     * ($slotStartUtc) — the instant itself, and every conflict/availability
     * check above, is computed exactly as before, entirely independent of
     * $displayTimezone. Returning `date` alongside `time` (rather than a
     * bare "H:i" string) matters because converting a staff-day slot near
     * midnight into a visitor's timezone can shift it onto the adjacent
     * calendar date — the frontend must display and submit that actual
     * shifted date, not silently mislabel it under the staff's date.
     * Sorted by the underlying UTC instant (not the formatted strings), so
     * a date-crossing slot still lands in correct chronological order.
     */
    public function generateAvailableSlots(User $staff, AppointmentType $type, string $localDate, ?string $displayTimezone = null): array
    {
        $stepMinutes = 15;
        $windows = $this->availabilityService->resolveWindowsForDate($staff, $localDate);
        $staffTimezone = TimezoneResolver::effectiveTimezone($staff, $staff->organization);
        $labelTimezone = $displayTimezone ?: $staffTimezone;

        $slots = []; // keyed by UTC timestamp — de-duplicates overlapping windows and keeps chronological order for free.

        foreach ($windows as $window) {
            $cursor = $window['start']->copy();
            $windowEnd = $window['end'];

            while ($cursor->copy()->addMinutes($type->duration_minutes)->lte($windowEnd)) {
                try {
                    $slotStartUtc = TimezoneResolver::buildLocalInstant($localDate, $cursor->format('H:i'), $staffTimezone);
                } catch (\InvalidArgumentException) {
                    $cursor->addMinutes($stepMinutes);
                    continue;
                }
                $slotEndUtc = $slotStartUtc->copy()->addMinutes($type->duration_minutes);

                $bookable = $this->isSlotFree($staff->id, $slotStartUtc, $slotEndUtc)
                    && !$this->hasBufferedConflict($staff->id, $type, $slotStartUtc, $slotEndUtc);

                if ($bookable) {
                    try {
                        $this->availabilityService->assertBookable($staff, $type, $slotStartUtc, $slotEndUtc);
                        $displayInstant = $slotStartUtc->copy()->setTimezone($labelTimezone);
                        $slots[$slotStartUtc->getTimestamp()] = [
                            'date' => $displayInstant->format('Y-m-d'),
                            'time' => $displayInstant->format('H:i'),
                        ];
                    } catch (\RuntimeException) {
                        // Not bookable (notice/advance/blocked) — skip.
                    }
                }

                $cursor->addMinutes($stepMinutes);
            }
        }

        ksort($slots);
        return array_values($slots);
    }

    /**
     * Which local dates within [$year-$month] have at least one bookable
     * slot for $staff/$type — powers the public booking calendar's
     * available/unavailable colouring per month. Deliberately just calls
     * generateAvailableSlots() once per candidate STAFF day and checks for
     * a non-empty result: this is the same authoritative slot generation
     * every other read path uses, not a second availability calculation.
     * A day already excluded by the type's own notice/advance window (see
     * $earliestLocalDate/$latestLocalDate below) is skipped without even
     * querying, since generateAvailableSlots() would return empty for it
     * anyway via assertBookable() — this just avoids the wasted query.
     *
     * Scans one day of slack on either side of the requested month: once
     * generateAvailableSlots() labels a slot in $displayTimezone, a staff
     * day near a month/day boundary can produce a slot whose displayed
     * date falls just outside that staff day (see that method's own doc) —
     * the scan needs to look one day past each edge to catch a slot that
     * shifts into (or out of) the requested month, and the final result is
     * bucketed by each slot's OWN displayed date (not the staff day that
     * generated it) so the returned set is accurate from the visitor's own
     * calendar perspective, then filtered back down to just this month.
     */
    public function bookableDatesInMonth(User $staff, AppointmentType $type, int $year, int $month, string $earliestLocalDate, string $latestLocalDate, ?string $displayTimezone = null): array
    {
        $firstOfMonth = Carbon::create($year, $month, 1);
        $scanStart = $firstOfMonth->copy()->subDay();
        $scanEnd = $firstOfMonth->copy()->endOfMonth()->addDay();

        $bookableDisplayDates = [];
        $cursor = $scanStart->copy();
        while ($cursor->lte($scanEnd)) {
            $localDate = $cursor->toDateString();
            if ($localDate >= $earliestLocalDate && $localDate <= $latestLocalDate) {
                foreach ($this->generateAvailableSlots($staff, $type, $localDate, $displayTimezone) as $slot) {
                    $bookableDisplayDates[$slot['date']] = true;
                }
            }
            $cursor->addDay();
        }

        $monthPrefix = sprintf('%04d-%02d-', $year, $month);
        $dates = array_keys(array_filter(
            $bookableDisplayDates,
            fn (string $date): bool => str_starts_with($date, $monthPrefix),
            ARRAY_FILTER_USE_KEY,
        ));

        sort($dates);
        return $dates;
    }

    private function rawOverlapQuery(int $userId, Carbon $startsAt, Carbon $endsAt, ?int $excludeAppointmentId)
    {
        return Appointment::query()
            ->where('assigned_user_id', $userId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->when($excludeAppointmentId, fn ($q) => $q->where('id', '!=', $excludeAppointmentId))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);
    }

    private function candidateQuery(int $userId, Carbon $rangeStart, Carbon $rangeEnd, ?int $excludeAppointmentId)
    {
        return Appointment::query()
            ->where('assigned_user_id', $userId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->when($excludeAppointmentId, fn ($q) => $q->where('id', '!=', $excludeAppointmentId))
            ->where('starts_at', '<', $rangeEnd)
            ->where('ends_at', '>', $rangeStart)
            ->with('appointmentType');
    }
}
