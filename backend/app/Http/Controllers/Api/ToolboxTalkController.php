<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FileUpload;
use App\Models\Project;
use App\Models\ToolboxTalk;
use App\Services\Documents\RecordAttachmentService;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Toolbox Talks V1A — mirrors QaReportController's structure exactly
 * (authorize()/authorizeProjectX(), eligibility-scoped user reference,
 * RecordAttachmentService for evidence, ProjectActivityService for the
 * activity trail). See ToolboxTalk model docblock and the creating
 * migration for the reasoning behind fields not obvious from validation
 * alone.
 *
 * Deliberately NO outbound organisation notification (unlike SiteDiary/
 * QaReport/Snag) — a completed Toolbox Talk is routine, high-frequency
 * compliance evidence, not an actionable exception a team needs pushed
 * to them the way a failed QA check or a new Snag is. ActivityLog is the
 * complete audit trail for V1; add a notification later only if a real
 * product need for one emerges.
 */
class ToolboxTalkController extends Controller
{
    private function authorize(Request $request, Project|ToolboxTalk $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    /**
     * Re-derives the Toolbox Talk's REAL parent project so a same-
     * organisation but mismatched project ID in the URL can't address a
     * talk belonging to a different project — mirrors
     * SiteDiaryController::authorizeProjectSiteDiary()/
     * QaReportController::authorizeProjectQaReport() exactly.
     */
    private function authorizeProjectToolboxTalk(Request $request, Project $project, ToolboxTalk $toolboxTalk): void
    {
        $this->authorize($request, $toolboxTalk);
        if ($toolboxTalk->project_id !== $project->id) {
            abort(404, 'Toolbox talk not found for this project.');
        }
    }

    /**
     * Deliverer scoping — mirrors SnagController::eligibleAssigneeRule()/
     * QaReportController::eligibleInspectorRule() exactly (P3 Security
     * Remediation's evidence-based rule: same organisation, active, not
     * banned, not soft-deleted). A platform-wide Admin/Super Admin has
     * organization_id = NULL, so this rule — scoped to a specific
     * organisation — correctly excludes them from being stored as a
     * delivered_by_user_id, same as it already excludes them as an
     * assignee/inspector elsewhere. No existing project-record reference
     * field in this codebase treats platform operators as eligible
     * project-record actors, so this is not a new restriction.
     */
    private function eligibleDelivererRule(int $organizationId): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('users', 'id')->where(function ($query) use ($organizationId) {
            $query->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->whereNull('banned_at')
                ->whereNull('deleted_at');
        });
    }

    public function index(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $query = ToolboxTalk::where('project_id', $project->id)
            ->with(['creator:id,name', 'deliveredByUser:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('from')) {
            $query->whereDate('talk_date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('talk_date', '<=', $request->to);
        }

        return response()->json($query->latest('talk_date')->paginate(25));
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $validated = $request->validate([
            'title'                   => 'required|string|max:255',
            'talk_date'               => 'required|date',
            'started_at'              => 'nullable|date_format:H:i',
            'location'                => 'nullable|string|max:255',
            'delivered_by_user_id'    => ['nullable', 'integer', $this->eligibleDelivererRule($project->organization_id)],
            'delivered_by_name'       => 'nullable|string|max:255',
            'trade_or_subcontractor'  => 'nullable|string|max:255',
            'summary'                 => 'nullable|string',
            'attendee_count'          => 'required|integer|min:0',
            'status'                  => 'nullable|in:draft,submitted,approved',
        ]);

        $talk = ToolboxTalk::create(array_merge($validated, [
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $request->user()->id,
            'status'          => $validated['status'] ?? 'draft',
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'toolbox_talk_added',
            "Toolbox talk added: {$talk->title} (" . $talk->talk_date->format('d M Y') . ')',
            null,
            $talk
        );

        return response()->json($talk->load(['creator:id,name', 'deliveredByUser:id,name']), 201);
    }

    // Not shallow (api/projects/{project}/toolbox-talks/{toolbox_talk}) —
    // both segments are typed model bindings, so Project $project must be
    // declared even though unused here, matching SiteDiaryController/
    // QaReportController's identical convention.
    public function show(Request $request, Project $project, ToolboxTalk $toolboxTalk)
    {
        $this->authorizeProjectToolboxTalk($request, $project, $toolboxTalk);

        return response()->json($toolboxTalk->load(['creator:id,name', 'deliveredByUser:id,name']));
    }

    public function update(Request $request, Project $project, ToolboxTalk $toolboxTalk)
    {
        $this->authorizeProjectToolboxTalk($request, $project, $toolboxTalk);

        $oldStatus = $toolboxTalk->status;

        $validated = $request->validate([
            'title'                   => 'sometimes|string|max:255',
            'talk_date'               => 'sometimes|date',
            'started_at'              => 'nullable|date_format:H:i',
            'location'                => 'nullable|string|max:255',
            // Deliberately uses $toolboxTalk->organization_id, NOT
            // $project->organization_id — mirrors SnagController::update()/
            // QaReportController::update()'s identical reasoning: nothing
            // verifies the URL's {project} segment actually matches
            // $toolboxTalk->project_id, so only the already-persisted
            // organization_id on the record itself is authoritative.
            'delivered_by_user_id'    => ['nullable', 'integer', $this->eligibleDelivererRule($toolboxTalk->organization_id)],
            'delivered_by_name'       => 'nullable|string|max:255',
            'trade_or_subcontractor'  => 'nullable|string|max:255',
            'summary'                 => 'nullable|string',
            // NOT NULL columns — 'sometimes' leaves them untouched if
            // absent from the request rather than nulling them out, same
            // fix already applied to Rfi/SiteDiary/QaReport/Snag.
            'attendee_count'          => 'sometimes|integer|min:0',
            'status'                  => 'sometimes|in:draft,submitted,approved',
        ]);

        $toolboxTalk->update($validated);

        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            $talkProject = $toolboxTalk->project;

            ProjectActivityService::record(
                $talkProject,
                $request->user(),
                'toolbox_talk_updated',
                "Toolbox talk '{$toolboxTalk->title}' status changed to {$validated['status']}",
                null,
                $toolboxTalk
            );
        }

        return response()->json($toolboxTalk->fresh()->load(['creator:id,name', 'deliveredByUser:id,name']));
    }

    public function destroy(Request $request, Project $project, ToolboxTalk $toolboxTalk, \App\Services\FridayPack\FridayPackPhotoSelectionService $photoSelectionService)
    {
        $this->authorizeProjectToolboxTalk($request, $project, $toolboxTalk);
        // Post-Deploy Photo Hardening, P1 — a Toolbox Talk is a Friday
        // Pack photo-evidence source; block deletion while any of its
        // attachments is currently selected. See
        // FridayPackPhotoSelectionService::assertSourceRecordCanBeDeleted()'s
        // own docblock for the full reasoning.
        $photoSelectionService->assertSourceRecordCanBeDeleted($toolboxTalk);

        $toolboxTalk->delete();
        return response()->json(null, 204);
    }

    // ── Evidence attachments ─────────────────────────────────────────────
    // See App\Services\Documents\RecordAttachmentService — the same shared
    // service backs Snag/Rfi/QaReport's identical methods. No new
    // attachment mechanism.
    //
    // R1G.2 fix — every OLDER RecordAttachmentService caller (this one
    // included, until now) returns the raw FileUpload model directly,
    // exposing `disk`/`file_path` (a documented, deferred cross-cutting
    // cleanup — see SiteInductionController/HsInspectionController's own
    // identical comment). This controller is genuinely NEW in this same
    // Friday Pack/H&S Realignment batch, so per that batch's own release
    // gate it must not ship with this exposure — redacted locally via
    // presentAttachment(), the exact pattern already established by
    // SiteInductionController/HsInspectionController/PlantItemController/
    // StatutoryInspectionController. The pre-existing, genuinely older
    // Snag/Rfi/QaReport callers remain out of scope for this fix.

    public function attachments(Request $request, Project $project, ToolboxTalk $toolboxTalk)
    {
        $this->authorizeProjectToolboxTalk($request, $project, $toolboxTalk);

        return response()->json(
            (new RecordAttachmentService())->list($toolboxTalk)->map(fn (FileUpload $u) => $this->presentAttachment($u))
        );
    }

    public function uploadAttachment(Request $request, Project $project, ToolboxTalk $toolboxTalk)
    {
        $this->authorizeProjectToolboxTalk($request, $project, $toolboxTalk);

        $upload = (new RecordAttachmentService())->upload(
            $request, $project, $toolboxTalk, $request->user(),
            'toolbox_talks', "Toolbox Talk: {$toolboxTalk->title}", 'toolbox_talk_evidence_uploaded',
        );

        return response()->json($this->presentAttachment($upload), 201);
    }

    /** Never exposes disk/file_path/attachable_type FQCN — only fields the frontend genuinely needs. */
    private function presentAttachment(FileUpload $upload): array
    {
        return [
            'id'            => $upload->id,
            'original_name' => $upload->original_name,
            'mime_type'     => $upload->mime_type,
            'file_size'     => $upload->file_size,
            'uploaded_by'   => $upload->uploaded_by,
            'created_at'    => $upload->created_at,
        ];
    }

    public function deleteAttachment(Request $request, Project $project, ToolboxTalk $toolboxTalk, FileUpload $fileUpload)
    {
        $this->authorizeProjectToolboxTalk($request, $project, $toolboxTalk);

        (new RecordAttachmentService())->delete(
            $fileUpload, $toolboxTalk, $project, $request->user(),
            "Toolbox Talk: {$toolboxTalk->title}", 'toolbox_talk_evidence_removed',
        );

        return response()->json(null, 204);
    }
}
