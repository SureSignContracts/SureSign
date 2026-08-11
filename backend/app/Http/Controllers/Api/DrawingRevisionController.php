<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Drawing;
use App\Models\DrawingRevision;
use App\Models\Project;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Drawing Revision history (Phase 4) — the actual issued file per revision
 * of a Drawing (see App\Models\DrawingRevision's docblock). Uses the same
 * private authorize() pattern as DrawingController (Drawing/Project
 * organisation-scoped check) — no separate revision permission framework.
 */
class DrawingRevisionController extends Controller
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

    private function documentSummary(): array
    {
        return ['id', 'title', 'file_name', 'reference_number', 'category', 'type', 'mime_type'];
    }

    /**
     * Deterministic, most-recent-first order — never an attempt to sort
     * revision_code semantically (Part Y): worldwide revision codes are
     * not reliably numeric, so created_at/id is the only honest ordering.
     */
    public function index(Request $request, Project $project, Drawing $drawing)
    {
        $this->authorize($request, $drawing);

        $revisions = $drawing->revisions()
            ->with(['document:'.implode(',', $this->documentSummary()), 'creator:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $revisions, 'current_revision_id' => $drawing->current_revision_id]);
    }

    public function store(Request $request, Project $project, Drawing $drawing)
    {
        $this->authorize($request, $drawing);

        $validated = $request->validate([
            'document_id' => 'required|integer|exists:documents,id',
            'revision_code' => 'required|string|max:100',
            'status' => 'nullable|string|max:100',
            'issued_date' => 'nullable|date',
            'issued_by' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        // Same eligibility as eligibleRevisionDocuments() (Part L/C) —
        // same project, not soft-deleted (default scope), and re-verified
        // here rather than trusted from the selector response.
        $document = Document::where('id', $validated['document_id'])
            ->where('project_id', $project->id)
            ->first();

        if (! $document) {
            throw ValidationException::withMessages([
                'document_id' => 'The selected document could not be found for this project.',
            ]);
        }
        if ($document->organization_id !== $project->organization_id) {
            abort(403, 'Access denied.');
        }

        $revision = DB::transaction(function () use ($validated, $drawing, $document, $request) {
            $duplicate = DrawingRevision::where('drawing_id', $drawing->id)
                ->where('document_id', $document->id)
                ->lockForUpdate()
                ->first();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'document_id' => 'This document is already used by another revision of this drawing.',
                ]);
            }

            $revision = DrawingRevision::create(array_merge($validated, [
                'drawing_id' => $drawing->id,
                'created_by' => $request->user()->id,
            ]));

            // Every newly-added revision becomes current immediately (Phase
            // 4 Part H) — no separate "set as current" step exists in this
            // phase. The previous current revision's own `status` is
            // deliberately left untouched (Part I) — current/non-current is
            // fully expressed by current_revision_id alone.
            $drawing->update(['current_revision_id' => $revision->id]);

            return $revision;
        });

        ProjectActivityService::record(
            $project,
            $request->user(),
            'drawing_revision_added',
            "Drawing revision added: {$drawing->drawing_number} — {$revision->revision_code}",
            null,
            $revision
        );

        return response()->json($revision->load(['document:'.implode(',', $this->documentSummary()), 'creator:id,name']), 201);
    }

    public function show(Request $request, Project $project, Drawing $drawing, DrawingRevision $revision)
    {
        $this->authorize($request, $drawing);

        if ($revision->drawing_id !== $drawing->id) {
            abort(404, 'Revision not found for this drawing.');
        }

        return response()->json([
            'revision' => $revision->load(['document:'.implode(',', $this->documentSummary()), 'creator:id,name']),
            'drawing' => $drawing->only(['id', 'drawing_number', 'title', 'discipline', 'status', 'location_reference']),
            'is_current' => $drawing->current_revision_id === $revision->id,
        ]);
    }

    public function update(Request $request, Project $project, Drawing $drawing, DrawingRevision $revision)
    {
        $this->authorize($request, $drawing);

        if ($revision->drawing_id !== $drawing->id) {
            abort(404, 'Revision not found for this drawing.');
        }

        // Metadata only — drawing_id/document_id are immutable after
        // creation (Part U). A wrong file means adding a new, correct
        // revision, never rewriting which Document a historical revision
        // pointed to.
        $validated = $request->validate([
            'revision_code' => 'sometimes|string|max:100',
            'status' => 'nullable|string|max:100',
            'issued_date' => 'nullable|date',
            'issued_by' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $revision->update($validated);

        ProjectActivityService::record(
            $project,
            $request->user(),
            'drawing_revision_updated',
            "Drawing revision updated: {$drawing->drawing_number} — {$revision->revision_code}",
            null,
            $revision
        );

        return response()->json($revision->fresh()->load(['document:'.implode(',', $this->documentSummary()), 'creator:id,name']));
    }
}
