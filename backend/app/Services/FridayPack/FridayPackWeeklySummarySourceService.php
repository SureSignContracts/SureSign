<?php

namespace App\Services\FridayPack;

use App\Models\Project;
use App\Models\SiteDiary;
use Carbon\Carbon;

/**
 * Friday Pack Realignment, R1C — Weekly Summary. Read-only, deterministic
 * source material for the Weekly Summary section — mirrors
 * FridayPackPhotoDiscoveryService's exact shape (a pure discovery service;
 * never persists anything, never composes/synthesizes text). NO AI
 * anywhere in this class.
 *
 * Only Site Reports within the authoritative Monday-Friday reporting
 * period AND with non-empty `works_carried_out` count as source material
 * — a Site Report with no recorded works, or one dated on a weekend
 * (never queried at all, since the period itself is Mon-Fri only — see
 * FridayPackPeriodResolver), never contributes.
 */
class FridayPackWeeklySummarySourceService
{
    /**
     * Mirrors FridayPackSnapshotService::dateRange()'s exact reasoning — a
     * bare [$start, $end] pair mismatches a `date`-cast column's stored
     * datetime format on SQLite's plain-TEXT date columns.
     */
    private function dateRange(string $start, string $end): array
    {
        return ["{$start} 00:00:00", "{$end} 23:59:59"];
    }

    /**
     * @return array<int, array{date: string, day_name: string, works_carried_out: string}>
     */
    public function sources(Project $project, array $period): array
    {
        $diaries = SiteDiary::where('project_id', $project->id)
            ->whereBetween('diary_date', $this->dateRange($period['period_start'], $period['period_end']))
            ->whereNotNull('works_carried_out')
            ->where('works_carried_out', '!=', '')
            ->orderBy('diary_date')
            ->get();

        return $diaries->map(fn (SiteDiary $diary) => [
            'date'              => $diary->diary_date->toDateString(),
            'day_name'          => Carbon::parse($diary->diary_date)->format('l'),
            'works_carried_out' => $diary->works_carried_out,
        ])->values()->all();
    }

    /**
     * The count of Site Reports that actually contributed source material
     * — never every Site Report row in the period. Reused by
     * FridayPackSnapshotService so the frozen snapshot's
     * `source_site_report_count` and this endpoint's own count can never
     * silently drift apart.
     */
    public function sourceCount(Project $project, array $period): int
    {
        return count($this->sources($project, $period));
    }
}
