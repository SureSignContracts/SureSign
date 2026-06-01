<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Project;
use App\Models\Variation;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;

class VariationController extends Controller
{
    public function indexByProject(Request $request, Project $project)
    {
        $query = Variation::where('project_id', $project->id)
            ->with(['creator:id,name', 'contract:id,title,reference_number']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest()->paginate(25));
    }

    public function index(Request $request, Contract $contract)
    {
        return response()->json(
            Variation::where('contract_id', $contract->id)
                ->with('creator:id,name')
                ->latest()
                ->paginate(25)
        );
    }

    public function store(Request $request, Contract $contract)
    {
        $validated = $request->validate([
            'title'                  => 'required|string|max:255',
            'description'            => 'nullable|string',
            'type'                   => 'nullable|string|max:100',
            'status'                 => 'nullable|in:pending,submitted,approved,rejected,on_hold',
            'quoted_amount'          => 'nullable|numeric|min:0',
            'agreed_amount'          => 'nullable|numeric|min:0',
            'variation_date'         => 'nullable|date',
            'programme_impact_days'  => 'nullable|integer|min:0',
        ]);

        $variationNumber = (Variation::where('project_id', $contract->project_id)->max('variation_number') ?? 0) + 1;

        $variation = Variation::create(array_merge($validated, [
            'contract_id'     => $contract->id,
            'project_id'      => $contract->project_id,
            'organization_id' => $request->user()->organization_id,
            'created_by'      => $request->user()->id,
            'variation_number'=> $variationNumber,
            'status'          => $validated['status'] ?? 'pending',
            'variation_date'  => $validated['variation_date'] ?? now()->toDateString(),
        ]));

        $project = $contract->project;
        ProjectActivityService::record(
            $project,
            $request->user(),
            'variation_created',
            "Variation #{$variationNumber} submitted: {$variation->title}",
            null,
            $variation
        );

        return response()->json($variation->load('creator:id,name'), 201);
    }

    public function show(Variation $variation)
    {
        return response()->json($variation->load(['creator:id,name', 'contract:id,title']));
    }

    public function update(Request $request, Variation $variation)
    {
        $oldStatus = $variation->status;

        $validated = $request->validate([
            'title'                 => 'sometimes|string|max:255',
            'description'           => 'nullable|string',
            'type'                  => 'nullable|string|max:100',
            'status'                => 'nullable|in:pending,submitted,approved,rejected,on_hold',
            'quoted_amount'         => 'nullable|numeric|min:0',
            'agreed_amount'         => 'nullable|numeric|min:0',
            'variation_date'        => 'nullable|date',
            'programme_impact_days' => 'nullable|integer|min:0',
        ]);

        $variation->update($validated);

        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            $project = $variation->project;
            ProjectActivityService::record(
                $project,
                $request->user(),
                'variation_updated',
                "Variation #{$variation->variation_number} {$validated['status']}: {$variation->title}",
                null,
                $variation
            );
        }

        return response()->json($variation->fresh()->load('creator:id,name'));
    }

    public function destroy(Variation $variation)
    {
        $variation->delete();
        return response()->json(null, 204);
    }
}
