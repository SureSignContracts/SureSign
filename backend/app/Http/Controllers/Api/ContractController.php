<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\FileUpload;
use App\Models\Project;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ContractController extends Controller
{
    private function authorizeProject(Request $request, Project $project): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $project->organization_id) abort(403, 'Access denied.');
    }

    public function index(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $contracts = Contract::where('project_id', $project->id)
            ->with(['creator:id,name', 'paymentApplications', 'variations'])
            ->latest()
            ->paginate(25);

        return response()->json($contracts);
    }

    public function store(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $validated = $request->validate([
            'title'                    => 'required|string|max:255',
            'type'                     => 'required|in:main_contract,subcontract,consultant_appointment,supplier_agreement',
            'reference_number'         => 'nullable|string|max:100',
            'form_of_contract'         => 'nullable|string|max:100',
            'party_name'               => 'nullable|string|max:255',
            'contract_sum'             => 'nullable|numeric|min:0',
            'currency'                 => 'nullable|string|max:10',
            'retention_percentage'     => 'nullable|numeric|min:0|max:100',
            'retention_cap_percentage' => 'nullable|numeric|min:0|max:100',
            'payment_terms_days'       => 'nullable|integer|min:0',
            'execution_date'           => 'nullable|date',
            'commencement_date'        => 'nullable|date',
            'completion_date'          => 'nullable|date',
            'status'                   => 'nullable|in:draft,active,expired,complete,terminated',
            'notes'                    => 'nullable|string',
            'contract_file'            => 'nullable|file|max:51200|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png',
        ]);

        $contract = Contract::create(array_merge(
            collect($validated)->except('contract_file')->toArray(),
            [
                'project_id'      => $project->id,
                'organization_id' => $project->organization_id,
                'created_by'      => $request->user()->id,
                'status'          => $validated['status'] ?? 'draft',
            ]
        ));

        // Handle optional file upload
        if ($request->hasFile('contract_file')) {
            $file       = $request->file('contract_file');
            $storedName = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $path       = "projects/{$project->id}/contracts/{$storedName}";

            Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

            FileUpload::create([
                'project_id'      => $project->id,
                'organization_id' => $project->organization_id,
                'uploaded_by'     => $request->user()->id,
                'attachable_type' => Contract::class,
                'attachable_id'   => $contract->id,
                'original_name'   => $file->getClientOriginalName(),
                'stored_name'     => $storedName,
                'file_path'       => $path,
                'mime_type'       => $file->getMimeType(),
                'file_size'       => $file->getSize(),
                'folder_path'     => 'contracts',
                'module_key'      => 'contracts',
                'folder_key'      => 'contracts',
                'disk'            => 'local',
            ]);

            $activityTitle = $contract->type === 'main_contract'
                ? 'Main contract uploaded'
                : 'Contract file uploaded';

            ProjectActivityService::record(
                $project,
                $request->user(),
                'contract_file_uploaded',
                $activityTitle,
                "File attached to contract: {$contract->title}",
                $contract
            );
        }

        ProjectActivityService::record(
            $project,
            $request->user(),
            'contract_added',
            "Contract added: {$contract->title}",
            "New {$contract->type} contract added to the project.",
            $contract
        );

        return response()->json($contract->load('creator:id,name'), 201);
    }

    public function show(Request $request, Contract $contract)
    {
        $project = $contract->project;
        $this->authorizeProject($request, $project);

        return response()->json(
            $contract->load(['creator:id,name', 'paymentApplications', 'variations', 'project'])
        );
    }

    public function update(Request $request, Contract $contract)
    {
        $project = $contract->project;
        $this->authorizeProject($request, $project);

        $validated = $request->validate([
            'title'                    => 'sometimes|string|max:255',
            'type'                     => 'sometimes|in:main_contract,subcontract,consultant_appointment,supplier_agreement',
            'reference_number'         => 'nullable|string|max:100',
            'form_of_contract'         => 'nullable|string|max:100',
            'party_name'               => 'nullable|string|max:255',
            'contract_sum'             => 'nullable|numeric|min:0',
            'retention_percentage'     => 'nullable|numeric|min:0|max:100',
            'retention_cap_percentage' => 'nullable|numeric|min:0|max:100',
            'payment_terms_days'       => 'nullable|integer|min:0',
            'execution_date'           => 'nullable|date',
            'commencement_date'        => 'nullable|date',
            'completion_date'          => 'nullable|date',
            'status'                   => 'nullable|in:draft,active,expired,complete,terminated',
            'notes'                    => 'nullable|string',
        ]);

        $contract->update($validated);

        ProjectActivityService::record(
            $project,
            $request->user(),
            'contract_updated',
            "Contract updated: {$contract->title}",
            null,
            $contract
        );

        return response()->json($contract->fresh()->load('creator:id,name'));
    }

    public function destroy(Request $request, Contract $contract)
    {
        $project = $contract->project;
        $this->authorizeProject($request, $project);
        $contract->delete();
        return response()->json(['message' => 'Contract deleted.']);
    }
}

