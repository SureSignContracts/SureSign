<?php

namespace App\Services\FridayPack;

use App\Models\PlantDeployment;
use App\Models\Project;

/**
 * Friday Pack Realignment, R1E.2D — Plant & Equipment. Read-only,
 * deterministic source aggregation. The authoritative source is
 * `PlantDeployment` — NEVER `PlantItem::$status` (a register/operational
 * state, not presence). Soft-deleted deployments/items excluded
 * automatically by Eloquent's default scope. No inspection dependency —
 * a deployment alone is sufficient to appear here, matching the R1E.2D
 * checkpoint's own explicit "an inspection must never be required merely
 * to prove plant was on site" rule.
 *
 * Selection uses the exact interval-overlap formula the checkpoint
 * specifies: `on_site_from <= period_end AND (off_site_at IS NULL OR
 * off_site_at >= period_start)` — the same formula
 * `App\Services\Plant\PlantDeploymentService` uses for overlap
 * protection, applied here against the Friday Pack's own Mon-Fri window
 * instead of another deployment.
 *
 * **Multiple non-overlapping periods in one week are never merged.** If
 * the same PlantItem has two genuinely separate deployment periods that
 * both intersect the reporting week (e.g. Mon-Tue, then Thu-Fri), both
 * are preserved as distinct entries in that item's own
 * `presence_periods` array — never silently collapsed into one
 * continuous Mon-Fri claim.
 */
class FridayPackPlantEquipmentSourceService
{
    /**
     * @param  array{period_start: string, period_end: string}  $period
     * @return array{items: array, source_count: int, deployment_source_count: int}
     */
    public function aggregate(Project $project, array $period): array
    {
        $periodStart = $period['period_start'];
        $periodEnd   = $period['period_end'];

        $deployments = PlantDeployment::where('project_id', $project->id)
            ->where('on_site_from', '<=', $periodEnd)
            ->where(function ($q) use ($periodStart) {
                $q->whereNull('off_site_at')->orWhere('off_site_at', '>=', $periodStart);
            })
            ->with('plantItem')
            ->orderBy('on_site_from')
            ->get()
            // Defensive only — plant_item_id is restrictOnDelete, so an
            // orphaned deployment should never exist in practice.
            ->filter(fn (PlantDeployment $d) => $d->plantItem !== null);

        $items = $deployments->groupBy('plant_item_id')->map(function ($deploymentsForItem) {
            $plantItem = $deploymentsForItem->first()->plantItem;

            return [
                'name'             => $plantItem->name,
                'type'             => $plantItem->type,
                'identifier'       => $plantItem->identifier,
                'owner_supplier'   => $plantItem->owner_supplier,
                'plant_status'     => $plantItem->status,
                'presence_periods' => $deploymentsForItem->map(fn (PlantDeployment $d) => [
                    'on_site_from' => $d->on_site_from->toDateString(),
                    'off_site_at'  => optional($d->off_site_at)->toDateString(),
                    'notes'        => $d->notes,
                ])->values()->all(),
            ];
        })->values()->all();

        return [
            'items'                    => $items,
            // Distinct Plant Items represented — never the deployment
            // row count (a single item with two periods this week is
            // still one item, not two, in this count).
            'source_count'             => count($items),
            'deployment_source_count'  => $deployments->count(),
        ];
    }
}
