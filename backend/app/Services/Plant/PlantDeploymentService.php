<?php

namespace App\Services\Plant;

use App\Models\PlantDeployment;
use App\Models\PlantItem;
use App\Models\Project;
use App\Models\User;
use App\Support\Plant\PlantDeploymentOverlapException;
use Illuminate\Support\Facades\DB;

/**
 * Friday Pack Realignment, R1E.2D — the ONE authoritative service for
 * creating/updating a PlantDeployment. Never duplicated in the
 * controller, the model, or the frontend — the frontend's own overlap
 * hinting (if any) is UX assistance only; this service is the sole
 * source of truth.
 *
 * **Concurrency decision**: mirrors `FridayPackGenerationService::generate()`'s
 * exact TOCTOU-closing pattern (see CLAUDE.md's P2 AI Analysis Security
 * Remediation) — a `DB::transaction()` that first
 * `PlantItem::whereKey($id)->lockForUpdate()->first()`s the AUTHORITATIVE
 * PARENT PlantItem row before checking for an overlap and writing the
 * deployment. This serializes concurrent deployment mutations for the
 * SAME plant item only — a different plant item's deployments are
 * completely unaffected, and no application-level cache lock
 * (`Cache::lock()`) is needed, matching this codebase's own established
 * preference for a DB row lock over a cache lock wherever one cleanly
 * applies (see `AiCreditLedgerService`'s own docblock for the identical
 * reasoning). This is the one natural serialization point per plant
 * item: two concurrent requests proposing overlapping periods for the
 * SAME item can never both pass the overlap check, because the second
 * transaction blocks on the row lock until the first commits (or rolls
 * back on conflict) — by which point its own overlap query sees the
 * first transaction's now-committed row.
 *
 * Overlap condition (the exact interval-overlap formula the R1E.2D
 * checkpoint specifies): an existing deployment (excluding the one being
 * edited, on update) conflicts with a proposed
 * `[$onSiteFrom, $offSiteAt]` range when
 * `existing.on_site_from <= $offSiteAt` (or the proposed range is
 * open-ended, treated as extending indefinitely) AND
 * `(existing.off_site_at IS NULL OR existing.off_site_at >= $onSiteFrom)`.
 */
class PlantDeploymentService
{
    public function create(PlantItem $plantItem, Project $project, User $actor, string $onSiteFrom, ?string $offSiteAt, ?string $notes): PlantDeployment
    {
        return DB::transaction(function () use ($plantItem, $project, $actor, $onSiteFrom, $offSiteAt, $notes) {
            $this->lockPlantItem($plantItem);
            $this->assertNoOverlap($plantItem, $onSiteFrom, $offSiteAt);

            return PlantDeployment::create([
                'organization_id' => $project->organization_id,
                'project_id'      => $project->id,
                'plant_item_id'   => $plantItem->id,
                'created_by'      => $actor->id,
                'on_site_from'    => $onSiteFrom,
                'off_site_at'     => $offSiteAt,
                'notes'           => $notes,
            ]);
        });
    }

    public function update(PlantDeployment $deployment, ?string $onSiteFrom, ?string $offSiteAt, bool $offSiteAtProvided, ?string $notes): PlantDeployment
    {
        return DB::transaction(function () use ($deployment, $onSiteFrom, $offSiteAt, $offSiteAtProvided, $notes) {
            $plantItem = PlantItem::findOrFail($deployment->plant_item_id);
            $this->lockPlantItem($plantItem);

            $newFrom = $onSiteFrom ?? $deployment->on_site_from->toDateString();
            $newTo   = $offSiteAtProvided ? $offSiteAt : optional($deployment->off_site_at)->toDateString();

            $this->assertNoOverlap($plantItem, $newFrom, $newTo, excludingDeploymentId: $deployment->id);

            $deployment->update([
                'on_site_from' => $newFrom,
                'off_site_at'  => $newTo,
                'notes'        => $notes ?? $deployment->notes,
            ]);

            return $deployment->fresh();
        });
    }

    private function lockPlantItem(PlantItem $plantItem): void
    {
        PlantItem::whereKey($plantItem->id)->lockForUpdate()->first();
    }

    /**
     * @throws PlantDeploymentOverlapException
     */
    private function assertNoOverlap(PlantItem $plantItem, string $onSiteFrom, ?string $offSiteAt, ?int $excludingDeploymentId = null): void
    {
        // An open-ended proposed range (no off_site_at) extends
        // indefinitely — represented here as a date far enough in the
        // future that it is never a real deployment boundary.
        $effectiveEnd = $offSiteAt ?? '9999-12-31';

        $query = PlantDeployment::where('plant_item_id', $plantItem->id)
            ->where('on_site_from', '<=', $effectiveEnd)
            ->where(function ($q) use ($onSiteFrom) {
                $q->whereNull('off_site_at')->orWhere('off_site_at', '>=', $onSiteFrom);
            });

        if ($excludingDeploymentId !== null) {
            $query->where('id', '!=', $excludingDeploymentId);
        }

        if ($query->exists()) {
            throw new PlantDeploymentOverlapException();
        }
    }
}
