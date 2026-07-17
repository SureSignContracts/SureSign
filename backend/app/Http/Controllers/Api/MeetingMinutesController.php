<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MeetingMinutes;
use App\Models\Project;
use App\Models\SuresignNotification;
use App\Services\NotificationService;
use App\Services\ProjectActivityService;
use App\Services\TimezoneResolver;
use App\Services\TradePackages\WorkspaceNavigationResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Batch 6: meetings support two modes.
 *
 *   Date-only (legacy, still the default): meeting_date is authoritative,
 *   starts_at/ends_at/scheduled_timezone stay null.
 *
 *   Timed: the client sends meeting_date (the local scheduling date),
 *   start_time/end_time (H:i, local wall-clock), and timezone (IANA).
 *   The controller builds the UTC starts_at/ends_at itself — the frontend
 *   never computes or sends a UTC instant directly. meeting_date is then
 *   re-derived server-side (see MeetingMinutes::booted()) from starts_at's
 *   own calendar day in that timezone, so it always agrees with starts_at.
 *
 * Switching between modes is only ever done via an explicit `is_timed` key
 * in the request — its mere absence never changes a meeting's mode, which
 * is what keeps every existing API caller (sending only meeting_date, as
 * before this batch) fully backward compatible.
 */

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

        // Deterministic mixed ordering (Batch 6): most recent day first;
        // within the same day, date-only ("all day") meetings are grouped
        // before timed ones, which are then ordered by their local start
        // time. `starts_at IS NOT NULL` is portable SQL (evaluates to 0/1
        // in both MySQL and sqlite) — no MySQL-only functions.
        $meetings = MeetingMinutes::where('project_id', $project->id)
            ->with('creator:id,name')
            ->orderByDesc('meeting_date')
            ->orderByRaw('starts_at IS NOT NULL')
            ->orderBy('starts_at')
            ->paginate(25);

        return response()->json($meetings);
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $isTimed = $request->boolean('is_timed', false);

        $commonRules = [
            'meeting_number' => 'nullable|integer',
            'title'          => 'required|string|max:255',
            'location'       => 'nullable|string|max:255',
            'type'           => 'nullable|in:progress,design,commercial,safety,subcontractor,other',
            'attendees'      => 'nullable|array',
            'agenda'         => 'nullable|string',
            'minutes'        => 'nullable|string',
            'action_items'   => 'nullable|array',
            'status'         => 'nullable|in:draft,issued,approved',
        ];

        $validated = $request->validate(array_merge($commonRules, $isTimed ? [
            'meeting_date' => 'required|date',
            'start_time'   => 'required|date_format:H:i',
            'end_time'     => 'required|date_format:H:i',
            'timezone'     => 'required|timezone',
        ] : [
            'meeting_date' => 'required|date',
        ]));

        $validated['meeting_number'] = $validated['meeting_number']
            ?? (MeetingMinutes::where('project_id', $project->id)->max('meeting_number') ?? 0) + 1;

        $scheduling = [];
        if ($isTimed) {
            $scheduling = $this->buildSchedule($validated['meeting_date'], $validated['start_time'], $validated['end_time'], $validated['timezone']);
            if (isset($scheduling['error'])) {
                return response()->json(['message' => $scheduling['error']], 422);
            }
        }

        // For a timed meeting, meeting_date is never passed through directly
        // — MeetingMinutes::booted() derives it from starts_at, the single
        // authoritative computation. Passing the client's raw meeting_date
        // here too wouldn't be wrong (the model hook always overwrites it),
        // but it's a redundant second value with no effect — stripped so
        // there's exactly one source of truth to read, not two that happen
        // to agree.
        $meeting = MeetingMinutes::create(array_merge(
            Arr::except($validated, $isTimed ? ['meeting_date', 'start_time', 'end_time', 'timezone'] : []),
            [
                'project_id'      => $project->id,
                'created_by'      => $request->user()->id,
                'organization_id' => $project->organization_id,
                'status'          => $validated['status'] ?? 'draft',
            ],
            $scheduling
        ));

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

    /**
     * Build the UTC starts_at/ends_at/scheduled_timezone trio from local
     * scheduling fields, rolling `end_time` to the next calendar day when
     * it's earlier than or equal to `start_time` (crossing midnight
     * locally) — equal times are rejected outright as zero-duration, not
     * silently treated as a 24-hour meeting.
     *
     * Returns either ['starts_at' => ..., 'ends_at' => ..., 'scheduled_timezone' => ...]
     * or ['error' => '...'] — never throws, so callers can turn the error
     * into a plain 422 without a try/catch at each call site.
     */
    private function buildSchedule(string $localDate, string $startTime, string $endTime, string $timezone): array
    {
        try {
            $startsAt = TimezoneResolver::buildLocalInstant($localDate, $startTime, $timezone);
            $endsAt   = TimezoneResolver::buildLocalInstant($localDate, $endTime, $timezone);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        if ($endsAt->equalTo($startsAt)) {
            return ['error' => 'End time must be after start time.'];
        }
        if ($endsAt->lt($startsAt)) {
            $endsAt = $endsAt->copy()->addDay();
        }
        if ($startsAt->diffInHours($endsAt) > 24) {
            return ['error' => 'A meeting cannot be longer than 24 hours.'];
        }

        return ['starts_at' => $startsAt, 'ends_at' => $endsAt, 'scheduled_timezone' => $timezone];
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

        $commonRules = [
            'title'        => 'sometimes|string|max:255',
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
        ];

        // Mode switches only ever happen when `is_timed` is explicitly
        // present — its absence NEVER changes a meeting's mode. This is
        // what keeps every pre-Batch-6 API caller (sending only
        // meeting_date, no is_timed key at all) fully backward compatible.
        $switchingToDateOnly = $request->has('is_timed') && !$request->boolean('is_timed');
        $switchingToTimed    = $request->has('is_timed') && $request->boolean('is_timed');
        $reschedulingTimed   = !$request->has('is_timed') && $meeting->is_timed
            && $request->hasAny(['meeting_date', 'start_time', 'end_time', 'timezone']);

        $scheduling   = null; // null = "don't touch starts_at/ends_at/scheduled_timezone at all"
        $rescheduled  = false;

        if ($switchingToDateOnly) {
            $validated = $request->validate(array_merge($commonRules, ['meeting_date' => 'required|date']));
            $scheduling = ['starts_at' => null, 'ends_at' => null, 'scheduled_timezone' => null];
            $rescheduled = true; // date-only <-> timed is always a meaningful change
        } elseif ($switchingToTimed || $reschedulingTimed) {
            // Converting to timed requires the full schedule; rescheduling an
            // already-timed meeting lets any omitted field default to its
            // current stored value (Phase 4: "updates preserve old values
            // when omitted").
            $rules = array_merge($commonRules, [
                'meeting_date' => $switchingToTimed ? 'required|date' : 'sometimes|date',
                'start_time'   => $switchingToTimed ? 'required|date_format:H:i' : 'sometimes|date_format:H:i',
                'end_time'     => $switchingToTimed ? 'required|date_format:H:i' : 'sometimes|date_format:H:i',
                'timezone'     => $switchingToTimed ? 'required|timezone' : 'sometimes|timezone',
            ]);
            $validated = $request->validate($rules);

            $timezone  = $validated['timezone'] ?? $meeting->scheduled_timezone;
            $localDate = $validated['meeting_date'] ?? $meeting->starts_at->copy()->setTimezone($timezone)->toDateString();
            $startTime = $validated['start_time'] ?? $meeting->starts_at->copy()->setTimezone($timezone)->format('H:i');
            $endTime   = $validated['end_time']   ?? $meeting->ends_at->copy()->setTimezone($timezone)->format('H:i');

            $result = $this->buildSchedule($localDate, $startTime, $endTime, $timezone);
            if (isset($result['error'])) {
                return response()->json(['message' => $result['error']], 422);
            }
            $scheduling = $result;

            // Only a genuine change to the resolved instants counts as a
            // reschedule — resubmitting the same values (or converting to
            // timed for the first time) is compared against the meeting's
            // previous starts_at/ends_at below.
            $validated = Arr::except($validated, ['meeting_date', 'start_time', 'end_time', 'timezone']);
        } else {
            // No mode switch, no time fields touched — ordinary edit.
            // Date-only meetings may still have meeting_date changed
            // exactly as before Batch 6.
            $validated = $request->validate(array_merge($commonRules, [
                'meeting_date' => 'sometimes|date',
            ]));
        }

        $previousDate   = $meeting->meeting_date;
        $previousStart  = $meeting->starts_at;
        $previousEnd    = $meeting->ends_at;
        $previousStatus = $meeting->status;

        $meeting->update(array_merge($validated, $scheduling ?? []));

        // "Meaningful" per the approved channel policy = a reschedule (the
        // organisation-local calendar date changed, or — for a timed
        // meeting — the actual start/end instant changed) or a lifecycle
        // milestone (issued = minutes published, approved = signed off).
        // Editing location/agenda/attendees/action items alone stays
        // silent, and re-submitting an unchanged schedule is not a
        // reschedule either (cosmetic edits must not notify).
        if (!$rescheduled) {
            $dateChanged  = (string) $meeting->meeting_date !== (string) $previousDate;
            $startChanged = $scheduling !== null && !$this->sameInstant($previousStart, $meeting->starts_at);
            $endChanged   = $scheduling !== null && !$this->sameInstant($previousEnd, $meeting->ends_at);
            $rescheduled  = $dateChanged || $startChanged || $endChanged;
        }
        $statusMoved = isset($validated['status']) && $validated['status'] !== $previousStatus;

        if ($rescheduled) {
            $this->notifyMeeting(
                $request, $project, $meeting, 'rescheduled',
                'rescheduled_' . $meeting->updated_at->timestamp,
                $meeting->is_timed
                    ? 'Now on ' . $meeting->meeting_date->format('d M Y') . ' at '
                        . $meeting->starts_at->copy()->setTimezone($meeting->scheduled_timezone)->format('H:i')
                        . ' (' . $meeting->scheduled_timezone . ').'
                    : 'Now on ' . $meeting->meeting_date->format('d M Y') . '.'
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

    private function sameInstant(?\Carbon\Carbon $a, ?\Carbon\Carbon $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return $a->equalTo($b);
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

        // Batch 7 (Phase 11): the shared `message` string above is a single,
        // organisation-wide fact — it can only ever show ONE timezone's
        // rendering (the scheduling timezone), which is honest and
        // unambiguous (always labelled) but not personalised. Recipients
        // resolve through sendToOrganization() as one notification ROW per
        // recipient, each with its own `data` JSON column — so the raw UTC
        // instants + scheduling timezone are included here too, letting the
        // frontend additionally render the recipient's OWN effective-
        // timezone time alongside the shared message, without needing
        // per-recipient message text or a schema change.
        $data = $meeting->is_timed ? [
            'is_timed'           => true,
            'starts_at'          => $meeting->starts_at->toJSON(),
            'ends_at'            => $meeting->ends_at->toJSON(),
            'scheduled_timezone' => $meeting->scheduled_timezone,
        ] : ['is_timed' => false];

        NotificationService::sendToOrganization(
            $project->organization,
            'meeting_' . $kind,
            $title,
            $message,
            $data,
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
