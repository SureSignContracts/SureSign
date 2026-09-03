<?php

namespace App\Services\Plant;

use App\Models\PlantItem;
use App\Models\Project;

/**
 * Friday Pack Realignment, R1E.2E — Statutory Inspections. The ONE place
 * a client-supplied `plant_item_id` is re-resolved and validated against
 * the authoritative Project — mirrors CLAUDE.md's own established
 * Project-owned-foreign-key discipline (same-Project validation, never
 * organization_id-only scoping, a deliberately generic 422 message that
 * never names the foreign organisation/project). Never trusts a
 * client-supplied `plant_item_id` blindly, and never reveals whether a
 * foreign/nonexistent id exists elsewhere on the platform.
 *
 * Deliberately excludes a soft-deleted PlantItem — a NEW link may only
 * ever be made to a currently active item; StatutoryInspectionController
 * only calls this when a request genuinely supplies `plant_item_id`, so
 * editing an already-linked record's OTHER fields (leaving its existing
 * link to a since-soft-deleted PlantItem untouched) never re-runs this
 * check at all — see StatutoryInspection::plantItem()'s own docblock for
 * why that existing historical link still resolves and displays
 * correctly regardless.
 */
class PlantLinkResolver
{
    public function resolve(?int $plantItemId, Project $project): ?PlantItem
    {
        if ($plantItemId === null) {
            return null;
        }

        $plantItem = PlantItem::where('id', $plantItemId)
            ->where('project_id', $project->id)
            ->where('organization_id', $project->organization_id)
            ->first();

        abort_unless($plantItem !== null, 422, 'Plant item does not belong to this project.');

        return $plantItem;
    }
}
