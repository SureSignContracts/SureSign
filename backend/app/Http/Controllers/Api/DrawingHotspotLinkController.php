<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Drawing;
use App\Models\DrawingHotspot;
use App\Models\DrawingHotspotLink;
use App\Models\DrawingRevision;
use App\Models\Project;
use App\Services\Drawings\DrawingHotspotLinkService;
use App\Services\ProjectActivityService;
use App\Services\TradePackages\WorkspaceNavigationResolver;
use App\Support\Drawings\DrawingLinkableType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Drawing Phase 6B — Hotspot <-> construction record linking. Every
 * `type` value is validated against App\Support\Drawings\DrawingLinkableType
 * — never a raw client-supplied PHP class string.
 *
 * PRESENTATION CONTRACT: `linkable_type` is never cascade-cleaned when the
 * linked record is hard-deleted (see the creating migration's docblock) —
 * every method that resolves a link's target model must tolerate a null
 * result (record since deleted) and skip that row, never throw.
 */
class DrawingHotspotLinkController extends Controller
{
    private function authorize(Request $request, Project|Drawing $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) {
            return;
        }
        if ($user->organization_id !== $subject->organization_id) {
            abort(403, 'Access denied.');
        }
    }

    private function resolveHotspot(Request $request, Project $project, Drawing $drawing, DrawingRevision $revision, DrawingHotspot $hotspot): void
    {
        $this->authorize($request, $drawing);

        if ($drawing->project_id !== $project->id) {
            abort(404, 'Drawing not found for this project.');
        }
        if ($revision->drawing_id !== $drawing->id) {
            abort(404, 'Revision not found for this drawing.');
        }
        if ($hotspot->drawing_revision_id !== $revision->id) {
            abort(404, 'Location not found for this revision.');
        }
    }

    /**
     * A short display label built from each real model's own identifying
     * fields (Part V) — never a fabricated label. Mirrors the field names
     * confirmed on each model (Snag::$snag_number/$title,
     * Rfi::$rfi_number/$subject, QaReport::$report_number/$title,
     * Variation::$variation_number/$title).
     */
    private function labelFor(string $shortType, Model $record): string
    {
        $parts = match ($shortType) {
            DrawingLinkableType::SNAG => ['S-'.$record->snag_number, $record->title],
            DrawingLinkableType::RFI => ['RFI-'.$record->rfi_number, $record->subject],
            DrawingLinkableType::QA_REPORT => ['QA-'.$record->report_number, $record->title],
            DrawingLinkableType::VARIATION => ['V-'.$record->variation_number, $record->title],
            default => [null, null],
        };

        return trim(implode(' ', array_filter($parts, fn ($p) => filled($p))));
    }

    /**
     * Resolves the linked record for one link row, tolerating a since-
     * deleted target (Part AF) — returns null rather than throwing.
     */
    private function present(DrawingHotspotLink $link): ?array
    {
        $shortType = DrawingLinkableType::shortTypeFor($link->linkable_type);
        if (! $shortType) {
            return null;
        }

        $record = $link->linkable_type::find($link->linkable_id);
        if (! $record) {
            return null;
        }

        return [
            'id' => $link->id,
            'type' => $shortType,
            'type_label' => DrawingLinkableType::labelFor($shortType),
            'record_id' => $record->id,
            'label' => $this->labelFor($shortType, $record),
            'action_url' => WorkspaceNavigationResolver::actionUrl($record->project_id, $shortType, $record->id),
            'created_at' => $link->created_at,
        ];
    }

    public function index(Request $request, Project $project, Drawing $drawing, DrawingRevision $revision, DrawingHotspot $hotspot)
    {
        $this->resolveHotspot($request, $project, $drawing, $revision, $hotspot);

        $links = $hotspot->links()->orderBy('created_at')->get();
        $presented = $links->map(fn ($link) => $this->present($link))->filter()->values();

        return response()->json(['data' => $presented]);
    }

    /**
     * Part P/Q/R/S — allowlisted type, record must belong to the same
     * Project (and organisation) as the hotspot's own Drawing, duplicate
     * links rejected. Ownership/duplicate validation and link creation are
     * delegated to DrawingHotspotLinkService (Phase 7B1) — the exact same
     * rules a future Create + Auto-Link path uses, so the two never
     * diverge.
     *
     * Phase 7B1, Part 3 — deliberately NOT restricted to the current
     * revision. A hotspot on a historical DrawingRevision is still a real,
     * valid location; linking an operational record to it changes only
     * that relationship, never the historical Drawing/hotspot itself. This
     * is a distinct restriction from hotspot AUTHORING (add/edit/move/
     * delete a hotspot), which remains current-revision-only in
     * DrawingHotspotController — see that controller's
     * assertCurrentRevision().
     */
    public function store(Request $request, Project $project, Drawing $drawing, DrawingRevision $revision, DrawingHotspot $hotspot, DrawingHotspotLinkService $linkService)
    {
        $this->resolveHotspot($request, $project, $drawing, $revision, $hotspot);

        $validated = $request->validate([
            'type' => 'required|string',
            'record_id' => 'required|integer',
        ]);

        if (! DrawingLinkableType::isValid($validated['type'])) {
            throw ValidationException::withMessages(['type' => 'Unsupported record type.']);
        }

        $modelClass = DrawingLinkableType::modelFor($validated['type']);
        $record = $modelClass::find($validated['record_id']);

        if (! $record) {
            throw ValidationException::withMessages(['record_id' => 'The selected record could not be found for this project.']);
        }

        $link = $linkService->linkOrFail($hotspot, $validated['type'], $record, $request->user());

        return response()->json($this->present($link), 201);
    }

    /**
     * Phase 7B1, Part 3 — same relaxation as store() above: unlinking a
     * historical hotspot's link is intentionally supported. Deleting a
     * DrawingHotspotLink never deletes/affects the target record itself
     * (unchanged from Phase 6B).
     */
    public function destroy(Request $request, Project $project, Drawing $drawing, DrawingRevision $revision, DrawingHotspot $hotspot, DrawingHotspotLink $link)
    {
        $this->resolveHotspot($request, $project, $drawing, $revision, $hotspot);

        if ($link->drawing_hotspot_id !== $hotspot->id) {
            abort(404, 'Link not found for this location.');
        }

        ProjectActivityService::record(
            $project,
            $request->user(),
            'drawing_hotspot_record_unlinked',
            "Drawing location unlinked: {$drawing->drawing_number} — {$revision->revision_code}",
            null,
            $link
        );

        $link->delete();

        return response()->json(null, 204);
    }
}
