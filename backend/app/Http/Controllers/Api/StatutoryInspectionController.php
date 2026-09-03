<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FileUpload;
use App\Models\Project;
use App\Models\StatutoryInspection;
use App\Services\Documents\RecordAttachmentService;
use App\Services\Plant\PlantLinkResolver;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Friday Pack Realignment, R1E.2E — Statutory Inspections. Mirrors
 * HsInspectionController's structure (including its status-change
 * activity logging and attachment redaction pattern). The one addition
 * over HsInspectionController is the optional Plant & Equipment link —
 * see App\Services\Plant\PlantLinkResolver, the one place a
 * client-supplied `plant_item_id` is re-resolved and validated, never
 * trusted directly.
 */
class StatutoryInspectionController extends Controller
{
    private function authorize(Request $request, Project|StatutoryInspection $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    /**
     * Re-derives the Statutory Inspection's REAL parent project so a
     * same-organisation but mismatched project ID in the URL can't
     * address an inspection belonging to a different project — mirrors
     * HsInspectionController::authorizeProjectHsInspection() exactly.
     */
    private function authorizeProjectStatutoryInspection(Request $request, Project $project, StatutoryInspection $inspection): void
    {
        $this->authorize($request, $inspection);
        if ($inspection->project_id !== $project->id) {
            abort(404, 'Statutory inspection not found for this project.');
        }
    }

    public function index(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $query = StatutoryInspection::where('project_id', $project->id)
            ->with(['creator:id,name', 'plantItem:id,name,identifier']);

        if ($request->filled('outcome')) {
            $query->where('outcome', $request->outcome);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('from')) {
            $query->whereDate('inspection_date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('inspection_date', '<=', $request->to);
        }

        return response()->json($query->latest('inspection_date')->paginate(25));
    }

    public function store(Request $request, Project $project, PlantLinkResolver $plantLinkResolver)
    {
        $this->authorize($request, $project);

        $validated = $request->validate([
            'inspection_date'       => 'required|date',
            'inspection_type'       => 'required|string|max:255',
            'subject_description'   => 'required|string|max:255',
            'plant_item_id'         => 'nullable|integer',
            'reference'             => 'nullable|string|max:255',
            'outcome'               => ['required', Rule::in(StatutoryInspection::OUTCOMES)],
            'status'                => ['nullable', Rule::in(StatutoryInspection::STATUSES)],
            'notes'                 => 'nullable|string',
            'next_due_date'         => 'nullable|date',
        ]);

        // Re-resolved and validated server-side — never trust the
        // client-supplied id directly. Only ever resolves an ACTIVE
        // (non-soft-deleted) same-project/same-org PlantItem for a new
        // link — see PlantLinkResolver's own docblock.
        $plantLinkResolver->resolve($validated['plant_item_id'] ?? null, $project);

        $inspection = StatutoryInspection::create(array_merge($validated, [
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $request->user()->id,
            // The user controls lifecycle — never auto-derived from
            // outcome (issues_found does NOT imply open; satisfactory
            // does NOT imply closed).
            'status'          => $validated['status'] ?? 'open',
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'statutory_inspection_added',
            "{$inspection->inspection_type} recorded for {$inspection->subject_description} on " . $inspection->inspection_date->format('d M Y') . " ({$inspection->outcomeLabel()})",
            null,
            $inspection
        );

        return response()->json($inspection->load(['creator:id,name', 'plantItem:id,name,identifier']), 201);
    }

    // Not shallow (api/projects/{project}/statutory-inspections/{statutory_inspection})
    // — both segments are typed model bindings, so Project $project must
    // be declared even though unused here, matching HsInspectionController/
    // SiteInductionController's identical convention.
    public function show(Request $request, Project $project, StatutoryInspection $statutoryInspection)
    {
        $this->authorizeProjectStatutoryInspection($request, $project, $statutoryInspection);

        return response()->json($statutoryInspection->load(['creator:id,name', 'plantItem:id,name,identifier']));
    }

    public function update(Request $request, Project $project, StatutoryInspection $statutoryInspection, PlantLinkResolver $plantLinkResolver)
    {
        $this->authorizeProjectStatutoryInspection($request, $project, $statutoryInspection);

        $oldStatus = $statutoryInspection->status;

        $validated = $request->validate([
            'inspection_date'       => 'sometimes|date',
            'inspection_type'       => 'sometimes|string|max:255',
            'subject_description'   => 'sometimes|string|max:255',
            'plant_item_id'         => 'sometimes|nullable|integer',
            'reference'             => 'nullable|string|max:255',
            'outcome'               => ['sometimes', Rule::in(StatutoryInspection::OUTCOMES)],
            'status'                => ['sometimes', Rule::in(StatutoryInspection::STATUSES)],
            'notes'                 => 'nullable|string',
            'next_due_date'         => 'nullable|date',
        ]);

        // Only re-validated when the request genuinely supplies a
        // plant_item_id key — leaving it unset never re-checks (and
        // never disturbs) an existing link, including one that now
        // points to a since-soft-deleted PlantItem.
        if (array_key_exists('plant_item_id', $validated)) {
            $plantLinkResolver->resolve($validated['plant_item_id'], $project);
        }

        // project_id/organization_id are never in $validated at all —
        // an inspection can never be moved to another Project via update.
        $statutoryInspection->update($validated);

        ProjectActivityService::record(
            $project,
            $request->user(),
            'statutory_inspection_updated',
            "{$statutoryInspection->inspection_type} updated for " . $statutoryInspection->inspection_date->format('d M Y'),
            null,
            $statutoryInspection
        );

        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            ProjectActivityService::record(
                $project,
                $request->user(),
                'statutory_inspection_status_changed',
                "{$statutoryInspection->inspection_type} status changed from {$oldStatus} to {$validated['status']}",
                null,
                $statutoryInspection
            );
        }

        return response()->json($statutoryInspection->fresh()->load(['creator:id,name', 'plantItem:id,name,identifier']));
    }

    public function destroy(Request $request, Project $project, StatutoryInspection $statutoryInspection)
    {
        $this->authorizeProjectStatutoryInspection($request, $project, $statutoryInspection);

        $inspectionType = $statutoryInspection->inspection_type;
        $inspectionDate = $statutoryInspection->inspection_date->format('d M Y');

        $statutoryInspection->delete();

        ProjectActivityService::record(
            $project,
            $request->user(),
            'statutory_inspection_deleted',
            "{$inspectionType} deleted for {$inspectionDate}",
            null,
            $statutoryInspection
        );

        return response()->json(null, 204);
    }

    // ── Evidence attachments ─────────────────────────────────────────────
    // See App\Services\Documents\RecordAttachmentService — the same
    // shared service backs SiteInduction/HsInspection/PlantItem's
    // identical methods. No new attachment mechanism, and never a new
    // Document/certificate table — DeliveryDocument may independently
    // track compliance documents, but it is not the inspection event.
    //
    // Deliberately NOT reproducing the older RecordAttachmentService
    // callers' raw-path exposure (SiteDiary/ToolboxTalk/QaReport/Snag/
    // Rfi) — a documented, deferred cross-cutting cleanup. This module
    // redacts locally via presentAttachment(), same pattern
    // SiteInductionController/HsInspectionController/PlantItemController
    // already established.

    public function attachments(Request $request, Project $project, StatutoryInspection $statutoryInspection)
    {
        $this->authorizeProjectStatutoryInspection($request, $project, $statutoryInspection);

        return response()->json(
            (new RecordAttachmentService())->list($statutoryInspection)->map(fn (FileUpload $u) => $this->presentAttachment($u))
        );
    }

    public function uploadAttachment(Request $request, Project $project, StatutoryInspection $statutoryInspection)
    {
        $this->authorizeProjectStatutoryInspection($request, $project, $statutoryInspection);

        $upload = (new RecordAttachmentService())->upload(
            $request, $project, $statutoryInspection, $request->user(),
            'statutory_inspections', "Statutory Inspection: {$statutoryInspection->inspection_type} (" . $statutoryInspection->inspection_date->format('d M Y') . ')', 'statutory_inspection_evidence_uploaded',
        );

        return response()->json($this->presentAttachment($upload), 201);
    }

    public function deleteAttachment(Request $request, Project $project, StatutoryInspection $statutoryInspection, FileUpload $fileUpload)
    {
        $this->authorizeProjectStatutoryInspection($request, $project, $statutoryInspection);

        (new RecordAttachmentService())->delete(
            $fileUpload, $statutoryInspection, $project, $request->user(),
            "Statutory Inspection: {$statutoryInspection->inspection_type} (" . $statutoryInspection->inspection_date->format('d M Y') . ')', 'statutory_inspection_evidence_removed',
        );

        return response()->json(null, 204);
    }

    /** Never exposes disk/file_path/attachable_type FQCN — only fields the frontend genuinely needs. */
    private function presentAttachment(FileUpload $upload): array
    {
        return [
            'id'                => $upload->id,
            'original_name'     => $upload->original_name,
            'mime_type'         => $upload->mime_type,
            'file_size'         => $upload->file_size,
            'uploaded_by'       => $upload->uploaded_by,
            'created_at'        => $upload->created_at,
        ];
    }
}
