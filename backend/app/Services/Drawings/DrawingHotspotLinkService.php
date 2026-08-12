<?php

namespace App\Services\Drawings;

use App\Models\DrawingHotspot;
use App\Models\DrawingHotspotLink;
use App\Models\User;
use App\Services\ProjectActivityService;
use App\Support\Drawings\DrawingLinkableType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Drawing Phase 7B1 — the sole place a DrawingHotspot <-> construction
 * record relationship is validated and created, extracted from
 * DrawingHotspotLinkController::store() so "Link Existing" and any future
 * "Create + Auto-Link" path never diverge on ownership/duplicate rules.
 *
 * Deliberately narrow: this validates and creates ONE relationship row. It
 * does not create/authorize the operational record itself (that stays each
 * module's own controller's job — see Part 13), does not touch Drawing/
 * DrawingRevision/DrawingHotspot authoring, and does not manage unlinking
 * (only one caller unlinks today — DrawingHotspotLinkController::destroy()
 * — so there is nothing to centralize there yet).
 *
 * Ownership is resolved from the HOTSPOT's own chain
 * (hotspot -> revision -> drawing -> project/organization), never from a
 * separately-supplied Project — this is what lets both the existing
 * project-scoped Link Existing endpoint and a future Snag/RFI/QA create
 * endpoint call the exact same validation without either one needing to
 * pass its own project context in.
 */
class DrawingHotspotLinkService
{
    /**
     * @throws ValidationException on an unsupported type, a record that
     *   doesn't belong to the hotspot's own Project/Organisation, or a
     *   duplicate relationship — never a raw framework exception, so every
     *   caller's existing error-handling convention (422 with field
     *   messages) keeps working unchanged.
     */
    public function linkOrFail(DrawingHotspot $hotspot, string $type, Model $record, User $actor): DrawingHotspotLink
    {
        if (! DrawingLinkableType::isValid($type)) {
            throw ValidationException::withMessages(['type' => 'Unsupported record type.']);
        }

        $modelClass = DrawingLinkableType::modelFor($type);
        if (! $record instanceof $modelClass) {
            // Defensive only — every real caller resolves $record via
            // $modelClass::find() itself, so this should be unreachable in
            // practice. Never trust a type string and a model instance to
            // agree just because the caller says so.
            throw ValidationException::withMessages(['type' => 'Unsupported record type.']);
        }

        $drawing = $hotspot->revision->drawing;

        if ((int) $record->project_id !== (int) $drawing->project_id) {
            throw ValidationException::withMessages(['record_id' => 'The selected record could not be found for this project.']);
        }
        if ((int) $record->organization_id !== (int) $drawing->organization_id) {
            // Deliberately the same generic message as the project mismatch
            // above, not a distinct "wrong organisation" message — see Part
            // 4's "do not expose cross-tenant information unnecessarily in
            // error responses".
            throw ValidationException::withMessages(['record_id' => 'The selected record could not be found for this project.']);
        }

        $link = DB::transaction(function () use ($hotspot, $modelClass, $record, $actor) {
            $duplicate = DrawingHotspotLink::where('drawing_hotspot_id', $hotspot->id)
                ->where('linkable_type', $modelClass)
                ->where('linkable_id', $record->id)
                ->lockForUpdate()
                ->first();

            if ($duplicate) {
                throw ValidationException::withMessages(['record_id' => 'This location is already linked to that record.']);
            }

            return DrawingHotspotLink::create([
                'drawing_hotspot_id' => $hotspot->id,
                'linkable_type' => $modelClass,
                'linkable_id' => $record->id,
                'created_by' => $actor->id,
            ]);
        });

        $this->recordActivity($drawing, $hotspot, $actor, $link);

        return $link;
    }

    /**
     * Same activity semantics as the pre-extraction controller code —
     * unchanged wording/event name. Takes the resolved Project directly
     * (via the hotspot's own Drawing) rather than requiring a caller to
     * pass one in separately.
     */
    private function recordActivity(\App\Models\Drawing $drawing, DrawingHotspot $hotspot, User $actor, DrawingHotspotLink $link): void
    {
        $revision = $hotspot->revision;
        $project = $drawing->project;
        if (! $project) {
            // Should be unreachable (a Drawing's project is never deleted
            // out from under it), but activity logging must never be what
            // turns a successful link into a 500.
            return;
        }

        ProjectActivityService::record(
            $project,
            $actor,
            'drawing_hotspot_record_linked',
            "Drawing location linked: {$drawing->drawing_number} — {$revision->revision_code}",
            null,
            $link
        );
    }
}
