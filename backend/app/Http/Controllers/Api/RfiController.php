<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Rfi;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;

class RfiController extends Controller
{
    public function index(Request $request, Project $project)
    {
        $query = Rfi::where('project_id', $project->id)
            ->with('creator:id,name');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest()->paginate(25));
    }

    public function store(Request $request, Project $project)
    {
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
            'organization_id' => $request->user()->organization_id,
            'created_by'      => $request->user()->id,
            'rfi_number'      => $rfiNumber,
            'status'          => $validated['status'] ?? 'open',
            'raised_date'     => $validated['raised_date'] ?? now()->toDateString(),
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'rfi_raised',
            "RFI #{$rfiNumber} raised: {$rfi->subject}",
            null,
            $rfi
        );

        return response()->json($rfi->load('creator:id,name'), 201);
    }

    public function show(Rfi $rfi)
    {
        return response()->json($rfi->load('creator:id,name'));
    }

    public function update(Request $request, Rfi $rfi)
    {
        $oldStatus = $rfi->status;

        $validated = $request->validate([
            'subject'              => 'sometimes|string|max:255',
            'description'          => 'nullable|string',
            'priority'             => 'nullable|in:urgent,high,normal,low',
            'status'               => 'nullable|in:open,pending_response,responded,closed,draft',
            'raised_date'          => 'nullable|date',
            'response_due_date'    => 'nullable|date',
            'response'             => 'nullable|string',
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
        }

        return response()->json($rfi->fresh()->load('creator:id,name'));
    }

    public function destroy(Rfi $rfi)
    {
        $rfi->delete();
        return response()->json(null, 204);
    }
}
