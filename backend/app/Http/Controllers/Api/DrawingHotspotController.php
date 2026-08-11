<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Drawing;
use App\Models\DrawingHotspot;
use App\Models\DrawingRevision;
use App\Models\Project;
use Illuminate\Http\Request;

/**
 * Drawing Phase 5 — read-only hotspot foundation. NO customer-facing
 * authoring UI consumes store() yet (see DrawingHotspot's own docblock for
 * the full ownership rule) — this exists so the real Viewer can render
 * real persisted rows, and so Phase 6 authoring has a proven, tested
 * creation path to build on rather than inventing a new one later.
 *
 * Deliberately no update()/destroy() — no removal/edit workflow exists in
 * this phase (mirrors DrawingRevisionController's own precedent of adding
 * only what the phase actually needs).
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

        return response()->json($hotspot->only(['id', 'drawing_revision_id', 'page_number', 'x', 'y', 'label', 'created_at']), 201);
    }
}
