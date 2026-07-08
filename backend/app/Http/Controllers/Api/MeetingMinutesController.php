<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MeetingMinutes;
use App\Models\Project;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;

class MeetingMinutesController extends Controller
{
    private function authorize(Request $request, Project|MeetingMinutes $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    public function index(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $meetings = MeetingMinutes::where('project_id', $project->id)
            ->with('creator:id,name')
            ->latest('meeting_date')
            ->paginate(25);

        return response()->json($meetings);
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $validated = $request->validate([
            'meeting_number' => 'nullable|integer',
            'title'          => 'required|string|max:255',
            'meeting_date'   => 'required|date',
            'location'       => 'nullable|string|max:255',
            'type'           => 'nullable|in:progress,design,commercial,safety,subcontractor,other',
            'attendees'      => 'nullable|array',
            'agenda'         => 'nullable|string',
            'minutes'        => 'nullable|string',
            'action_items'   => 'nullable|array',
            'status'         => 'nullable|in:draft,issued,approved',
        ]);

        $validated['meeting_number'] = $validated['meeting_number']
            ?? (MeetingMinutes::where('project_id', $project->id)->max('meeting_number') ?? 0) + 1;

        $meeting = MeetingMinutes::create(array_merge($validated, [
            'project_id'     => $project->id,
            'created_by'     => $request->user()->id,
            'organization_id' => $project->organization_id,
            'status'         => $validated['status'] ?? 'draft',
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'meeting_created',
            "Meeting #{$meeting->meeting_number} created: {$meeting->title}",
            null,
            $meeting
        );

        return response()->json($meeting, 201);
    }

    // These routes are NOT shallow (api/projects/{project}/meetings/{meeting})
    // — both route segments are typed model bindings, so Project $project
    // must be declared even though it's unused here, or Laravel's implicit
    // binding gets the positional args confused and passes the project's
    // string ID where $meeting (typed MeetingMinutes) is expected.
    public function show(Request $request, Project $project, MeetingMinutes $meeting)
    {
        $this->authorize($request, $meeting);

        return response()->json($meeting->load('creator:id,name'));
    }

    public function update(Request $request, Project $project, MeetingMinutes $meeting)
    {
        $this->authorize($request, $meeting);

        $validated = $request->validate([
            'title'        => 'sometimes|string|max:255',
            'meeting_date' => 'sometimes|date',
            'location'     => 'nullable|string|max:255',
            // type/status are NOT NULL columns (with defaults that only
            // apply when the column is omitted from an UPDATE, not when an
            // explicit NULL is sent) — 'sometimes' here means an omitted
            // field is left untouched instead of nulled out, matching
            // meeting_date/title above rather than the 'nullable' this used
            // to have, which crashed the update whenever the request didn't
            // include them.
            'type'         => 'sometimes|in:progress,design,commercial,safety,subcontractor,other',
            'attendees'    => 'nullable|array',
            'agenda'       => 'nullable|string',
            'minutes'      => 'nullable|string',
            'action_items' => 'nullable|array',
            'status'       => 'sometimes|in:draft,issued,approved',
        ]);

        $meeting->update($validated);

        return response()->json($meeting);
    }

    public function destroy(Request $request, Project $project, MeetingMinutes $meeting)
    {
        $this->authorize($request, $meeting);

        $meeting->delete();
        return response()->json(null, 204);
    }
}
