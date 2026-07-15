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
    /**
     * This controller previously had NO authorization checks at all on any
     * method — index/store/update/markComplete/destroy were fully open to
     * any authenticated user of any organisation, for statutory
     * adjudication deadlines. Fixed for every role, not just Client.
     */
    private function authorize(Request $request, AdjudicationCase|AdjudicationDeadline $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    /** Re-derives the case's REAL parent project (see MeetingMinutesController). */
    private function authorizeProjectCase(Request $request, Project $project, AdjudicationCase $adjudicationCase): void
    {
        $this->authorize($request, $adjudicationCase);
        if ($adjudicationCase->project_id !== $project->id) {
            abort(404, 'Adjudication case not found for this project.');
        }
    }

    /** Re-derives the deadline's REAL parent project. */
    private function authorizeProjectDeadline(Request $request, Project $project, AdjudicationDeadline $adjudicationDeadline): void
    {
        $this->authorize($request, $adjudicationDeadline);
        if ($adjudicationDeadline->project_id !== $project->id) {
            abort(404, 'Adjudication deadline not found for this project.');
        }
    }

    public function index(Request $request, Project $project, AdjudicationCase $adjudicationCase)
    {
        $this->authorizeProjectCase($request, $project, $adjudicationCase);

        return response()->json(
            $adjudicationCase->deadlines()->orderBy('due_date')->get()
        );
    }

    public function store(Request $request, Project $project, AdjudicationCase $adjudicationCase)
    {
        $this->authorizeProjectCase($request, $project, $adjudicationCase);

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
        $this->authorizeProjectDeadline($request, $project, $adjudicationDeadline);

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
        $this->authorizeProjectDeadline($request, $project, $adjudicationDeadline);

        $adjudicationDeadline->update([
            'status'       => 'completed',
            'completed_at' => now(),
        ]);

        return response()->json($adjudicationDeadline->fresh());
    }

    public function destroy(Request $request, Project $project, AdjudicationDeadline $adjudicationDeadline)
    {
        $this->authorizeProjectDeadline($request, $project, $adjudicationDeadline);

        $adjudicationDeadline->delete();
        return response()->json(null, 204);
    }
}
