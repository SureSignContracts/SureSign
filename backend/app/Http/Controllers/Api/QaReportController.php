<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DrawingHotspot;
use App\Models\FileUpload;
use App\Models\Project;
use App\Models\QaReport;
use App\Models\SuresignNotification;
use App\Services\Documents\RecordAttachmentService;
use App\Services\Drawings\DrawingHotspotLinkService;
use App\Services\NotificationService;
use App\Services\ProjectActivityService;
use App\Services\TradePackages\WorkspaceNavigationResolver;
use App\Support\Drawings\DrawingLinkableType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QaReportController extends Controller
{
    private function authorize(Request $request, Project|QaReport $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    public function index(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $query = QaReport::where('project_id', $project->id)
            ->with(['creator:id,name', 'inspector:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('inspection_type', 'like', "%{$search}%")
                  ->orWhere('area', 'like', "%{$search}%");
            });
        }

        return response()->json($query->latest()->paginate(50));
    }

    public function store(Request $request, Project $project, DrawingHotspotLinkService $linkService)
    {
        $this->authorize($request, $project);

        $validated = $request->validate([
            'title'              => 'required|string|max:255',
            'inspection_type'    => 'nullable|string|max:100',
            'area'               => 'nullable|string|max:255',
            'inspected_by'       => 'nullable|integer|exists:users,id',
            'inspection_date'    => 'nullable|date',
            'status'             => 'nullable|in:draft,open,failed,passed,closed',
            'result'             => 'nullable|string|max:100',
            'observations'       => 'nullable|string',
            'corrective_action'  => 'nullable|string',
            'follow_up_required' => 'nullable|boolean',
            // Drawing Phase 7B1 — optional; see SnagController::store()'s
            // identical field for the full rationale.
            'drawing_hotspot_id' => 'nullable|integer|exists:drawing_hotspots,id',
        ]);

        // Record creation + optional hotspot link are atomic; notifyQaReport
        // (below) is deliberately outside this closure so it only ever
        // fires for a report that actually survived the transaction.
        $report = DB::transaction(function () use ($request, $project, $validated, $linkService) {
            $reportNumber = (QaReport::where('project_id', $project->id)->max('report_number') ?? 0) + 1;

            $report = QaReport::create(array_merge(
                collect($validated)->except('drawing_hotspot_id')->all(),
                [
                    'project_id'      => $project->id,
                    'organization_id' => $project->organization_id,
                    'created_by'      => $request->user()->id,
                    'report_number'   => $reportNumber,
                    'status'          => $validated['status'] ?? 'draft',
                    'follow_up_required' => $validated['follow_up_required'] ?? false,
                ]
            ));

            ProjectActivityService::record(
                $project,
                $request->user(),
                'qa_report_created',
                "QA Report #{$reportNumber} created: {$report->title}",
                null,
                $report
            );

            if (! empty($validated['drawing_hotspot_id'])) {
                $hotspot = DrawingHotspot::find($validated['drawing_hotspot_id']);
                if (! $hotspot) {
                    throw ValidationException::withMessages([
                        'drawing_hotspot_id' => 'The selected drawing location could not be found.',
                    ]);
                }
                $linkService->linkOrFail($hotspot, DrawingLinkableType::QA_REPORT, $report, $request->user());
            }

            return $report;
        });

        // Only reached once the transaction above has committed.
        $this->notifyQaReport($request, $project, $report, 'created', 'created', $report->title);

        return response()->json($report->load(['creator:id,name', 'inspector:id,name']), 201);
    }

    // Not shallow (api/projects/{project}/qa-reports/{qa_report}) — both
    // segments are typed model bindings, so Project $project must be
    // declared even though unused here, matching the same fix already
    // applied to MeetingMinutesController/SiteDiaryController/etc.
    public function show(Request $request, Project $project, QaReport $qaReport)
    {
        $this->authorize($request, $qaReport);

        return response()->json($qaReport->load(['creator:id,name', 'inspector:id,name']));
    }

    public function update(Request $request, Project $project, QaReport $qaReport)
    {
        $this->authorize($request, $qaReport);

        $oldStatus = $qaReport->status;

        $validated = $request->validate([
            'title'              => 'sometimes|string|max:255',
            'inspection_type'    => 'nullable|string|max:100',
            'area'               => 'nullable|string|max:255',
            'inspected_by'       => 'nullable|integer|exists:users,id',
            'inspection_date'    => 'nullable|date',
            // status/follow_up_required are NOT NULL columns whose DB
            // defaults only apply when the column is omitted from an
            // UPDATE, not when explicit NULL is sent — 'sometimes' leaves
            // them untouched if absent from the request instead of nulling
            // them out (same fix already applied to Rfi/SiteDiary/Meeting).
            'status'             => 'sometimes|in:draft,open,failed,passed,closed',
            'result'             => 'nullable|string|max:100',
            'observations'       => 'nullable|string',
            'corrective_action'  => 'nullable|string',
            'follow_up_required' => 'sometimes|boolean',
        ]);

        $qaReport->update($validated);

        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            $project = $qaReport->project;
            ProjectActivityService::record(
                $project,
                $request->user(),
                'qa_report_updated',
                "QA Report #{$qaReport->report_number} status changed to {$validated['status']}",
                null,
                $qaReport
            );

            // "Important transitions only" per the approved channel policy —
            // failed/passed/closed are inspection outcomes stakeholders act
            // on; draft -> open is routine workflow initiation.
            if (in_array($validated['status'], ['failed', 'passed', 'closed'], true)) {
                $this->notifyQaReport(
                    $request, $project, $qaReport, 'status_changed',
                    "from_{$oldStatus}_to_{$qaReport->status}_" . $qaReport->updated_at->timestamp,
                    ucfirst("{$qaReport->status}.")
                );
            }
        }

        return response()->json($qaReport->fresh()->load(['creator:id,name', 'inspector:id,name']));
    }

    public function destroy(Request $request, Project $project, QaReport $qaReport)
    {
        $this->authorize($request, $qaReport);

        $qaReport->delete();
        return response()->json(null, 204);
    }

    // ── Evidence attachments (Phase 0) ───────────────────────────────────
    // See App\Services\Documents\RecordAttachmentService — the same shared
    // service backs Snag/Rfi's identical methods.

    public function attachments(Request $request, Project $project, QaReport $qaReport)
    {
        $this->authorize($request, $qaReport);

        return response()->json(
            (new RecordAttachmentService())->list($qaReport)
        );
    }

    public function uploadAttachment(Request $request, Project $project, QaReport $qaReport)
    {
        $this->authorize($request, $qaReport);

        $upload = (new RecordAttachmentService())->upload(
            $request, $project, $qaReport, $request->user(),
            'qa', "QA Report #{$qaReport->report_number}", 'qa_report_evidence_uploaded',
        );

        return response()->json($upload, 201);
    }

    public function deleteAttachment(Request $request, Project $project, QaReport $qaReport, FileUpload $fileUpload)
    {
        $this->authorize($request, $qaReport);

        (new RecordAttachmentService())->delete(
            $fileUpload, $qaReport, $project, $request->user(),
            "QA Report #{$qaReport->report_number}", 'qa_report_evidence_removed',
        );

        return response()->json(null, 204);
    }

    private function notifyQaReport(Request $request, Project $project, QaReport $qaReport, string $kind, string $sourceField, string $message): void
    {
        $title = match (true) {
            $kind === 'created'                          => "QA Report #{$qaReport->report_number} Logged",
            $qaReport->status === 'failed'                => "QA Report #{$qaReport->report_number} Failed",
            $qaReport->status === 'passed'                => "QA Report #{$qaReport->report_number} Passed",
            $qaReport->status === 'closed'                => "QA Report #{$qaReport->report_number} Closed",
            default                                        => "QA Report #{$qaReport->report_number} Status Changed",
        };

        NotificationService::sendToOrganization(
            $project->organization,
            'qa_report_' . $kind,
            $title,
            $message,
            [],
            [
                'project_id' => $project->id, 'organization_id' => $project->organization_id,
                'category' => SuresignNotification::CATEGORY_COMPLIANCE, 'priority' => SuresignNotification::PRIORITY_INFO,
                'source_type' => 'qa_report', 'source_id' => $qaReport->id, 'source_field' => $sourceField,
                'action_url' => WorkspaceNavigationResolver::actionUrl($project->id, 'qa_report', $qaReport->id),
            ],
            $request->user(),
        );
    }
}
