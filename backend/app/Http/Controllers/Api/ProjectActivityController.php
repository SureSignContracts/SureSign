<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectActivity;
use Illuminate\Http\Request;

class ProjectActivityController extends Controller
{
    public function index(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $query = ProjectActivity::where('project_id', $project->id)
            ->with('user:id,name')
            ->latest();

        // Allow filtering by related model
        if ($request->filled('related_id') && $request->filled('related_type')) {
            $typeMap = [
                'AdjudicationCase' => \App\Models\AdjudicationCase::class,
            ];
            $fqn = $typeMap[$request->related_type] ?? $request->related_type;
            $query->where('related_type', $fqn)->where('related_id', $request->related_id);
        }

        return response()->json($query->paginate(50));
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) {
            return;
        }
        if ($user->organization_id !== $project->organization_id) {
            abort(403, 'Access denied.');
        }
    }
}
