<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteInstruction;
use App\Models\Project;
use Illuminate\Http\Request;

class SiteInstructionController extends Controller
{
    public function index(Request $request, Project $project)
    {
        $instructions = SiteInstruction::where('project_id', $project->id)
            ->with('creator:id,name')
            ->latest('issued_date')
            ->paginate(25);

        return response()->json($instructions);
    }

    public function store(Request $request, Project $project)
    {
        $validated = $request->validate([
            'instruction_number' => 'nullable|integer',
            'title'              => 'required|string|max:255',
            'issued_date'        => 'required|date',
            'issued_to'          => 'nullable|string|max:255',
            'description'        => 'nullable|string',
            'status'             => 'nullable|in:draft,issued',
        ]);

        $validated['instruction_number'] = $validated['instruction_number']
            ?? (SiteInstruction::where('project_id', $project->id)->max('instruction_number') ?? 0) + 1;

        $instruction = SiteInstruction::create(array_merge($validated, [
            'project_id'     => $project->id,
            'created_by'     => $request->user()->id,
            'organization_id' => $request->user()->organization_id,
            'status'         => $validated['status'] ?? 'draft',
        ]));

        return response()->json($instruction, 201);
    }

    public function show(SiteInstruction $siteInstruction)
    {
        return response()->json($siteInstruction->load('creator:id,name'));
    }

    public function update(Request $request, SiteInstruction $siteInstruction)
    {
        $validated = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'issued_date' => 'sometimes|date',
            'issued_to'   => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status'      => 'nullable|in:draft,issued',
        ]);

        $siteInstruction->update($validated);

        return response()->json($siteInstruction);
    }

    public function destroy(SiteInstruction $siteInstruction)
    {
        $siteInstruction->delete();
        return response()->json(null, 204);
    }
}
