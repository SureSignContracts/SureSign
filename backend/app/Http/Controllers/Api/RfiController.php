<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateProjectNotificationsJob;
use App\Models\Project;
use App\Models\Rfi;
use App\Models\SuresignNotification;
use App\Services\NotificationService;
use App\Services\ProjectActivityService;
use App\Services\TradePackages\WorkspaceNavigationResolver;
use Illuminate\Http\Request;

class RfiController extends Controller
{
    private function authorize(Request $request, Project|Rfi $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    public function index(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $query = Rfi::where('project_id', $project->id)
            ->with('creator:id,name');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest()->paginate(25));
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $validated = $request->validate([
            'subject'              => 'required|string|max:255',
            'description'          => 'nullable|string',
            'priority'             => 'nullable|in:urgent,high,normal,low',
            'status'               => 'nullable|in:open,pending_response,responded,closed,draft',
            'raised_date'          => 'nullable|date',
            'response_due_date'    => 'nullable|date',
            'programme_impact'     => 'nullable|boolean',
            'programme_impact_days'=> 'nullable|integer|min:0',
            'cost_impact_amount'   => 'nullable|numeric|min:0',
        ]);

        $rfiNumber = (Rfi::where('project_id', $project->id)->max('rfi_number') ?? 0) + 1;

        $rfi = Rfi::create(array_merge($validated, [
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $request->user()->id,
            'rfi_number'      => $rfiNumber,
            'status'          => $validated['status'] ?? 'open',
            // Business-day default (today, for this project's organisation)
            // when no raised_date is supplied.
            'raised_date'     => $validated['raised_date'] ?? \App\Services\TimezoneResolver::today(null, $project->organization)->toDateString(),
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'rfi_raised',
            "RFI #{$rfiNumber} raised: {$rfi->subject}",
            null,
            $rfi
        );

        if ($rfi->status !== 'draft') {
            $this->notifyRfi($request, $project, $rfi, 'submitted', 'submitted', $rfi->subject);
        }

        // A new RFI can immediately be operationally relevant (e.g. created
        // with a near-term response_due_date) — regenerate notifications now
        // rather than waiting for an unrelated AI-confirm/calendar-sync run to
        // eventually pick it up.
        GenerateProjectNotificationsJob::dispatch($project->id);

        return response()->json($rfi->load('creator:id,name'), 201);
    }

    public function show(Request $request, Rfi $rfi)
    {
        $this->authorize($request, $rfi);

        return response()->json($rfi->load('creator:id,name'));
    }

    public function update(Request $request, Rfi $rfi)
    {
        $this->authorize($request, $rfi);

        $oldStatus = $rfi->status;

        $validated = $request->validate([
            'subject'              => 'sometimes|string|max:255',
            'description'          => 'nullable|string',
            'priority'             => 'nullable|in:urgent,high,normal,low',
            'status'               => 'nullable|in:open,pending_response,responded,closed,draft',
            'raised_date'          => 'nullable|date',
            'response_due_date'    => 'nullable|date',
            'response'             => 'nullable|string',
            'responded_at'         => 'nullable|date',
            // assigned_to is NOT accepted here — rfis.assigned_to is a real
            // bigint FK to users.id, but the frontend's "Assigned to" field
            // is free text ("Name or email"). Validating/persisting it as
            // sent would either crash (non-numeric string into an int
            // column) or silently corrupt the FK. Needs a real decision
            // (user-picker dropdown, or a separate free-text column) before
            // this can be wired up — flagged, not silently patched.
            'programme_impact'     => 'nullable|boolean',
            'programme_impact_days'=> 'nullable|integer|min:0',
            'cost_impact_amount'   => 'nullable|numeric|min:0',
        ]);

        $rfi->update($validated);

        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            $project = $rfi->project;
            ProjectActivityService::record(
                $project,
                $request->user(),
                'rfi_updated',
                "RFI #{$rfi->rfi_number} status changed to {$validated['status']}",
                null,
                $rfi
            );

            // Per the approved channel policy, only "answered" and "closed"
            // are meaningful enough to notify — the other status values
            // (open <-> pending_response) are internal workflow bookkeeping.
            if ($validated['status'] === 'responded') {
                $this->notifyRfi(
                    $request, $project, $rfi, 'answered',
                    'answered_' . $rfi->updated_at->timestamp,
                    $rfi->subject
                );
            } elseif ($validated['status'] === 'closed') {
                $this->notifyRfi(
                    $request, $project, $rfi, 'closed',
                    'closed_' . $rfi->updated_at->timestamp,
                    $rfi->subject
                );
            }
        }

        // Only the fields OperationalIntelligenceService::collectRfis() actually
        // reads (response_due_date, status) or that change whether it's
        // resolved (responded_at) can change what notifications should exist —
        // e.g. editing subject/description/priority never affects them, so
        // skip the dispatch rather than queuing pointless work on every save.
        if ($rfi->wasChanged(['response_due_date', 'status', 'responded_at'])) {
            GenerateProjectNotificationsJob::dispatch($rfi->project_id);
        }

        return response()->json($rfi->fresh()->load('creator:id,name'));
    }

    public function destroy(Request $request, Rfi $rfi)
    {
        $this->authorize($request, $rfi);

        $projectId = $rfi->project_id;
        $rfi->delete();

        // A deleted RFI can no longer appear in collectRfis() — resolve any
        // outstanding notification for it immediately rather than leaving it
        // live until an unrelated trigger next regenerates notifications.
        GenerateProjectNotificationsJob::dispatch($projectId);

        return response()->json(null, 204);
    }

    private function notifyRfi(Request $request, Project $project, Rfi $rfi, string $kind, string $sourceField, string $message): void
    {
        $title = match ($kind) {
            'submitted' => "RFI #{$rfi->rfi_number} Submitted",
            'answered'  => "RFI #{$rfi->rfi_number} Answered",
            'closed'    => "RFI #{$rfi->rfi_number} Closed",
            default     => "RFI #{$rfi->rfi_number} Status Changed",
        };

        NotificationService::sendToOrganization(
            $project->organization,
            'rfi_' . $kind,
            $title,
            $message,
            [],
            [
                'project_id' => $project->id, 'organization_id' => $project->organization_id,
                'category' => SuresignNotification::CATEGORY_COMMUNICATION, 'priority' => SuresignNotification::PRIORITY_INFO,
                'source_type' => 'rfi', 'source_id' => $rfi->id, 'source_field' => $sourceField,
                'action_url' => WorkspaceNavigationResolver::actionUrl($project->id, 'rfi', $rfi->id),
            ],
            $request->user(),
        );
    }
}
