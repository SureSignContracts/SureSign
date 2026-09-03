<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\Project;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Friday Pack Realignment, R1E.2B — Incidents / Accidents / Near Misses.
 * Mirrors SiteInductionController's structure — no attachments in V1
 * (deliberate R1E.2B scope limit; see App\Models\Incident's own
 * docblock). Deliberately privacy-minimal: `description` is never logged
 * verbatim into ActivityLog, and is never included in the Friday Pack
 * snapshot (see App\Services\FridayPack\FridayPackIncidentSourceService).
 */
class IncidentController extends Controller
{
    private function authorize(Request $request, Project|Incident $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    /**
     * Re-derives the Incident's REAL parent project so a same-
     * organisation but mismatched project ID in the URL can't address an
     * incident belonging to a different project — mirrors
     * SiteInductionController::authorizeProjectSiteInduction() exactly.
     */
    private function authorizeProjectIncident(Request $request, Project $project, Incident $incident): void
    {
        $this->authorize($request, $incident);
        if ($incident->project_id !== $project->id) {
            abort(404, 'Incident not found for this project.');
        }
    }

    private function rules(): array
    {
        return [
            'occurred_at'               => 'required|date',
            'type'                      => ['required', Rule::in(Incident::TYPES)],
            'title'                     => 'required|string|max:255',
            'description'               => 'required|string',
            'location'                  => 'nullable|string|max:255',
            'injury_occurred'           => 'nullable|boolean',
            'regulatory_reportability'  => ['nullable', Rule::in(Incident::REPORTABILITY_STATES)],
            'status'                    => ['nullable', Rule::in(Incident::STATUSES)],
        ];
    }

    public function index(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $query = Incident::where('project_id', $project->id)
            ->with('creator:id,name');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('from')) {
            $query->whereDate('occurred_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('occurred_at', '<=', $request->to);
        }

        return response()->json($query->latest('occurred_at')->paginate(25));
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $validated = $request->validate($this->rules());

        $incident = Incident::create(array_merge($validated, [
            'project_id'                => $project->id,
            'organization_id'           => $project->organization_id,
            'created_by'                => $request->user()->id,
            // Explicit server-side default rather than relying solely on
            // the DB column default — 'unknown' is a genuine classification
            // state, never silently substituted for a user-chosen one.
            'regulatory_reportability'  => $validated['regulatory_reportability'] ?? 'unknown',
            'status'                    => $validated['status'] ?? 'open',
        ]));

        // Deliberately never includes description/location in the
        // activity title — privacy-minimal audit trail, identifies the
        // event without duplicating potentially sensitive narrative.
        ProjectActivityService::record(
            $project,
            $request->user(),
            'incident_added',
            "{$incident->typeLabel()} recorded for " . $incident->occurred_at->format('d M Y'),
            null,
            $incident
        );

        return response()->json($incident->load('creator:id,name'), 201);
    }

    // Not shallow (api/projects/{project}/incidents/{incident}) — both
    // segments are typed model bindings, so Project $project must be
    // declared even though unused here, matching SiteInductionController's
    // identical convention.
    public function show(Request $request, Project $project, Incident $incident)
    {
        $this->authorizeProjectIncident($request, $project, $incident);

        return response()->json($incident->load('creator:id,name'));
    }

    public function update(Request $request, Project $project, Incident $incident)
    {
        $this->authorizeProjectIncident($request, $project, $incident);

        $oldStatus = $incident->status;

        $validated = $request->validate([
            'occurred_at'               => 'sometimes|date',
            'type'                      => ['sometimes', Rule::in(Incident::TYPES)],
            'title'                     => 'sometimes|string|max:255',
            'description'               => 'sometimes|string',
            'location'                  => 'nullable|string|max:255',
            'injury_occurred'           => 'nullable|boolean',
            'regulatory_reportability'  => ['sometimes', Rule::in(Incident::REPORTABILITY_STATES)],
            'status'                    => ['sometimes', Rule::in(Incident::STATUSES)],
        ]);

        $incident->update($validated);

        ProjectActivityService::record(
            $project,
            $request->user(),
            'incident_updated',
            "{$incident->typeLabel()} updated for " . $incident->occurred_at->format('d M Y'),
            null,
            $incident
        );

        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            ProjectActivityService::record(
                $project,
                $request->user(),
                'incident_status_changed',
                "{$incident->typeLabel()} status changed from {$oldStatus} to {$validated['status']}",
                null,
                $incident
            );
        }

        return response()->json($incident->fresh()->load('creator:id,name'));
    }

    public function destroy(Request $request, Project $project, Incident $incident)
    {
        $this->authorizeProjectIncident($request, $project, $incident);

        $typeLabel = $incident->typeLabel();
        $occurredAt = $incident->occurred_at->format('d M Y');

        $incident->delete();

        ProjectActivityService::record(
            $project,
            $request->user(),
            'incident_deleted',
            "{$typeLabel} deleted for {$occurredAt}",
            null,
            $incident
        );

        return response()->json(null, 204);
    }
}
