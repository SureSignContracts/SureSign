<?php

namespace App\Services\FridayPack;

use App\Models\ContractProgrammeMilestone;
use App\Models\Project;
use Carbon\Carbon;

/**
 * Friday Pack Realignment, R1D — Look Ahead: Next Week's Programme.
 * Read-only, deterministic source material — never the full Programme
 * dump (R1A already removed that as a Friday Pack section). NO AI
 * anywhere in this class; final wording is always human-confirmed.
 *
 * The "next week" window is the calendar Monday-Friday immediately
 * following the CURRENT Friday Pack's own reporting week — deliberately
 * not an arbitrary 30-day window, and derived from the pack's own
 * `period_end` (its Friday) without touching FridayPackPeriodResolver
 * itself.
 *
 * Effective-date preference (`forecast_date ?? planned_date`) mirrors the
 * existing precedent already established in
 * App\Services\OperationalIntelligenceService and
 * App\Services\Dashboard\OrganisationDashboardService — never a new,
 * separately-invented preference rule. `actual_date` is deliberately
 * never consulted here (a milestone that has already actually happened
 * is not "upcoming").
 */
class FridayPackLookAheadSourceService
{
    /**
     * @return array{start: string, end: string} the next Monday-Friday
     *   window immediately after this Friday Pack's own reporting week.
     */
    public function nextWeekWindow(string $periodEnd): array
    {
        $friday = Carbon::parse($periodEnd)->startOfDay();

        return [
            'start' => $friday->copy()->addDays(3)->toDateString(),
            'end'   => $friday->copy()->addDays(7)->toDateString(),
        ];
    }

    /**
     * @return array<int, array{id: int, name: string, milestone_type: ?string, upcoming_date: string, status: ?string}>
     */
    public function sources(Project $project, string $periodEnd): array
    {
        $window = $this->nextWeekWindow($periodEnd);
        $windowStart = Carbon::parse($window['start'])->startOfDay();
        $windowEnd = Carbon::parse($window['end'])->endOfDay();

        $milestones = ContractProgrammeMilestone::where('project_id', $project->id)
            ->where(function ($q) {
                $q->whereNotNull('forecast_date')->orWhereNotNull('planned_date');
            })
            ->get();

        return $milestones
            ->map(function (ContractProgrammeMilestone $m) {
                $effectiveDate = $m->forecast_date ?? $m->planned_date;

                return $effectiveDate ? [
                    'id'             => $m->id,
                    'name'           => $m->name,
                    'milestone_type' => $m->milestone_type,
                    'upcoming_date'  => $effectiveDate->toDateString(),
                    'status'         => $m->status,
                    '_effective'     => $effectiveDate,
                ] : null;
            })
            ->filter()
            ->filter(fn (array $m) => $m['_effective']->between($windowStart, $windowEnd))
            ->sortBy('upcoming_date')
            ->map(fn (array $m) => collect($m)->except('_effective')->all())
            ->values()
            ->all();
    }

    public function sourceMilestoneCount(Project $project, string $periodEnd): int
    {
        return count($this->sources($project, $periodEnd));
    }
}
