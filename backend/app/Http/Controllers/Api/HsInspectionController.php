<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FileUpload;
use App\Models\HsInspection;
use App\Models\Project;
use App\Services\Documents\RecordAttachmentService;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Friday Pack Realignment, R1E.2C — H&S Inspections. Mirrors
 * SiteInductionController's structure (including its attachment
 * redaction pattern — see presentAttachment() below) with IncidentController's
 * separate status-change activity logging. Deliberately never reuses
 * QaReportController's pass/fail-shaped semantics — see App\Models\HsInspection's
 * own docblock.
 */
class HsInspectionController extends Controller
{
    private function authorize(Request $request, Project|HsInspection $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    /**
     * Re-derives the H&S Inspection's REAL parent project so a same-
     * organisation but mismatched project ID in the URL can't address an
     * inspection belonging to a different project — mirrors
     * SiteInductionController::authorizeProjectSiteInduction() exactly.
     */
    private function authorizeProjectHsInspection(Request $request, Project $project, HsInspection $hsInspection): void
    {
        $this->authorize($request, $hsInspection);
        if ($hsInspection->project_id !== $project->id) {
            abort(404, 'H&S inspection not found for this project.');
        }
    }

    public function index(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $query = HsInspection::where('project_id', $project->id)
            ->with('creator:id,name');

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

    public function store(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $validated = $request->validate([
            'inspection_date'  => 'required|date',
            'inspection_type'  => 'required|string|max:255',
            'inspected_by'     => 'required|string|max:255',
            'outcome'          => ['required', Rule::in(HsInspection::OUTCOMES)],
            'status'           => ['nullable', Rule::in(HsInspection::STATUSES)],
            'findings'         => 'nullable|string',
            'actions'          => 'nullable|string',
        ]);

        $inspection = HsInspection::create(array_merge($validated, [
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
            'hs_inspection_added',
            "{$inspection->inspection_type} recorded for " . $inspection->inspection_date->format('d M Y') . " ({$inspection->outcomeLabel()})",
            null,
            $inspection
        );

        return response()->json($inspection->load('creator:id,name'), 201);
    }

    // Not shallow (api/projects/{project}/hs-inspections/{hs_inspection})
    // — both segments are typed model bindings, so Project $project must
    // be declared even though unused here, matching SiteInductionController/
    // IncidentController's identical convention.
    public function show(Request $request, Project $project, HsInspection $hsInspection)
    {
        $this->authorizeProjectHsInspection($request, $project, $hsInspection);

        return response()->json($hsInspection->load('creator:id,name'));
    }

    public function update(Request $request, Project $project, HsInspection $hsInspection)
    {
        $this->authorizeProjectHsInspection($request, $project, $hsInspection);

        $oldStatus = $hsInspection->status;

        $validated = $request->validate([
            'inspection_date'  => 'sometimes|date',
            'inspection_type'  => 'sometimes|string|max:255',
            'inspected_by'     => 'sometimes|string|max:255',
            'outcome'          => ['sometimes', Rule::in(HsInspection::OUTCOMES)],
            'status'           => ['sometimes', Rule::in(HsInspection::STATUSES)],
            'findings'         => 'nullable|string',
            'actions'          => 'nullable|string',
        ]);

        $hsInspection->update($validated);

        ProjectActivityService::record(
            $project,
            $request->user(),
            'hs_inspection_updated',
            "{$hsInspection->inspection_type} updated for " . $hsInspection->inspection_date->format('d M Y'),
            null,
            $hsInspection
        );

        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            ProjectActivityService::record(
                $project,
                $request->user(),
                'hs_inspection_status_changed',
                "{$hsInspection->inspection_type} status changed from {$oldStatus} to {$validated['status']}",
                null,
                $hsInspection
            );
        }

        return response()->json($hsInspection->fresh()->load('creator:id,name'));
    }

    public function destroy(Request $request, Project $project, HsInspection $hsInspection)
    {
        $this->authorizeProjectHsInspection($request, $project, $hsInspection);

        $inspectionType = $hsInspection->inspection_type;
        $inspectionDate = $hsInspection->inspection_date->format('d M Y');

        $hsInspection->delete();

        ProjectActivityService::record(
            $project,
            $request->user(),
            'hs_inspection_deleted',
            "{$inspectionType} deleted for {$inspectionDate}",
            null,
            $hsInspection
        );

        return response()->json(null, 204);
    }

    // ── Evidence attachments ─────────────────────────────────────────────
    // See App\Services\Documents\RecordAttachmentService — the same
    // shared service backs SiteDiary/ToolboxTalk/SiteInduction/Snag/Rfi/
    // QaReport's identical methods. No new attachment mechanism.
    //
    // R1E.2A finding, deliberately NOT reproduced here (see
    // SiteInductionController's own identical comment): every OLDER
    // RecordAttachmentService caller returns the raw FileUpload model
    // directly, exposing `disk`/`file_path` — a documented, deferred
    // cross-cutting cleanup, not fixed platform-wide in this phase. This
    // NEW module redacts locally via presentAttachment(), same pattern
    // SiteInductionController already established.

    public function attachments(Request $request, Project $project, HsInspection $hsInspection)
    {
        $this->authorizeProjectHsInspection($request, $project, $hsInspection);

        return response()->json(
            (new RecordAttachmentService())->list($hsInspection)->map(fn (FileUpload $u) => $this->presentAttachment($u))
        );
    }

    public function uploadAttachment(Request $request, Project $project, HsInspection $hsInspection)
    {
        $this->authorizeProjectHsInspection($request, $project, $hsInspection);

        $upload = (new RecordAttachmentService())->upload(
            $request, $project, $hsInspection, $request->user(),
            'hs_inspections', "H&S Inspection: {$hsInspection->inspection_type} (" . $hsInspection->inspection_date->format('d M Y') . ')', 'hs_inspection_evidence_uploaded',
        );

        return response()->json($this->presentAttachment($upload), 201);
    }

    public function deleteAttachment(Request $request, Project $project, HsInspection $hsInspection, FileUpload $fileUpload)
    {
        $this->authorizeProjectHsInspection($request, $project, $hsInspection);

        (new RecordAttachmentService())->delete(
            $fileUpload, $hsInspection, $project, $request->user(),
            "H&S Inspection: {$hsInspection->inspection_type} (" . $hsInspection->inspection_date->format('d M Y') . ')', 'hs_inspection_evidence_removed',
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
