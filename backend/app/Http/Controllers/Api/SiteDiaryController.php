<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteDiary;
use App\Models\Project;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;

class SiteDiaryController extends Controller
{
    public function index(Request $request, Project $project)
    {
        $diaries = SiteDiary::where('project_id', $project->id)
            ->with('creator:id,name')
            ->latest('diary_date')
            ->paginate(25);

        return response()->json($diaries);
    }

    public function store(Request $request, Project $project)
    {
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
            'organization_id' => $request->user()->organization_id,
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

    public function show(SiteDiary $siteDiary)
    {
        return response()->json($siteDiary->load('creator:id,name'));
    }

    public function update(Request $request, SiteDiary $siteDiary)
    {
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

        return response()->json($siteDiary);
    }

    public function destroy(SiteDiary $siteDiary)
    {
        $siteDiary->delete();
        return response()->json(null, 204);
    }
}
