<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AppointmentAvailability;
use App\Models\AppointmentAvailabilityOverride;
use App\Models\AppointmentBlockedPeriod;
use App\Models\AppointmentType;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves whether a staff member is actually bookable at a given UTC
 * interval — weekly schedule, date overrides (full precedence over the
 * weekly schedule for that date), blocked periods, minimum notice, and
 * maximum advance window. All overridable by a Super Admin override.
 *
 * Does NOT do same-staff appointment overlap or buffered (buffer-expanded)
 * interval conflict — both of those are enforced separately and
 * unconditionally by AppointmentSchedulingService (isSlotFree() /
 * hasBufferedConflict()) and are never overridable, unlike everything
 * checked here (Phase 2.1: buffer conflicts moved out of this overridable
 * path — see AppointmentSchedulingService's class doc for why).
 *
 * Weekly windows and date overrides are local wall-clock time in the staff
 * member's CURRENT effective timezone, re-resolved on every call — they are
 * never stored with their own timezone. Blocked periods and appointments are
 * fixed UTC instants that don't move if the staff member's timezone setting
 * changes later.
 */
class AppointmentAvailabilityService
{
    public function isEligibleStaff(User $user): bool
    {
        return $user->is_active && !$user->isBanned() && ($user->hasRole('Admin') || $user->hasRole('Super Admin'));
    }

    public function assertEligibleStaff(User $user): void
    {
        if (!$this->isEligibleStaff($user)) {
            throw new \RuntimeException('This user is not eligible for appointment scheduling (must be an active, non-banned Admin or Super Admin).');
        }
    }

    // ─── Weekly schedule ────────────────────────────────────────────────

    public function getWeeklySchedule(User $user): Collection
    {
        return AppointmentAvailability::where('user_id', $user->id)->orderBy('weekday')->orderBy('start_time')->get();
    }

    /**
     * Replaces the user's entire weekly schedule.
     * $windows: array of ['weekday' => 0-6, 'start_time' => 'H:i', 'end_time' => 'H:i', 'is_active' => bool].
     * @throws \InvalidArgumentException on an invalid or overlapping window
     */
    public function setWeeklySchedule(User $user, array $windows, User $actor): Collection
    {
        $this->assertEligibleStaff($user);
        $this->assertNoOverlaps($windows, 'weekday');

        return DB::transaction(function () use ($user, $windows, $actor) {
            AppointmentAvailability::where('user_id', $user->id)->delete();

            $created = collect($windows)->map(fn (array $w) => AppointmentAvailability::create([
                'user_id'    => $user->id,
                'weekday'    => $w['weekday'],
                'start_time' => $w['start_time'],
                'end_time'   => $w['end_time'],
                'is_active'  => $w['is_active'] ?? true,
            ]));

            ActivityLog::record(
                'appointment_availability.updated',
                "Weekly availability updated for {$user->name}.",
                $actor,
                $user,
                ['window_count' => $created->count()],
            );

            return $created;
        });
    }

    // ─── Date-specific overrides ────────────────────────────────────────

    public function getOverrides(User $user, ?string $from = null, ?string $to = null): Collection
    {
        return AppointmentAvailabilityOverride::where('user_id', $user->id)
            ->when($from, fn ($q) => $q->whereDate('local_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('local_date', '<=', $to))
            ->orderBy('local_date')
            ->get();
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function createOverride(User $user, array $data, User $actor): AppointmentAvailabilityOverride
    {
        $this->assertEligibleStaff($user);
        $this->assertValidOverrideRecord($user, $data);

        $override = AppointmentAvailabilityOverride::create([
            'user_id'        => $user->id,
            'local_date'     => $data['local_date'],
            'is_unavailable' => $data['is_unavailable'] ?? false,
            'start_time'     => $data['start_time'] ?? null,
            'end_time'       => $data['end_time'] ?? null,
        ]);

        ActivityLog::record(
            'appointment_availability_override.created',
            "Availability override created for {$user->name} on {$data['local_date']}.",
            $actor,
            $override,
            $data,
        );

        return $override;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function updateOverride(AppointmentAvailabilityOverride $override, array $data, User $actor): AppointmentAvailabilityOverride
    {
        $user = $override->user;
        $merged = array_merge([
            'local_date'     => (string) $override->local_date,
            'is_unavailable' => $override->is_unavailable,
            'start_time'     => $override->start_time,
            'end_time'       => $override->end_time,
        ], $data);

        $this->assertValidOverrideRecord($user, $merged, excludeId: $override->id);

        $override->update($merged);

        ActivityLog::record(
            'appointment_availability_override.updated',
            "Availability override updated for {$user->name} on {$merged['local_date']}.",
            $actor,
            $override,
            $data,
        );

        return $override->refresh();
    }

    public function deleteOverride(AppointmentAvailabilityOverride $override, User $actor): void
    {
        $user = $override->user;

        ActivityLog::record(
            'appointment_availability_override.deleted',
            "Availability override removed for {$user->name} on {$override->local_date}.",
            $actor,
            $override,
            ['local_date' => (string) $override->local_date],
        );

        $override->delete();
    }

    /**
     * A date override fully replaces the weekly schedule for that date —
     * validated only against sibling override rows for the SAME date, never
     * against the weekly schedule.
     *
     * @throws \InvalidArgumentException
     */
    private function assertValidOverrideRecord(User $user, array $data, ?int $excludeId = null): void
    {
        $isUnavailable = $data['is_unavailable'] ?? false;

        if ($isUnavailable) {
            if (!empty($data['start_time']) || !empty($data['end_time'])) {
                throw new \InvalidArgumentException('A whole-day unavailable override cannot also have a start or end time.');
            }
        } else {
            if (empty($data['start_time']) || empty($data['end_time'])) {
                throw new \InvalidArgumentException('Provide both a start and end time, or mark the day unavailable.');
            }
            if ($this->parseTime($data['end_time'])->lte($this->parseTime($data['start_time']))) {
                throw new \InvalidArgumentException('End time must be after start time.');
            }
        }

        $siblings = AppointmentAvailabilityOverride::where('user_id', $user->id)
            ->whereDate('local_date', $data['local_date'])
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get();

        if ($siblings->isEmpty()) {
            return;
        }

        if ($isUnavailable || $siblings->contains('is_unavailable', true)) {
            throw new \InvalidArgumentException('This date already has a conflicting override — remove the existing one first.');
        }

        $newStart = $this->parseTime($data['start_time']);
        $newEnd   = $this->parseTime($data['end_time']);

        foreach ($siblings as $sibling) {
            $siblingStart = $this->parseTime($sibling->start_time);
            $siblingEnd   = $this->parseTime($sibling->end_time);

            if ($newStart->lt($siblingEnd) && $newEnd->gt($siblingStart)) {
                throw new \InvalidArgumentException('Availability windows cannot overlap.');
            }
        }
    }

    // ─── Blocked periods ────────────────────────────────────────────────

    public function getBlockedPeriods(User $user): Collection
    {
        return AppointmentBlockedPeriod::where('user_id', $user->id)->orderBy('starts_at')->get();
    }

    public function createBlockedPeriod(User $user, array $data, User $actor): AppointmentBlockedPeriod
    {
        $this->assertEligibleStaff($user);

        $period = AppointmentBlockedPeriod::create(array_merge($data, [
            'user_id'            => $user->id,
            'created_by_user_id' => $actor->id,
        ]));

        ActivityLog::record(
            'appointment_blocked_period.created',
            "Blocked period created for {$user->name}.",
            $actor,
            $period,
            ['starts_at' => $period->starts_at->toJSON(), 'ends_at' => $period->ends_at->toJSON(), 'reason' => $period->reason],
        );

        return $period;
    }

    public function updateBlockedPeriod(AppointmentBlockedPeriod $period, array $data, User $actor): AppointmentBlockedPeriod
    {
        $period->update($data);

        ActivityLog::record(
            'appointment_blocked_period.updated',
            "Blocked period updated for {$period->user->name}.",
            $actor,
            $period,
            $data,
        );

        return $period->refresh();
    }

    public function deleteBlockedPeriod(AppointmentBlockedPeriod $period, User $actor): void
    {
        ActivityLog::record(
            'appointment_blocked_period.deleted',
            "Blocked period removed for {$period->user->name}.",
            $actor,
            $period,
            [],
        );

        $period->delete();
    }

    // ─── Resolution / validation ────────────────────────────────────────

    /**
     * Windows (local time-of-day pairs) available for $localDate — override
     * rows take total precedence over the weekly schedule when any exist
     * for that date. Returns [] when the date is fully unavailable.
     *
     * @return array<int, array{start: Carbon, end: Carbon}>
     */
    public function resolveWindowsForDate(User $staff, string $localDate): array
    {
        $overrides = AppointmentAvailabilityOverride::where('user_id', $staff->id)
            ->whereDate('local_date', $localDate)
            ->get();

        if ($overrides->isNotEmpty()) {
            if ($overrides->contains('is_unavailable', true)) {
                return [];
            }

            return $overrides->map(fn ($o) => [
                'start' => $this->parseTime($o->start_time),
                'end'   => $this->parseTime($o->end_time),
            ])->values()->all();
        }

        $weekday = Carbon::parse($localDate)->dayOfWeek;

        return AppointmentAvailability::where('user_id', $staff->id)
            ->where('weekday', $weekday)
            ->where('is_active', true)
            ->get()
            ->map(fn ($w) => ['start' => $this->parseTime($w->start_time), 'end' => $this->parseTime($w->end_time)])
            ->values()->all();
    }

    /**
     * Appointment Type business rules (minimum notice, maximum advance) —
     * these apply regardless of whether a staff member is assigned yet, so
     * this is called unconditionally by AppointmentSchedulingService, unlike
     * assertBookable() below which only runs when a staff member exists.
     *
     * $user/$organization drive the timezone the notice/advance window is
     * evaluated in, via the existing TimezoneResolver fallback chain (user →
     * organisation → platform → UTC) — no separate timezone rule is
     * introduced here. When there's no assigned staff member (and often no
     * organisation either, e.g. a public booking with no prospect
     * organisation on file), both are null and the check correctly falls
     * back to the platform's own effective timezone.
     *
     * @throws \RuntimeException with a human-readable rejection reason
     */
    public function assertTypeBookable(AppointmentType $type, Carbon $startsAtUtc, Carbon $endsAtUtc, ?User $user = null, ?Organization $organization = null): void
    {
        $timezone = TimezoneResolver::effectiveTimezone($user, $organization);

        $localStart = $startsAtUtc->copy()->setTimezone($timezone);
        $localNow   = TimezoneResolver::now($user, $organization);

        if ($localNow->copy()->addHours($type->min_notice_hours)->gt($localStart)) {
            throw new \RuntimeException("This appointment type requires at least {$type->min_notice_hours} hour(s) notice.");
        }

        if ($localStart->gt($localNow->copy()->addDays($type->max_advance_days))) {
            throw new \RuntimeException("This appointment type cannot be booked more than {$type->max_advance_days} day(s) in advance.");
        }
    }

    /**
     * The single entry point AppointmentSchedulingService calls before
     * booking/rescheduling a staff-assigned appointment (skipped entirely
     * for a Super Admin override, and never called at all for an
     * unassigned appointment — there's no staff member to validate
     * against, though assertTypeBookable() above still applies to that
     * case). Does NOT check same-staff overlap or buffered-interval
     * conflict; both are enforced separately and unconditionally by
     * AppointmentSchedulingService (hasBufferedConflict()/isSlotFree()) and
     * are never overridable — unlike everything checked here.
     *
     * @throws \RuntimeException with a human-readable rejection reason
     */
    public function assertBookable(User $staff, AppointmentType $type, Carbon $startsAtUtc, Carbon $endsAtUtc, ?int $excludeAppointmentId = null): void
    {
        $this->assertTypeBookable($type, $startsAtUtc, $endsAtUtc, $staff, $staff->organization);

        $timezone = TimezoneResolver::effectiveTimezone($staff, $staff->organization);

        $localStart = $startsAtUtc->copy()->setTimezone($timezone);
        $localEnd   = $endsAtUtc->copy()->setTimezone($timezone);

        if ($localStart->toDateString() !== $localEnd->toDateString()) {
            throw new \RuntimeException("This time falls outside {$staff->name}'s availability (appointment would span midnight in their local timezone).");
        }

        $windows = $this->resolveWindowsForDate($staff, $localStart->toDateString());
        $startTod = $this->parseTime($localStart->format('H:i'));
        $endTod   = $this->parseTime($localEnd->format('H:i'));

        $withinWindow = collect($windows)->contains(
            fn ($w) => $startTod->gte($w['start']) && $endTod->lte($w['end'])
        );
        if (!$withinWindow) {
            throw new \RuntimeException("{$staff->name} is not available at this time.");
        }

        $blocked = AppointmentBlockedPeriod::where('user_id', $staff->id)
            ->where('starts_at', '<', $endsAtUtc)
            ->where('ends_at', '>', $startsAtUtc)
            ->exists();
        if ($blocked) {
            throw new \RuntimeException("{$staff->name} has blocked time during this period.");
        }
    }

    private function parseTime(string $time): Carbon
    {
        return Carbon::createFromFormat('H:i', substr($time, 0, 5));
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertNoOverlaps(array $windows, string $groupField): void
    {
        $groups = collect($windows)->groupBy($groupField);

        foreach ($groups as $group) {
            $parsed = collect($group)->map(function (array $w) {
                $start = $this->parseTime($w['start_time']);
                $end   = $this->parseTime($w['end_time']);
                if ($end->lte($start)) {
                    throw new \InvalidArgumentException('End time must be after start time.');
                }
                return ['start' => $start, 'end' => $end];
            })->sortBy(fn ($p) => $p['start']->format('H:i'))->values();

            for ($i = 1; $i < $parsed->count(); $i++) {
                if ($parsed[$i]['start']->lt($parsed[$i - 1]['end'])) {
                    throw new \InvalidArgumentException('Availability windows cannot overlap.');
                }
            }
        }
    }
}
