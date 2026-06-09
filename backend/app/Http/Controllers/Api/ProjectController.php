<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectFolder;
use App\Services\ProjectActivityService;
use App\Services\ProjectStatsService;
use App\Services\ProjectStorageService;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Super Admin and Admin can query any org by passing organization_id
        if (($user->hasRole('Super Admin') || $user->hasRole('Admin')) && $request->filled('organization_id')) {
            $query = Project::with(['creator:id,name', 'contacts'])
                ->where('organization_id', $request->organization_id);
        } elseif ($user->hasRole('Super Admin') || $user->hasRole('Admin')) {
            // System users with no org filter: return all projects
            $query = Project::with(['creator:id,name', 'contacts']);
        } else {
            // Regular clients: scope to their own organisation
            $query = Project::with(['creator:id,name', 'contacts'])
                ->where('organization_id', $user->organization_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $projects = $query->latest()->paginate(20);
        return response()->json($projects);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'                      => 'required|string|max:255',
            'code'                      => 'nullable|string|max:50',
            'description'               => 'nullable|string',
            'type'                      => 'nullable|string',
            'contract_type'             => 'nullable|string|max:100',
            'status'                    => 'nullable|in:active,on_hold,completed,cancelled',
            'client_id'                 => 'nullable|integer|exists:clients,id',
            'contract_value'            => 'nullable|numeric|min:0',
            'retention_percentage'      => 'nullable|numeric|min:0|max:100',
            'retention_cap_percentage'  => 'nullable|numeric|min:0|max:100',
            'payment_terms_days'        => 'nullable|integer|min:0',
            'start_date'                => 'nullable|date',
            'end_date'                  => 'nullable|date|after_or_equal:start_date',
            'address'                   => 'nullable|string',
            'city'                      => 'nullable|string',
            'state'                     => 'nullable|string',
            'postcode'                  => 'nullable|string',
        ]);

        $project = Project::create(array_merge($validated, [
            'organization_id' => $request->user()->organization_id,
            'created_by'      => $request->user()->id,
            'status'          => $validated['status'] ?? 'active',
        ]));

        // Auto-create standard folder structure (DB records)
        $this->createDefaultFolders($project);

        // Create actual suresign/ directory structure on disk
        ProjectStorageService::createProjectFolders($project);

        // Add creator as project manager
        $project->users()->attach($request->user()->id, ['role' => 'project_manager']);

        // Record activity
        ProjectActivityService::record(
            $project,
            $request->user(),
            'project_created',
            "Project created: {$project->name}",
            null,
            $project
        );

        return response()->json($project->load(['creator:id,name', 'folders']), 201);
    }

    /**
     * Admin: create a project on behalf of a client company.
     * Route: POST /admin/companies/{organization}/projects
     */
    public function storeForCompany(Request $request, Organization $organization)
    {
        $admin = $request->user();

        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'code'             => 'nullable|string|max:50',
            'description'      => 'nullable|string',
            'status'           => 'nullable|in:active,on_hold,completed,cancelled',
            'contract_value'   => 'nullable|numeric|min:0',
            'start_date'       => 'nullable|date',
            'end_date'         => 'nullable|date|after_or_equal:start_date',
            'address'          => 'nullable|string',
            'city'             => 'nullable|string',
            'state'            => 'nullable|string',
            'postcode'         => 'nullable|string',
            'country'          => 'nullable|string',
        ]);

        $project = Project::create(array_merge($validated, [
            'organization_id' => $organization->id,
            'created_by'      => $admin->id,
            'status'          => $validated['status'] ?? 'active',
        ]));

        $this->createDefaultFolders($project);
        ProjectStorageService::createProjectFolders($project);

        ProjectActivityService::record(
            $project,
            $admin,
            'project_created',
            "Project \"{$project->name}\" created by admin {$admin->name} on behalf of {$organization->name}.",
            null,
            $project
        );

        return response()->json($project->load(['creator:id,name', 'folders', 'organization:id,name']), 201);
    }

    public function show(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);
        return response()->json(
            $project->load(['creator:id,name', 'contacts', 'contracts', 'folders', 'users:id,name,email', 'organization:id,name', 'client:id,name'])
        );
    }

    public function update(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);
        $validated = $request->validate([
            'name'                     => 'sometimes|string|max:255',
            'code'                     => 'nullable|string|max:50',
            'description'              => 'nullable|string',
            'status'                   => 'sometimes|in:active,on_hold,completed,cancelled',
            'type'                     => 'nullable|string',
            'contract_type'            => 'nullable|string|max:100',
            'contract_value'           => 'nullable|numeric|min:0',
            'retention_percentage'     => 'nullable|numeric|min:0|max:100',
            'payment_terms_days'       => 'nullable|integer|min:0',
            'start_date'               => 'nullable|date',
            'end_date'                 => 'nullable|date',
            'practical_completion_date'=> 'nullable|date',
            'address'                  => 'nullable|string',
        ]);
        $project->update($validated);
        return response()->json($project->fresh());
    }

    public function destroy(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);
        $project->delete();
        return response()->json(['message' => 'Project archived.']);
    }

    public function folders(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $folders = $project->folders()->orderBy('order')->get();

        $counts = FileUpload::where('project_id', $project->id)
            ->selectRaw('folder_path, COUNT(*) as file_count')
            ->groupBy('folder_path')
            ->pluck('file_count', 'folder_path');

        $folders = $folders->map(function ($folder) use ($counts) {
            $folder->file_count = $counts[$folder->path] ?? 0;
            return $folder;
        });

        return response()->json($folders);
    }

    private function createDefaultFolders(Project $project): void
    {
        $folders = [
            ['number' => '01', 'name' => 'Contracts',             'path' => '01_Contracts'],
            ['number' => '02', 'name' => 'Commercial',            'path' => '02_Commercial'],
            ['number' => '03', 'name' => 'Payment Applications',  'path' => '03_Payment_Applications'],
            ['number' => '04', 'name' => 'Variations',            'path' => '04_Variations'],
            ['number' => '05', 'name' => 'Notices',               'path' => '05_Notices'],
            ['number' => '06', 'name' => 'RFIs',                  'path' => '06_RFIs'],
            ['number' => '07', 'name' => 'Meetings',              'path' => '07_Meetings'],
            ['number' => '08', 'name' => 'QA Reports',            'path' => '08_QA_Reports'],
            ['number' => '09', 'name' => 'Snagging',              'path' => '09_Snagging'],
            ['number' => '10', 'name' => 'Closeout',              'path' => '10_Closeout'],
            ['number' => '11', 'name' => 'Adjudication',          'path' => '11_Adjudication'],
            ['number' => '12', 'name' => 'Site Reports',          'path' => '12_Site_Reports'],
            ['number' => '13', 'name' => 'AI Generated',          'path' => '13_AI_Generated'],
        ];

        foreach ($folders as $i => $folder) {
            ProjectFolder::create([
                'project_id'      => $project->id,
                'name'            => $folder['name'],
                'path'            => $folder['path'],
                'folder_number'   => $folder['number'],
                'order'           => $i + 1,
                'is_auto_created' => true,
            ]);
        }
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        $user = $request->user();
        // System users (Super Admin, Admin) can access any project
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) {
            return;
        }
        if ($user->organization_id !== $project->organization_id) {
            abort(403, 'Access denied.');
        }
    }

    public function stats(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);
        return response()->json(ProjectStatsService::getStats($project));
    }
}
