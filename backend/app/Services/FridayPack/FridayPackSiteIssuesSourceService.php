<?php

namespace App\Services\FridayPack;

use App\Models\DelayEvent;
use App\Models\Project;
use App\Models\SiteDiary;
use Carbon\Carbon;

/**
 * Friday Pack Realignment, R1D — Site Issues, Delays & Risks. Read-only,
 * deterministic source material — mirrors
 * FridayPackWeeklySummarySourceService's exact shape. NO AI anywhere in
 * this class.
 *
 * Primary source: `SiteDiary::$issues` for Mon-Fri Site Reports within
 * the reporting period, non-empty only. Secondary, optional reference:
 * `DelayEvent` rows whose `date_occurred` falls inside the SAME period —
 * never the full DelayEvent history, and `ContractRisk` is never queried
 * here at all (the R1D checkpoint's own explicit instruction — this
 * section answers "what affected work THIS WEEK," not the persistent
 * Contract Risk Register).
 */
class FridayPackSiteIssuesSourceService
{
    private function dateRange(string $start, string $end): array
    {
        return ["{$start} 00:00:00", "{$end} 23:59:59"];
    }

    /**
     * @return array<int, array{date: string, day_name: string, issue: string}>
     */
    public function sources(Project $project, array $period): array
    {
        $diaries = SiteDiary::where('project_id', $project->id)
            ->whereBetween('diary_date', $this->dateRange($period['period_start'], $period['period_end']))
            ->whereNotNull('issues')
            ->where('issues', '!=', '')
            ->orderBy('diary_date')
            ->get();

        return $diaries->map(fn (SiteDiary $diary) => [
            'date'     => $diary->diary_date->toDateString(),
            'day_name' => Carbon::parse($diary->diary_date)->format('l'),
            'issue'    => $diary->issues,
        ])->values()->all();
    }

    public function sourceCount(Project $project, array $period): int
    {
        return count($this->sources($project, $period));
    }

    /**
     * Compact, deliberately-shaped DelayEvent references — never a raw
     * model dump. Optional reference material only; never auto-merged
     * into the confirmed narrative.
     *
     * @return array<int, array{id: int, title: string, date_occurred: string, cause_category: ?string}>
     */
    public function delayEventReferences(Project $project, array $period): array
    {
        $events = DelayEvent::where('project_id', $project->id)
            ->whereBetween('date_occurred', $this->dateRange($period['period_start'], $period['period_end']))
            ->orderBy('date_occurred')
            ->get();

        return $events->map(fn (DelayEvent $event) => [
            'id'             => $event->id,
            'title'          => $event->title,
            'date_occurred'  => optional($event->date_occurred)->toDateString(),
            'cause_category' => $event->cause_category,
        ])->values()->all();
    }
}
