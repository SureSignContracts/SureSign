<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\FileUpload;
use App\Models\Project;
use App\Models\SuresignSetting;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $filter = $request->query('filter', 'active');

        $query = Contract::where('project_id', $project->id)
            ->with([
                'creator:id,name',
                'paymentApplications',
                'variations',
                'eotRequests',
                'fileUploads' => fn($q) => $q->select(['id','attachable_type','attachable_id','original_name','mime_type','file_size','preview_pdf_path'])->latest(),
            ]);

        if ($filter === 'archived') {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }

        $contracts = $query->latest()->paginate(25);

        return response()->json($contracts);
    }

    public function store(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $validated = $request->validate([
            'title'                    => 'required|string|max:255',
            'type'                     => 'required|in:main_contract,subcontract,consultant_appointment,supplier_agreement',
            'reference_number'         => 'nullable|string|max:255',
            'form_of_contract'         => 'nullable|string|max:255',
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
            'contract_file'            => 'required|file|max:' . SuresignSetting::maxUploadKb() . '|mimes:pdf,doc,docx,txt',
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

        if ($request->hasFile('contract_file')) {
            $file       = $request->file('contract_file');
            $storedName = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $path       = "projects/{$project->id}/contracts/{$storedName}";

            Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

            $folderKey = match ($contract->type) {
                'main_contract'          => 'contracts/main_contract',
                'consultant_appointment' => 'contracts/consultant_agreement',
                'supplier_agreement'     => 'contracts/supplier_agreement',
                'subcontract'            => 'contracts/subcontract',
                default                  => 'contracts/main_contract',
            };

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
                'folder_key'      => $folderKey,
                'source_type'     => 'contract',
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

        ActivityLog::record('contract.created', 'Contract created: ' . $contract->title, $request->user(), $contract, ['reference' => $contract->reference_number], $project->id, $project->organization_id);

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
            'reference_number'         => 'nullable|string|max:255',
            'form_of_contract'         => 'nullable|string|max:255',
            'party_name'               => 'nullable|string|max:255',
            'contract_sum'             => 'nullable|numeric|min:0',
            'retention_percentage'     => 'nullable|numeric|min:0|max:100',
            'retention_cap_percentage' => 'nullable|numeric|min:0|max:100',
            'payment_terms_days'       => 'nullable|integer|min:0',
            'execution_date'           => 'nullable|date',
            'commencement_date'        => 'nullable|date',
            'completion_date'          => 'nullable|date',
            'status'                   => 'nullable|in:draft,active,expired,complete,terminated',
            'defects_liability_period' => 'nullable|string|max:255',
            'liquidated_damages'       => 'nullable|string|max:255',
            'notice_requirements'      => 'nullable|string',
            'variation_procedure'      => 'nullable|string',
            'notes'                    => 'nullable|string',
        ]);

        $contract->update($validated);

        ActivityLog::record('contract.updated', 'Contract updated: ' . $contract->title, $request->user(), $contract, array_keys($validated), $project->id, $project->organization_id);

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

    public function attachFile(Request $request, Contract $contract)
    {
        $project = $contract->project;
        $this->authorizeProject($request, $project);

        $request->validate([
            'contract_file' => 'required|file|max:' . SuresignSetting::maxUploadKb() . '|mimes:pdf,doc,docx,txt',
        ]);

        $file       = $request->file('contract_file');
        $storedName = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path       = "projects/{$project->id}/contracts/{$storedName}";

        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        $folderKey = match ($contract->type) {
            'main_contract'          => 'contracts/main_contract',
            'consultant_appointment' => 'contracts/consultant_agreement',
            'supplier_agreement'     => 'contracts/supplier_agreement',
            'subcontract'            => 'contracts/subcontract',
            default                  => 'contracts/main_contract',
        };

        $fileUpload = FileUpload::create([
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
            'folder_key'      => $folderKey,
            'source_type'     => 'contract',
            'disk'            => 'local',
        ]);

        ProjectActivityService::record(
            $project,
            $request->user(),
            'contract_file_uploaded',
            'Contract file attached',
            "File attached to contract: {$contract->title}",
            $contract
        );

        return response()->json(['file_upload' => $fileUpload], 201);
    }

    public function archive(Request $request, Contract $contract)
    {
        $project = $contract->project;
        $this->authorizeProject($request, $project);

        if ($contract->archived_at) {
            return response()->json(['message' => 'Contract is already archived.'], 422);
        }

        $contract->update(['archived_at' => now()]);

        ActivityLog::record('contract.archived', 'Contract archived: ' . $contract->title, $request->user(), $contract, [], $project->id, $project->organization_id);

        ProjectActivityService::record(
            $project,
            $request->user(),
            'contract_archived',
            "Contract archived: {$contract->title}",
            "Reference: {$contract->reference_number}",
            $contract
        );

        return response()->json(['message' => 'Contract archived.']);
    }

    public function restore(Request $request, Contract $contract)
    {
        $project = $contract->project;
        $this->authorizeProject($request, $project);

        $contract->update(['archived_at' => null]);

        ProjectActivityService::record(
            $project,
            $request->user(),
            'contract_restored',
            "Contract restored: {$contract->title}",
            "Reference: {$contract->reference_number}",
            $contract
        );

        return response()->json(['message' => 'Contract restored.']);
    }

    public function destroy(Request $request, Contract $contract)
    {
        $project = $contract->project;
        $this->authorizeProject($request, $project);

        if (!$contract->isDeletable()) {
            return response()->json([
                'message' => 'This contract cannot be deleted.',
                'blockers' => $contract->deletable_blockers,
            ], 422);
        }

        $title = $contract->title;
        $ref   = $contract->reference_number ?? "#{$contract->id}";

        DB::transaction(function () use ($contract) {
            $contract->aiAnalyses()->delete();

            // Delete stored contract files from disk before removing the records
            foreach ($contract->fileUploads as $upload) {
                Storage::disk($upload->disk ?? 'local')->delete($upload->file_path);
            }
            $contract->fileUploads()->delete();

            $contract->delete();
        });

        ActivityLog::record('contract.deleted', 'Contract deleted: ' . $title . ' (' . $ref . ')', $request->user(), null, ['title' => $title, 'reference' => $ref], $project->id, $project->organization_id);

        ProjectActivityService::record(
            $project,
            $request->user(),
            'contract_deleted',
            "Contract deleted: {$title}",
            "Reference: {$ref}",
            null
        );

        return response()->json(['message' => 'Contract deleted.']);
    }
}
