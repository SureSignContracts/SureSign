<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MeetingMinutes;
use App\Models\Project;
use App\Models\SuresignNotification;
use App\Services\NotificationService;
use App\Services\ProjectActivityService;
use App\Services\TradePackages\WorkspaceNavigationResolver;
use Illuminate\Http\Request;

class MeetingMinutesController extends Controller
{
    private function authorize(Request $request, Project|MeetingMinutes $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    /**
     * The route always carries both {project} and {meeting} even though
     * only the meeting's own organisation is authorisation-relevant — this
     * re-derives the meeting's REAL parent so a same-organisation but
     * mismatched project ID in the URL can't address a meeting that
     * actually belongs to a different project.
     */
    private function authorizeProjectMeeting(Request $request, Project $project, MeetingMinutes $meeting): void
    {
        $this->authorize($request, $meeting);
        if ($meeting->project_id !== $project->id) {
            abort(404, 'Meeting not found for this project.');
        }
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

        $this->notifyMeeting($request, $project, $meeting, 'created', 'created', $meeting->title);

        return response()->json($meeting, 201);
    }

    // These routes are NOT shallow (api/projects/{project}/meetings/{meeting})
    // — both route segments are typed model bindings, so Project $project
    // must be declared even though it's unused here, or Laravel's implicit
    // binding gets the positional args confused and passes the project's
    // string ID where $meeting (typed MeetingMinutes) is expected.
    public function show(Request $request, Project $project, MeetingMinutes $meeting)
    {
        $this->authorizeProjectMeeting($request, $project, $meeting);

        return response()->json($meeting->load('creator:id,name'));
    }

    public function update(Request $request, Project $project, MeetingMinutes $meeting)
    {
        $this->authorizeProjectMeeting($request, $project, $meeting);

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

        $previousDate   = $meeting->meeting_date;
        $previousStatus = $meeting->status;
        $meeting->update($validated);

        // "Meaningful" per the approved channel policy = a reschedule (date
        // changed) or a lifecycle milestone (issued = minutes published,
        // approved = signed off). Editing location/agenda/attendees/action
        // items alone stays silent.
        $rescheduled  = isset($validated['meeting_date']) && (string) $meeting->meeting_date !== (string) $previousDate;
        $statusMoved  = isset($validated['status']) && $validated['status'] !== $previousStatus;

        if ($rescheduled) {
            $this->notifyMeeting(
                $request, $project, $meeting, 'rescheduled',
                'rescheduled_' . $meeting->updated_at->timestamp,
                'Now on ' . \Carbon\Carbon::parse($meeting->meeting_date)->format('d M Y') . '.'
            );
        }

        if ($statusMoved) {
            $label = $meeting->status === 'issued' ? 'Minutes published.' : ucfirst("{$meeting->status}.");
            $this->notifyMeeting(
                $request, $project, $meeting, 'status_changed',
                "from_{$previousStatus}_to_{$meeting->status}_" . $meeting->updated_at->timestamp,
                $label
            );
        }

        return response()->json($meeting);
    }

    public function destroy(Request $request, Project $project, MeetingMinutes $meeting)
    {
        $this->authorizeProjectMeeting($request, $project, $meeting);

        $meeting->delete();
        return response()->json(null, 204);
    }

    private function notifyMeeting(Request $request, Project $project, MeetingMinutes $meeting, string $kind, string $sourceField, string $message): void
    {
        $title = match (true) {
            $kind === 'created'                                => "Meeting #{$meeting->meeting_number} Scheduled",
            $kind === 'rescheduled'                             => "Meeting #{$meeting->meeting_number} Rescheduled",
            $kind === 'status_changed' && $meeting->status === 'issued'   => "Meeting #{$meeting->meeting_number} Minutes Published",
            $kind === 'status_changed' && $meeting->status === 'approved' => "Meeting #{$meeting->meeting_number} Approved",
            default                                             => "Meeting #{$meeting->meeting_number} Reopened as Draft",
        };

        NotificationService::sendToOrganization(
            $project->organization,
            'meeting_' . $kind,
            $title,
            $message,
            [],
            [
                'project_id' => $project->id, 'organization_id' => $project->organization_id,
                'category' => SuresignNotification::CATEGORY_COMMUNICATION, 'priority' => SuresignNotification::PRIORITY_INFO,
                'source_type' => 'meeting', 'source_id' => $meeting->id, 'source_field' => $sourceField,
                'action_url' => WorkspaceNavigationResolver::actionUrl($project->id, 'meeting', $meeting->id),
            ],
            $request->user(),
        );
    }
}
