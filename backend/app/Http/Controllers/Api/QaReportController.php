<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\QaReport;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;

class QaReportController extends Controller
{
    public function index(Request $request, Project $project)
    {
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

    public function store(Request $request, Project $project)
    {
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
        ]);

        $reportNumber = (QaReport::where('project_id', $project->id)->max('report_number') ?? 0) + 1;

        $report = QaReport::create(array_merge($validated, [
            'project_id'      => $project->id,
            'organization_id' => $request->user()->organization_id,
            'created_by'      => $request->user()->id,
            'report_number'   => $reportNumber,
            'status'          => $validated['status'] ?? 'draft',
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'qa_report_created',
            "QA Report #{$reportNumber} created: {$report->title}",
            null,
            $report
        );

        return response()->json($report->load(['creator:id,name', 'inspector:id,name']), 201);
    }

    public function show(QaReport $qaReport)
    {
        return response()->json($qaReport->load(['creator:id,name', 'inspector:id,name']));
    }

    public function update(Request $request, QaReport $qaReport)
    {
        $oldStatus = $qaReport->status;

        $validated = $request->validate([
            'title'              => 'sometimes|string|max:255',
            'inspection_type'    => 'nullable|string|max:100',
            'area'               => 'nullable|string|max:255',
            'inspected_by'       => 'nullable|integer|exists:users,id',
            'inspection_date'    => 'nullable|date',
            'status'             => 'nullable|in:draft,open,failed,passed,closed',
            'result'             => 'nullable|string|max:100',
            'observations'       => 'nullable|string',
            'corrective_action'  => 'nullable|string',
            'follow_up_required' => 'nullable|boolean',
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
        }

        return response()->json($qaReport->fresh()->load(['creator:id,name', 'inspector:id,name']));
    }

    public function destroy(QaReport $qaReport)
    {
        $qaReport->delete();
        return response()->json(null, 204);
    }
}
