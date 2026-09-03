<?php

namespace App\Services\FridayPack;

use App\Models\Project;
use App\Models\SiteDiary;
use Carbon\Carbon;

/**
 * Friday Pack Realignment, R1D — Materials Delivered This Week. Read-only,
 * deterministic source material — mirrors
 * FridayPackWeeklySummarySourceService's exact shape. `SiteDiary::$materials_delivered`
 * is free text (confirmed the only real source anywhere in this codebase
 * — `DeliveryDocument` is an unrelated submittal/document-approval
 * tracker) — never parsed into invented quantity/delivery-note-reference/
 * material-line structure. No confirmed-narrative field exists for this
 * section in R1D (checkpoint's own decision) — the source list IS the
 * final content.
 */
class FridayPackMaterialsSourceService
{
    private function dateRange(string $start, string $end): array
    {
        return ["{$start} 00:00:00", "{$end} 23:59:59"];
    }

    /**
     * @return array<int, array{date: string, day_name: string, materials_delivered: string}>
     */
    public function items(Project $project, array $period): array
    {
        $diaries = SiteDiary::where('project_id', $project->id)
            ->whereBetween('diary_date', $this->dateRange($period['period_start'], $period['period_end']))
            ->whereNotNull('materials_delivered')
            ->where('materials_delivered', '!=', '')
            ->orderBy('diary_date')
            ->get();

        return $diaries->map(fn (SiteDiary $diary) => [
            'date'                => $diary->diary_date->toDateString(),
            'day_name'            => Carbon::parse($diary->diary_date)->format('l'),
            'materials_delivered' => $diary->materials_delivered,
        ])->values()->all();
    }
}
