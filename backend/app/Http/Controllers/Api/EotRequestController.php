<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EotRequest;
use App\Models\Project;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;

class EotRequestController extends Controller
{
    public function index(Request $request, Project $project)
    {
        $eots = EotRequest::where('project_id', $project->id)
            ->with('creator:id,name')
            ->latest()
            ->paginate(25);

        return response()->json($eots);
    }

    public function store(Request $request, Project $project)
    {
        $validated = $request->validate([
            'eot_number'    => 'nullable|integer',
            'title'         => 'required|string|max:255',
            'notice_date'   => 'required|date',
            'grounds'       => 'nullable|string',
            'days_claimed'  => 'nullable|integer|min:0',
            'days_granted'  => 'nullable|integer|min:0',
            'status'        => 'nullable|in:draft,submitted,under_assessment,granted,refused',
        ]);

        $validated['eot_number'] = $validated['eot_number']
            ?? (EotRequest::where('project_id', $project->id)->max('eot_number') ?? 0) + 1;

        $eot = EotRequest::create(array_merge($validated, [
            'project_id'     => $project->id,
            'created_by'     => $request->user()->id,
            'organization_id' => $request->user()->organization_id,
            'status'         => $validated['status'] ?? 'draft',
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'eot_submitted',
            "EOT #{$eot->eot_number} submitted: {$eot->title}",
            null,
            $eot
        );

        return response()->json($eot, 201);
    }

    public function show(EotRequest $eotRequest)
    {
        return response()->json($eotRequest->load('creator:id,name'));
    }

    public function update(Request $request, EotRequest $eotRequest)
    {
        $validated = $request->validate([
            'title'        => 'sometimes|string|max:255',
            'notice_date'  => 'sometimes|date',
            'grounds'      => 'nullable|string',
            'days_claimed' => 'nullable|integer|min:0',
            'days_granted' => 'nullable|integer|min:0',
            'status'       => 'nullable|in:draft,submitted,under_assessment,granted,refused',
        ]);

        $eotRequest->update($validated);

        return response()->json($eotRequest);
    }

    public function destroy(EotRequest $eotRequest)
    {
        $eotRequest->delete();
        return response()->json(null, 204);
    }
}
