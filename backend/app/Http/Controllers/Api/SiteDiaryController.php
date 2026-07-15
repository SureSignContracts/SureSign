<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteDiary;
use App\Models\Project;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;

class SiteDiaryController extends Controller
{
    private function authorize(Request $request, Project|SiteDiary $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    /**
     * Re-derives the site diary's REAL parent project so a same-organisation
     * but mismatched project ID in the URL can't address a diary entry that
     * actually belongs to a different project (see MeetingMinutesController).
     */
    private function authorizeProjectSiteDiary(Request $request, Project $project, SiteDiary $siteDiary): void
    {
        $this->authorize($request, $siteDiary);
        if ($siteDiary->project_id !== $project->id) {
            abort(404, 'Site diary not found for this project.');
        }
    }

    public function index(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $query = SiteDiary::where('project_id', $project->id)
            ->with('creator:id,name');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest('diary_date')->paginate(25));
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $validated = $request->validate([
            'diary_date'        => 'required|date',
            'weather'           => 'nullable|string|max:100',
            'temperature'       => 'nullable|numeric',
            'workers_on_site'   => 'nullable|integer|min:0',
            'works_carried_out' => 'nullable|string',
            'materials_delivered' => 'nullable|string',
            'issues'            => 'nullable|string',
            'visitors'          => 'nullable|string',
            'status'            => 'nullable|in:draft,submitted,approved',
        ]);

        $diary = SiteDiary::create(array_merge($validated, [
            'project_id'  => $project->id,
            'created_by'  => $request->user()->id,
            'organization_id' => $project->organization_id,
            'status'      => $validated['status'] ?? 'draft',
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'site_diary_added',
            "Site diary added for " . \Carbon\Carbon::parse($diary->diary_date)->format('d M Y'),
            null,
            $diary
        );

        return response()->json($diary, 201);
    }

    // Not shallow (api/projects/{project}/site-diaries/{site_diary}) — both
    // segments are typed model bindings, so Project $project must be
    // declared even though unused here, matching the same fix already
    // applied to MeetingMinutesController/ProgrammeMilestoneController/etc.
    public function show(Request $request, Project $project, SiteDiary $siteDiary)
    {
        $this->authorizeProjectSiteDiary($request, $project, $siteDiary);

        return response()->json($siteDiary->load('creator:id,name'));
    }

    public function update(Request $request, Project $project, SiteDiary $siteDiary)
    {
        $this->authorizeProjectSiteDiary($request, $project, $siteDiary);

        $oldStatus = $siteDiary->status;

        $validated = $request->validate([
            'diary_date'        => 'sometimes|date',
            'weather'           => 'nullable|string|max:100',
            'temperature'       => 'nullable|numeric',
            'workers_on_site'   => 'nullable|integer|min:0',
            'works_carried_out' => 'nullable|string',
            'materials_delivered' => 'nullable|string',
            'issues'            => 'nullable|string',
            'visitors'          => 'nullable|string',
            'status'            => 'nullable|in:draft,submitted,approved',
        ]);

        $siteDiary->update($validated);

        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            ProjectActivityService::record(
                $siteDiary->project,
                $request->user(),
                'site_diary_updated',
                "Site diary for " . \Carbon\Carbon::parse($siteDiary->diary_date)->format('d M Y') . " status changed to {$validated['status']}",
                null,
                $siteDiary
            );
        }

        return response()->json($siteDiary->fresh());
    }

    public function destroy(Request $request, Project $project, SiteDiary $siteDiary)
    {
        $this->authorizeProjectSiteDiary($request, $project, $siteDiary);

        $siteDiary->delete();
        return response()->json(null, 204);
    }
}
