<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MeetingMinutes;
use App\Models\Project;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;

class MeetingMinutesController extends Controller
{
    public function index(Request $request, Project $project)
    {
        $meetings = MeetingMinutes::where('project_id', $project->id)
            ->with('creator:id,name')
            ->latest('meeting_date')
            ->paginate(25);

        return response()->json($meetings);
    }

    public function store(Request $request, Project $project)
    {
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
            'organization_id' => $request->user()->organization_id,
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

    public function show(MeetingMinutes $meeting)
    {
        return response()->json($meeting->load('creator:id,name'));
    }

    public function update(Request $request, MeetingMinutes $meeting)
    {
        $validated = $request->validate([
            'title'        => 'sometimes|string|max:255',
            'meeting_date' => 'sometimes|date',
            'location'     => 'nullable|string|max:255',
            'type'         => 'nullable|in:progress,design,commercial,safety,subcontractor,other',
            'attendees'    => 'nullable|array',
            'agenda'       => 'nullable|string',
            'minutes'      => 'nullable|string',
            'action_items' => 'nullable|array',
            'status'       => 'nullable|in:draft,issued,approved',
        ]);

        $meeting->update($validated);

        return response()->json($meeting);
    }

    public function destroy(MeetingMinutes $meeting)
    {
        $meeting->delete();
        return response()->json(null, 204);
    }
}
