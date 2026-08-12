<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Drawing;
use App\Models\DrawingHotspot;
use App\Models\DrawingRevision;
use App\Models\Project;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;

/**
 * Drawing Hotspot authoring (Phase 6A) — index()/store() were built read-only
 * in Phase 5; update()/destroy() and the current-revision-only authoring
 * restriction are added here. See DrawingHotspot's own docblock for the
 * ownership/coordinate/anchor conventions, which are unchanged.
 *
 * AUTHORING RESTRICTION (Part D): store()/update()/destroy() are only
 * permitted against a Drawing's CURRENT revision — a historical revision
 * (superseded by a later one) is entirely read-only, including for editing
 * or removing a hotspot that was legitimately created while it was current.
 * index() has no such restriction — historical hotspots always remain
 * visible.
 */
class DrawingHotspotController extends Controller
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

    /**
     * Resolves and authorizes the exact (project, drawing, revision) chain
     * — never accepts a revision merely because it exists somewhere in the
     * same project (Part I, "Drawing A -> Revision belonging to Drawing B"
     * must be rejected even within the same project).
     */
    private function resolveRevision(Request $request, Project $project, Drawing $drawing, DrawingRevision $revision): void
    {
        $this->authorize($request, $drawing);

        if ($drawing->project_id !== $project->id) {
            abort(404, 'Drawing not found for this project.');
        }
        if ($revision->drawing_id !== $drawing->id) {
            abort(404, 'Revision not found for this drawing.');
        }
    }

    /**
     * Resolves and authorizes the exact (project, drawing, revision, hotspot)
     * chain for a single-hotspot operation — same discipline as
     * resolveRevision(), extended one level further so a hotspot belonging
     * to a different revision (even of the same drawing) is rejected.
     */
    private function resolveHotspot(Request $request, Project $project, Drawing $drawing, DrawingRevision $revision, DrawingHotspot $hotspot): void
    {
        $this->resolveRevision($request, $project, $drawing, $revision);

        if ($hotspot->drawing_revision_id !== $revision->id) {
            abort(404, 'Location not found for this revision.');
        }
    }

    /** Part D — store()/update()/destroy() are current-revision-only. */
    private function assertCurrentRevision(Drawing $drawing, DrawingRevision $revision): void
    {
        if ($drawing->current_revision_id !== $revision->id) {
            abort(422, 'Drawing locations can only be added, edited, or removed on the current revision.');
        }
    }

    public function index(Request $request, Project $project, Drawing $drawing, DrawingRevision $revision)
    {
        $this->resolveRevision($request, $project, $drawing, $revision);

        $hotspots = $revision->hotspots()
            ->orderBy('page_number')
            ->orderBy('id')
            ->get(['id', 'drawing_revision_id', 'page_number', 'x', 'y', 'label', 'created_at']);

        return response()->json(['data' => $hotspots]);
    }

    public function store(Request $request, Project $project, Drawing $drawing, DrawingRevision $revision)
    {
        $this->resolveRevision($request, $project, $drawing, $revision);
        $this->assertCurrentRevision($drawing, $revision);

        $validated = $request->validate([
            'page_number' => 'required|integer|min:1',
            'x' => 'required|numeric|min:0|max:1',
            'y' => 'required|numeric|min:0|max:1',
            'label' => 'nullable|string|max:255',
        ]);

        $hotspot = DrawingHotspot::create(array_merge($validated, [
            'drawing_revision_id' => $revision->id,
            'created_by' => $request->user()->id,
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'drawing_hotspot_created',
            "Drawing location added: {$drawing->drawing_number} — {$revision->revision_code}",
            null,
            $hotspot
        );

        return response()->json($hotspot->only(['id', 'drawing_revision_id', 'page_number', 'x', 'y', 'label', 'created_at']), 201);
    }

    /**
     * Label edit and/or reposition (Part G/H). page_number is deliberately
     * never accepted here — reposition stays within the same page a hotspot
     * was created on; moving a location to a different page would need its
     * own explicit workflow, which this phase does not build (Part H).
     */
    public function update(Request $request, Project $project, Drawing $drawing, DrawingRevision $revision, DrawingHotspot $hotspot)
    {
        $this->resolveHotspot($request, $project, $drawing, $revision, $hotspot);
        $this->assertCurrentRevision($drawing, $revision);

        $validated = $request->validate([
            'label' => 'nullable|string|max:255',
            'x' => 'sometimes|required|numeric|min:0|max:1',
            'y' => 'sometimes|required|numeric|min:0|max:1',
        ]);

        // x/y are only ever sent together by the frontend's Move Location
        // flow — require both if either is present rather than allowing a
        // half-updated position.
        if ((isset($validated['x']) xor isset($validated['y']))) {
            abort(422, 'x and y must be provided together.');
        }

        $hotspot->update($validated);

        ProjectActivityService::record(
            $project,
            $request->user(),
            'drawing_hotspot_updated',
            "Drawing location updated: {$drawing->drawing_number} — {$revision->revision_code}",
            null,
            $hotspot
        );

        return response()->json($hotspot->fresh()->only(['id', 'drawing_revision_id', 'page_number', 'x', 'y', 'label', 'created_at']));
    }

    /**
     * Part AG — removing a hotspot with links removes the link rows too
     * (never the linked construction records themselves, which are never
     * touched by any code path here).
     */
    public function destroy(Request $request, Project $project, Drawing $drawing, DrawingRevision $revision, DrawingHotspot $hotspot)
    {
        $this->resolveHotspot($request, $project, $drawing, $revision, $hotspot);
        $this->assertCurrentRevision($drawing, $revision);

        $linkCount = $hotspot->links()->count();

        ProjectActivityService::record(
            $project,
            $request->user(),
            'drawing_hotspot_removed',
            "Drawing location removed: {$drawing->drawing_number} — {$revision->revision_code}",
            null,
            $hotspot,
            $linkCount > 0 ? ['removed_link_count' => $linkCount] : []
        );

        $hotspot->links()->delete();
        $hotspot->delete();

        return response()->json(null, 204);
    }
}
