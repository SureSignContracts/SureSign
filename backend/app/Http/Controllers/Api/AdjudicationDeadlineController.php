<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdjudicationCase;
use App\Models\AdjudicationDeadline;
use App\Models\Project;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;

class AdjudicationDeadlineController extends Controller
{
    public function index(Project $project, AdjudicationCase $adjudicationCase)
    {
        return response()->json(
            $adjudicationCase->deadlines()->orderBy('due_date')->get()
        );
    }

    public function store(Request $request, Project $project, AdjudicationCase $adjudicationCase)
    {
        $validated = $request->validate([
            'title'         => 'required|string|max:255',
            'description'   => 'nullable|string',
            'deadline_type' => 'required|in:notice_deadline,referral_deadline,response_deadline,decision_deadline,enforcement_deadline,custom',
            'due_date'      => 'required|date',
            'status'        => 'nullable|in:upcoming,due_soon,overdue,completed',
        ]);

        $deadline = AdjudicationDeadline::create(array_merge($validated, [
            'organization_id'       => $adjudicationCase->organization_id,
            'project_id'            => $adjudicationCase->project_id,
            'adjudication_case_id'  => $adjudicationCase->id,
            'status'                => $validated['status'] ?? 'upcoming',
        ]));

        return response()->json($deadline, 201);
    }

    public function update(Request $request, Project $project, AdjudicationDeadline $adjudicationDeadline)
    {
        $validated = $request->validate([
            'title'         => 'sometimes|string|max:255',
            'description'   => 'nullable|string',
            'deadline_type' => 'sometimes|in:notice_deadline,referral_deadline,response_deadline,decision_deadline,enforcement_deadline,custom',
            'due_date'      => 'sometimes|date',
            'status'        => 'nullable|in:upcoming,due_soon,overdue,completed',
        ]);

        $adjudicationDeadline->update($validated);
        return response()->json($adjudicationDeadline->fresh());
    }

    public function markComplete(Request $request, Project $project, AdjudicationDeadline $adjudicationDeadline)
    {
        $adjudicationDeadline->update([
            'status'       => 'completed',
            'completed_at' => now(),
        ]);

        return response()->json($adjudicationDeadline->fresh());
    }

    public function destroy(Project $project, AdjudicationDeadline $adjudicationDeadline)
    {
        $adjudicationDeadline->delete();
        return response()->json(null, 204);
    }
}
