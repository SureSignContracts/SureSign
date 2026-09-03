<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FileUpload;
use App\Models\Project;
use App\Models\SiteInduction;
use App\Services\Documents\RecordAttachmentService;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;

/**
 * Friday Pack Realignment, R1E.2A — Site Inductions. Mirrors
 * ToolboxTalkController's exact structure (authorize()/
 * authorizeProjectX(), RecordAttachmentService for evidence,
 * ProjectActivityService for the activity trail). ONE ROW = ONE
 * INDUCTION SESSION — see SiteInduction model docblock.
 *
 * Route-model binding on `SiteInduction $siteInduction` naturally
 * respects its SoftDeletes global scope — a soft-deleted record is
 * simply not found (404) through any normal endpoint here, never
 * exposed via `show()`/`update()`/`attachments()`.
 */
class SiteInductionController extends Controller
{
    private function authorize(Request $request, Project|SiteInduction $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    /**
     * Re-derives the Site Induction's REAL parent project so a same-
     * organisation but mismatched project ID in the URL can't address a
     * session belonging to a different project — mirrors
     * ToolboxTalkController::authorizeProjectToolboxTalk() exactly.
     */
    private function authorizeProjectSiteInduction(Request $request, Project $project, SiteInduction $siteInduction): void
    {
        $this->authorize($request, $siteInduction);
        if ($siteInduction->project_id !== $project->id) {
            abort(404, 'Site induction not found for this project.');
        }
    }

    public function index(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $query = SiteInduction::where('project_id', $project->id)
            ->with('creator:id,name');

        if ($request->filled('from')) {
            $query->whereDate('induction_date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('induction_date', '<=', $request->to);
        }

        return response()->json($query->latest('induction_date')->paginate(25));
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $validated = $request->validate([
            'induction_date'    => 'required|date',
            'session_title'     => 'nullable|string|max:255',
            'company_or_trade'  => 'nullable|string|max:255',
            'inductee_count'    => 'required|integer|min:1',
            'notes'             => 'nullable|string',
        ]);

        $induction = SiteInduction::create(array_merge($validated, [
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $request->user()->id,
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'site_induction_added',
            'Site induction added for ' . $induction->induction_date->format('d M Y')
                . ($induction->company_or_trade ? " ({$induction->company_or_trade})" : ''),
            null,
            $induction
        );

        return response()->json($induction->load('creator:id,name'), 201);
    }

    // Not shallow (api/projects/{project}/site-inductions/{site_induction})
    // — both segments are typed model bindings, so Project $project must
    // be declared even though unused here, matching SiteDiaryController/
    // ToolboxTalkController's identical convention.
    public function show(Request $request, Project $project, SiteInduction $siteInduction)
    {
        $this->authorizeProjectSiteInduction($request, $project, $siteInduction);

        return response()->json($siteInduction->load('creator:id,name'));
    }

    public function update(Request $request, Project $project, SiteInduction $siteInduction)
    {
        $this->authorizeProjectSiteInduction($request, $project, $siteInduction);

        $validated = $request->validate([
            'induction_date'    => 'sometimes|date',
            'session_title'     => 'nullable|string|max:255',
            'company_or_trade'  => 'nullable|string|max:255',
            // NOT NULL column — 'sometimes' leaves it untouched if absent
            // from the request rather than nulling it out, same fix
            // already applied to Rfi/SiteDiary/QaReport/Snag/ToolboxTalk.
            'inductee_count'    => 'sometimes|integer|min:1',
            'notes'             => 'nullable|string',
        ]);

        $siteInduction->update($validated);

        ProjectActivityService::record(
            $project,
            $request->user(),
            'site_induction_updated',
            'Site induction updated for ' . $siteInduction->induction_date->format('d M Y'),
            null,
            $siteInduction
        );

        return response()->json($siteInduction->fresh()->load('creator:id,name'));
    }

    public function destroy(Request $request, Project $project, SiteInduction $siteInduction)
    {
        $this->authorizeProjectSiteInduction($request, $project, $siteInduction);

        $siteInduction->delete();

        ProjectActivityService::record(
            $project,
            $request->user(),
            'site_induction_deleted',
            'Site induction deleted for ' . $siteInduction->induction_date->format('d M Y'),
            null,
            $siteInduction
        );

        return response()->json(null, 204);
    }

    // ── Evidence attachments ─────────────────────────────────────────────
    // See App\Services\Documents\RecordAttachmentService — the same
    // shared service backs SiteDiary/ToolboxTalk/Snag/Rfi/QaReport's
    // identical methods. No new attachment mechanism.
    //
    // R1E.2A finding (deliberately scoped, not fixed platform-wide): every
    // existing caller of RecordAttachmentService::list()/upload() returns
    // the raw FileUpload model directly, which includes `disk`/`file_path`
    // — true of SiteDiary/ToolboxTalk/QaReport/Snag/Rfi today, not a
    // defect introduced here. This checkpoint's own requirement is that
    // Site Inductions specifically must never expose them, so this
    // controller redacts them locally via presentAttachment() rather than
    // silently broadening the phase into a platform-wide
    // RecordAttachmentService/FileUpload response-shape redesign.

    public function attachments(Request $request, Project $project, SiteInduction $siteInduction)
    {
        $this->authorizeProjectSiteInduction($request, $project, $siteInduction);

        return response()->json(
            (new RecordAttachmentService())->list($siteInduction)->map(fn (FileUpload $u) => $this->presentAttachment($u))
        );
    }

    public function uploadAttachment(Request $request, Project $project, SiteInduction $siteInduction)
    {
        $this->authorizeProjectSiteInduction($request, $project, $siteInduction);

        $upload = (new RecordAttachmentService())->upload(
            $request, $project, $siteInduction, $request->user(),
            'site_inductions', 'Site Induction: ' . $siteInduction->induction_date->format('d M Y'), 'site_induction_evidence_uploaded',
        );

        return response()->json($this->presentAttachment($upload), 201);
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

    public function deleteAttachment(Request $request, Project $project, SiteInduction $siteInduction, FileUpload $fileUpload)
    {
        $this->authorizeProjectSiteInduction($request, $project, $siteInduction);

        (new RecordAttachmentService())->delete(
            $fileUpload, $siteInduction, $project, $request->user(),
            'Site Induction: ' . $siteInduction->induction_date->format('d M Y'), 'site_induction_evidence_removed',
        );

        return response()->json(null, 204);
    }
}
