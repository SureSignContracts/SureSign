<?php

namespace App\Services\FridayPack;

use App\Models\Project;
use App\Models\SiteInduction;

/**
 * Friday Pack Realignment, R1E.2A — Site Inductions. Read-only,
 * deterministic source aggregation — mirrors
 * FridayPackWeeklySummarySourceService's exact shape. Selects
 * `SiteInduction` sessions (soft-deleted rows excluded automatically by
 * Eloquent's default SoftDeletes scope) whose `induction_date` falls
 * within the authoritative Mon-Fri Friday Pack reporting period. NO AI
 * anywhere in this class.
 *
 * `total_inducted` is a SUM of session attendance across the week — this
 * is summed induction-SESSION attendance, never a claim of unique people
 * inducted (the same person could plausibly appear in more than one
 * session's count; SureSign has no individual worker identity to
 * deduplicate against). Never labelled "unique inductees"/"unique
 * workers" anywhere this value is presented.
 */
class FridayPackSiteInductionSourceService
{
    private function dateRange(string $start, string $end): array
    {
        return ["{$start} 00:00:00", "{$end} 23:59:59"];
    }

    /**
     * @return array{items: array, total_inducted: int, source_count: int}
     */
    public function aggregate(Project $project, array $period): array
    {
        $sessions = SiteInduction::where('project_id', $project->id)
            ->whereBetween('induction_date', $this->dateRange($period['period_start'], $period['period_end']))
            ->orderBy('induction_date')
            ->get();

        $items = $sessions->map(fn (SiteInduction $s) => [
            'date'              => $s->induction_date->toDateString(),
            'session_title'     => $s->session_title,
            'company_or_trade'  => $s->company_or_trade,
            'inductee_count'    => $s->inductee_count,
            'notes'             => $s->notes,
        ])->values()->all();

        return [
            'items'          => $items,
            'total_inducted' => (int) $sessions->sum('inductee_count'),
            'source_count'   => $sessions->count(),
        ];
    }
}
