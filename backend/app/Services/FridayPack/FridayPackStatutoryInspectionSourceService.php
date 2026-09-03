<?php

namespace App\Services\FridayPack;

use App\Models\Project;
use App\Models\StatutoryInspection;

/**
 * Friday Pack Realignment, R1E.2E — Statutory Inspections. Read-only,
 * deterministic source aggregation. `inspection_date` is a plain DATE
 * column — no timezone conversion needed, same as every other H&S source
 * before Incidents. Soft-deleted rows excluded automatically by
 * Eloquent's default scope. NO AI, no compliance conclusion —
 * `outcome`/`status`/`next_due_date` are frozen exactly as recorded,
 * never reinterpreted, and never turned into an "overdue"/"compliant"
 * judgement.
 *
 * **Deliberately does NOT reuse FridayPackHsInspectionSourceService's own
 * `dateRange()`-based `whereBetween()` pattern** (a plain `'2026-08-17'`
 * boundary compared against `"2026-08-17 00:00:00"` /
 * `"2026-08-21 23:59:59"`). That pattern only produces correct results on
 * SQLite because `HsInspection::$inspection_date` carries the R1E.2D-
 * documented bare-`'date'`-cast defect (it actually stores
 * `'2026-08-17 00:00:00'`, not `'2026-08-17'`) — the lower boundary
 * happens to match by coincidence, not by a genuinely safe comparison.
 * `StatutoryInspection::$inspection_date` uses the corrected
 * `'date:Y-m-d'` cast (see that model's own docblock), so it genuinely
 * stores a bare `'2026-08-17'` — under `HsInspection`'s own
 * `whereBetween()` boundaries, a bare stored date is lexicographically
 * LESS than `"2026-08-17 00:00:00"` on SQLite (a strict string prefix
 * sorts before the longer string it prefixes) and would be silently
 * excluded at the reporting week's own Monday edge. Verified directly by
 * a failing `test_mon_fri_inspection_selected`-style boundary test before
 * this fix. Real MySQL would not have shown this (its DATE/DATETIME
 * comparison coerces temporally, not lexicographically) — exactly the
 * kind of SQLite-only discrepancy CLAUDE.md's MySQL-validation discipline
 * exists to catch before it reaches a shared collector pattern.
 *
 * Uses plain `>=`/`<=` comparisons against bare `Y-m-d` period-boundary
 * strings instead — the SAME safe pattern
 * `FridayPackPlantEquipmentSourceService`/`PlantDeploymentService`
 * already established in R1E.2D for a column genuinely cast as
 * `'date:Y-m-d'`. Do not "fix" this back to the `whereBetween()` pattern
 * without first confirming the column's actual stored format matches
 * what that pattern assumes.
 *
 * If linked to a PlantItem, the item's `name`/`identifier` are frozen
 * into the returned item at generation time — a later PlantItem
 * rename/soft-delete never changes an already-generated Friday Pack's
 * own snapshot. `plantItem()` deliberately resolves `withTrashed()` (see
 * that relation's own docblock) precisely so this freeze still works
 * even when the linked item has since been soft-deleted.
 *
 * Feeds only the previously-empty `inspections` sub-array of the
 * existing `permits_inspections` snapshot section — see
 * FridayPackSnapshotService's own `PERMITS_INSPECTIONS` construction.
 * This service never touches, queries, or knows about the `permits`
 * half of that section at all.
 */
class FridayPackStatutoryInspectionSourceService
{
    /**
     * @return array{items: array, source_count: int}
     */
    public function aggregate(Project $project, array $period): array
    {
        $inspections = StatutoryInspection::where('project_id', $project->id)
            ->where('inspection_date', '>=', $period['period_start'])
            ->where('inspection_date', '<=', $period['period_end'])
            ->with('plantItem')
            ->orderBy('inspection_date')
            ->get();

        $items = $inspections->map(fn (StatutoryInspection $i) => [
            'inspection_date'      => $i->inspection_date->toDateString(),
            'inspection_type'      => $i->inspection_type,
            'subject_description'  => $i->subject_description,
            'plant'                => $i->plantItem ? [
                'name'       => $i->plantItem->name,
                'identifier' => $i->plantItem->identifier,
            ] : null,
            'reference'            => $i->reference,
            'outcome'              => $i->outcome,
            'status'               => $i->status,
            'next_due_date'        => optional($i->next_due_date)->toDateString(),
        ])->values()->all();

        return ['items' => $items, 'source_count' => count($items)];
    }
}
