<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Snag;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;

class SnagController extends Controller
{
    public function index(Request $request, Project $project)
    {
        $query = Snag::where('project_id', $project->id)
            ->with(['creator:id,name', 'assignee:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%");
            });
        }

        return response()->json($query->latest()->paginate(50));
    }

    public function store(Request $request, Project $project)
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'location'    => 'nullable|string|max:255',
            'category'    => 'nullable|string|max:100',
            'priority'    => 'nullable|in:low,medium,high,critical',
            'status'      => 'nullable|in:open,in_progress,ready_for_review,closed',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'due_date'    => 'nullable|date',
            'notes'       => 'nullable|string',
        ]);

        $snagNumber = (Snag::where('project_id', $project->id)->max('snag_number') ?? 0) + 1;

        $snag = Snag::create(array_merge($validated, [
            'project_id'      => $project->id,
            'organization_id' => $request->user()->organization_id,
            'created_by'      => $request->user()->id,
            'snag_number'     => $snagNumber,
            'status'          => $validated['status'] ?? 'open',
            'priority'        => $validated['priority'] ?? 'medium',
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'snag_created',
            "Snag #{$snagNumber} raised: {$snag->title}",
            null,
            $snag
        );

        return response()->json($snag->load(['creator:id,name', 'assignee:id,name']), 201);
    }

    public function show(Snag $snag)
    {
        return response()->json($snag->load(['creator:id,name', 'assignee:id,name']));
    }

    public function update(Request $request, Snag $snag)
    {
        $oldStatus = $snag->status;

        $validated = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'location'    => 'nullable|string|max:255',
            'category'    => 'nullable|string|max:100',
            'priority'    => 'nullable|in:low,medium,high,critical',
            'status'      => 'nullable|in:open,in_progress,ready_for_review,closed',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'due_date'    => 'nullable|date',
            'notes'       => 'nullable|string',
        ]);

        // Auto-set closed_at when closing
        if (isset($validated['status']) && $validated['status'] === 'closed' && $oldStatus !== 'closed') {
            $validated['closed_at'] = now();
        } elseif (isset($validated['status']) && $validated['status'] !== 'closed') {
            $validated['closed_at'] = null;
        }

        $snag->update($validated);

        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            $project = $snag->project;
            ProjectActivityService::record(
                $project,
                $request->user(),
                'snag_updated',
                "Snag #{$snag->snag_number} status changed to {$validated['status']}",
                null,
                $snag
            );
        }

        return response()->json($snag->fresh()->load(['creator:id,name', 'assignee:id,name']));
    }

    public function destroy(Snag $snag)
    {
        $snag->delete();
        return response()->json(null, 204);
    }
}
