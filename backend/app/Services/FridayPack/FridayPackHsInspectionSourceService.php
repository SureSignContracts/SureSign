<?php

namespace App\Services\FridayPack;

use App\Models\HsInspection;
use App\Models\Project;

/**
 * Friday Pack Realignment, R1E.2C — H&S Inspections, corrected in R1F.0
 * (Temporal Boundary Integrity Audit). `inspection_date` is a plain DATE
 * column — no timezone conversion needed, same as every other H&S source
 * before Incidents. Soft-deleted rows excluded automatically by
 * Eloquent's default scope. NO AI, no compliance conclusion —
 * `outcome`/`status` are frozen exactly as recorded, never reinterpreted
 * or collapsed into a single verdict.
 *
 * **R1F.0 fix.** Previously used a `dateRange()`-based `whereBetween()`
 * filter (boundaries `"{$start} 00:00:00"`/`"{$end} 23:59:59"`) — safe
 * ONLY when paired with `HsInspection::$inspection_date`'s then-bare
 * `'date'` cast, which itself wrote back the full `Y-m-d H:i:s` format on
 * save (matching the query's own datetime-suffixed boundaries by
 * design — see `FridayPackSnapshotService::dateRange()`'s own docblock
 * for that ORIGINAL, deliberate pairing, which remains correct for every
 * collector still using it). R1E.2E gave `StatutoryInspection` the
 * corrected `'date:Y-m-d'` cast from the start, which then exposed (via
 * a failing boundary test) that reusing this exact `whereBetween()`
 * pattern against a genuinely bare-date-cast column silently excludes a
 * record landing exactly on the reporting period's own Monday edge — a
 * bare `'2026-08-17'` sorts lexicographically BEFORE
 * `"2026-08-17 00:00:00"` in SQLite's raw string comparison (a strict
 * prefix always sorts before the longer string it prefixes).
 * `HsInspection::$inspection_date` has now been given the SAME corrected
 * `'date:Y-m-d'` cast (R1F.0's own explicit fix), so this collector must
 * use the matching safe comparison — plain `>=`/`<=` against bare
 * `Y-m-d` period-boundary strings, the same pattern
 * `FridayPackPlantEquipmentSourceService`/
 * `FridayPackStatutoryInspectionSourceService` already established.
 */
class FridayPackHsInspectionSourceService
{
    /**
     * @return array{items: array, source_count: int}
     */
    public function aggregate(Project $project, array $period): array
    {
        $inspections = HsInspection::where('project_id', $project->id)
            ->where('inspection_date', '>=', $period['period_start'])
            ->where('inspection_date', '<=', $period['period_end'])
            ->orderBy('inspection_date')
            ->get();

        $items = $inspections->map(fn (HsInspection $i) => [
            'date'             => $i->inspection_date->toDateString(),
            'inspection_type'  => $i->inspection_type,
            'inspected_by'     => $i->inspected_by,
            'outcome'          => $i->outcome,
            'status'           => $i->status,
            'findings'         => $i->findings,
            'actions'          => $i->actions,
        ])->values()->all();

        return ['items' => $items, 'source_count' => count($items)];
    }
}
