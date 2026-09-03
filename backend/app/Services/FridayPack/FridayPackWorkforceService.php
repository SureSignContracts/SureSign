<?php

namespace App\Services\FridayPack;

use App\Models\Project;
use App\Models\SiteDiary;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Friday Pack Realignment, R1C — Workforce. The ONE authoritative
 * aggregation service for the Friday Pack Workforce section. Deterministic
 * only — no AI, no fabricated rows, no fabricated totals.
 *
 * **Same-date Site Report semantic finding (Amendment 3).** Investigated
 * before building this class: `site_diaries` has no unique
 * (project_id, diary_date) constraint, `SiteDiaryController::store()` has
 * no same-date guard or "shift/crew" concept anywhere, and the product
 * documentation (docs/site-reports/overview.md,
 * docs/workflows/site-report-process.md) describes a Site Report as
 * singular — "a dated record of site activity" / "a continuous record" —
 * with no mention of shifts, crews, or intentionally-additive multiple
 * entries per day. Additivity is NOT proven. Per the approved conflict-safe
 * fallback, multiple same-date Site Reports with DIFFERING
 * `workers_on_site` values are surfaced as an explicit conflict, never
 * silently summed/averaged/maxed.
 *
 * **Weekend exclusion.** This service only ever queries the resolved
 * Monday-Friday period (`$period['period_start']`/`$period['period_end']`,
 * both already Mon/Fri from FridayPackPeriodResolver) — it never queries
 * Saturday/Sunday records and never modifies FridayPackPeriodResolver.
 *
 * **Person-days, not "unique workers"/"total employees".** The weekly
 * summed attendance is a count of person-days recorded across the period,
 * not a count of distinct individuals (no individual-worker identity
 * exists anywhere in this codebase).
 */
class FridayPackWorkforceService
{
    private function dateRange(string $start, string $end): array
    {
        return ["{$start} 00:00:00", "{$end} 23:59:59"];
    }

    /**
     * @return array{
     *   days: array<int, array{date: string, day: string, daily_total: ?int, source_count: int, status: string, has_breakdown: bool, breakdown_total: ?int}>,
     *   rows: array<int, array{trade_or_role: string, counts: array<string, ?int>, person_days_total: int}>,
     *   has_trade_breakdown: bool,
     *   person_days_total: int,
     *   has_conflicts: bool,
     * }
     */
    public function aggregate(Project $project, array $period): array
    {
        $dates = $this->weekdayDates($period['period_start'], $period['period_end']);

        $diaries = SiteDiary::where('project_id', $project->id)
            ->whereBetween('diary_date', $this->dateRange($period['period_start'], $period['period_end']))
            ->with('workforceEntries')
            ->get()
            ->groupBy(fn (SiteDiary $d) => $d->diary_date->toDateString());

        $days = [];
        $hasConflicts = false;
        $personDaysTotal = 0;

        // trade key (normalized) => ['label' => original display label, 'counts' => [date => int]]
        $tradeAccumulator = [];

        foreach ($dates as $date) {
            $dayDiaries = $diaries->get($date, collect());

            [$dailyTotal, $dailyStatus] = $this->resolveDailyTotal($dayDiaries);
            if ($dailyStatus === 'conflicting_site_reports') {
                $hasConflicts = true;
            }
            if ($dailyStatus === 'recorded') {
                $personDaysTotal += $dailyTotal;
            }

            [$breakdownTotal, $breakdownEntries, $breakdownConflict] = $this->resolveDailyBreakdown($dayDiaries);
            if ($breakdownConflict) {
                $hasConflicts = true;
            }

            $days[] = [
                'date'            => $date,
                'day'             => Carbon::parse($date)->format('l'),
                'daily_total'     => $dailyTotal,
                'source_count'    => $dayDiaries->count(),
                'status'          => $dailyStatus,
                'has_breakdown'   => $breakdownEntries !== null,
                'breakdown_total' => $breakdownTotal,
            ];

            if ($breakdownEntries !== null) {
                foreach ($breakdownEntries as $entry) {
                    $key = mb_strtolower(trim($entry['trade_or_role']));
                    if (!isset($tradeAccumulator[$key])) {
                        $tradeAccumulator[$key] = ['label' => trim($entry['trade_or_role']), 'counts' => []];
                    }
                    $tradeAccumulator[$key]['counts'][$date] = $entry['operative_count'];
                }
            }
        }

        $rows = [];
        foreach ($tradeAccumulator as $trade) {
            $counts = [];
            $total = 0;
            foreach ($dates as $date) {
                $dayName = strtolower(Carbon::parse($date)->format('l'));
                $count = $trade['counts'][$date] ?? null;
                $counts[$dayName] = $count;
                $total += $count ?? 0;
            }
            $rows[] = [
                'trade_or_role'     => $trade['label'],
                'counts'            => $counts,
                'person_days_total' => $total,
            ];
        }

        // Deterministic order — alphabetical by display label, never
        // insertion order (which would depend on diary/date iteration).
        usort($rows, fn ($a, $b) => strcasecmp($a['trade_or_role'], $b['trade_or_role']));

        return [
            'days'                => $days,
            'rows'                => $rows,
            'has_trade_breakdown' => count($rows) > 0,
            'person_days_total'   => $personDaysTotal,
            'has_conflicts'       => $hasConflicts,
        ];
    }

    /** @return string[] the five Monday-Friday dates for this period, in order. */
    private function weekdayDates(string $start, string $end): array
    {
        $dates = [];
        $cursor = Carbon::parse($start)->startOfDay();
        $endDate = Carbon::parse($end)->startOfDay();
        while ($cursor->lte($endDate)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $dates;
    }

    /**
     * @return array{0: ?int, 1: string} [daily_total, status]
     */
    private function resolveDailyTotal(Collection $dayDiaries): array
    {
        $distinctValues = $dayDiaries->pluck('workers_on_site')
            ->filter(fn ($v) => $v !== null)
            ->unique()
            ->values();

        return match (true) {
            $distinctValues->isEmpty() => [null, 'no_data'],
            $distinctValues->count() === 1 => [(int) $distinctValues->first(), 'recorded'],
            default => [null, 'conflicting_site_reports'],
        };
    }

    /**
     * Same-date breakdown caution (Amendment 3's own extension to
     * breakdowns): if more than one of the day's Site Reports carries its
     * own workforce breakdown, which one is authoritative for that date
     * cannot be determined without assuming additivity — never proven (see
     * class docblock) — so the day's breakdown is surfaced as a conflict
     * instead of silently summed. Exactly one Site Report with a
     * breakdown for the date is unambiguous and used directly, regardless
     * of how many diaries exist that day.
     *
     * @return array{0: ?int, 1: ?array<int, array{trade_or_role: string, operative_count: int}>, 2: bool} [breakdown_total, entries|null, conflict]
     */
    private function resolveDailyBreakdown(Collection $dayDiaries): array
    {
        $diariesWithBreakdown = $dayDiaries->filter(fn (SiteDiary $d) => $d->workforceEntries->isNotEmpty());

        if ($diariesWithBreakdown->isEmpty()) {
            return [null, null, false];
        }

        if ($diariesWithBreakdown->count() > 1) {
            return [null, null, true];
        }

        $entries = $diariesWithBreakdown->first()->workforceEntries
            ->map(fn ($e) => ['trade_or_role' => $e->trade_or_role, 'operative_count' => $e->operative_count])
            ->values()
            ->all();

        $total = array_sum(array_column($entries, 'operative_count'));

        return [$total, $entries, false];
    }
}
