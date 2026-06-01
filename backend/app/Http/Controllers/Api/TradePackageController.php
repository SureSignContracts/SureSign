<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TradePackage;
use App\Services\LocalDocumentMirrorService;
use App\Services\ProjectStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TradePackageController extends Controller
{
    // ── List ────────────────────────────────────────────────────────────────

    /**
     * GET /api/admin/trade-packages?project_id=X
     */
    public function index(Request $request)
    {
        $projectId = $request->input('project_id');

        $query = TradePackage::orderBy('name');

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        $packages = $query->get()->map(function (TradePackage $pkg) {
            $totalFiles = FileUpload::where('trade_package_id', $pkg->id)->count();

            return array_merge($pkg->toArray(), [
                'total_files' => $totalFiles,
            ]);
        });

        return response()->json(['trade_packages' => $packages]);
    }

    // ── Create ──────────────────────────────────────────────────────────────

    /**
     * POST /api/admin/trade-packages
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'project_id'        => 'required|integer|exists:projects,id',
            'name'              => 'required|string|max:255',
            'package_code'      => 'nullable|string|max:50',
            'package_reference' => 'nullable|string|max:100',
            'contractor_name'   => 'nullable|string|max:255',
            'description'       => 'nullable|string|max:2000',
        ]);

        $project = Project::findOrFail($data['project_id']);

        $slug = TradePackage::makeSlug($data['name'], $project->id);

        $package = TradePackage::create([
            'organization_id'   => $project->organization_id,
            'project_id'        => $project->id,
            'name'              => $data['name'],
            'slug'              => $slug,
            'package_code'      => $data['package_code'] ?? null,
            'package_reference' => $data['package_reference'] ?? null,
            'contractor_name'   => $data['contractor_name'] ?? null,
            'description'       => $data['description'] ?? null,
            'status'            => 'active',
            'created_by'        => $request->user()?->id,
        ]);

        // Create local mirror folder for this package (flat — no subfolders)
        $this->mirrorTradePackageFolders($package, $project);

        return response()->json($package, 201);
    }

    // ── Create starter packages ─────────────────────────────────────────────

    /**
     * POST /api/admin/trade-packages/starters
     * Creates the three default trade packages for a project (if none exist yet).
     */
    public function storeStarters(Request $request)
    {
        $data = $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
        ]);

        $project = Project::findOrFail($data['project_id']);

        if (TradePackage::where('project_id', $project->id)->exists()) {
            return response()->json(['message' => 'Trade packages already exist for this project.'], 422);
        }

        $starters = [
            ['name' => 'Concrete Frame',   'package_code' => 'CF', 'contractor_name' => 'Harry Construction Ltd'],
            ['name' => 'Brickwork',        'package_code' => 'BW', 'contractor_name' => null],
            ['name' => 'Windows & Doors',  'package_code' => 'WD', 'contractor_name' => null],
        ];

        $created = [];
        foreach ($starters as $def) {
            $ref  = $project->code ? $project->code . '-' . $def['package_code'] : $def['package_code'];
            $slug = TradePackage::makeSlug($def['name'], $project->id);

            $package = TradePackage::create([
                'organization_id'   => $project->organization_id,
                'project_id'        => $project->id,
                'name'              => $def['name'],
                'slug'              => $slug,
                'package_code'      => $def['package_code'],
                'package_reference' => $ref,
                'contractor_name'   => $def['contractor_name'],
                'status'            => 'active',
                'created_by'        => $request->user()?->id,
            ]);

            $this->mirrorTradePackageFolders($package, $project);

            $created[] = $package;
        }

        return response()->json(['trade_packages' => $created], 201);
    }

    // ── Show ────────────────────────────────────────────────────────────────

    /**
     * GET /api/admin/trade-packages/{tradePackage}
     */
    public function show(TradePackage $tradePackage)
    {
        $totalFiles = FileUpload::where('trade_package_id', $tradePackage->id)->count();

        return response()->json(array_merge($tradePackage->load('project.organization')->toArray(), [
            'total_files' => $totalFiles,
        ]));
    }

    // ── Destroy ─────────────────────────────────────────────────────────────

    /**
     * DELETE /api/admin/trade-packages/{tradePackage}
     */
    public function destroy(TradePackage $tradePackage)
    {
        $tradePackage->delete();
        return response()->json(['message' => 'Trade package archived.']);
    }

    // ── Files ───────────────────────────────────────────────────────────────

    /**
     * GET /api/admin/trade-packages/{tradePackage}/files?document_type=X
     * Returns all files for a trade package, optionally filtered by document_type.
     */
    public function listFiles(Request $request, TradePackage $tradePackage)
    {
        $query = FileUpload::where('trade_package_id', $tradePackage->id)
            ->with(['uploader:id,name']);

        if ($request->filled('document_type')) {
            $query->where('document_type', $request->input('document_type'));
        }

        return response()->json($query->latest()->paginate(50));
    }

    /**
     * POST /api/admin/trade-packages/{tradePackage}/upload
     * Upload a file directly into a trade package (no subfolder required).
     */
    public function uploadFile(Request $request, TradePackage $tradePackage)
    {
        $allowedTypes = [
            'procurement_summary', 'tender_enquiry_letter', 'schedule_of_documents',
            'subcontract_draft', 'subcontract_template', 'executed_contract',
            'drawings', 'specification', 'pricing_document', 'correspondence',
            'returned_tender', 'insurance_document', 'health_and_safety', 'other',
        ];

        $data = $request->validate([
            'file'          => 'required|file|max:51200',
            'title'         => 'nullable|string|max:255',
            'document_type' => 'nullable|string|in:' . implode(',', $allowedTypes),
            'status'        => 'nullable|string|in:active,archived',
            'notes'         => 'nullable|string|max:2000',
        ]);

        $project = $tradePackage->project;
        $file    = $request->file('file');

        $storagePath = ProjectStorageService::buildFilePath($project, 'contracts', $file->getClientOriginalExtension());
        Storage::disk('local')->put($storagePath, file_get_contents($file->getRealPath()));

        $upload = FileUpload::create([
            'project_id'       => $project->id,
            'organization_id'  => $project->organization_id,
            'uploaded_by'      => $request->user()->id,
            'original_name'    => $file->getClientOriginalName(),
            'title'            => $data['title'] ?? null,
            'stored_name'      => basename($storagePath),
            'file_path'        => $storagePath,
            'mime_type'        => $file->getMimeType(),
            'file_size'        => $file->getSize(),
            'folder_path'      => dirname($storagePath),
            'module_key'       => 'contracts',
            'folder_key'       => 'contracts/subcontract',
            'trade_package_id' => $tradePackage->id,
            'document_type'    => $data['document_type'] ?? null,
            'status'           => $data['status'] ?? 'active',
            'disk'             => 'local',
        ]);

        LocalDocumentMirrorService::mirrorFileUpload($upload, $project);

        return response()->json($upload->load('uploader:id,name'), 201);
    }

    // ── Mirror helper ───────────────────────────────────────────────────────

    private function mirrorTradePackageFolders(TradePackage $package, Project $project): void
    {
        try {
            if (!LocalDocumentMirrorService::isEnabled()) {
                return;
            }

            $root = LocalDocumentMirrorService::getMirrorPath();
            if (empty($root)) {
                return;
            }

            $org      = $project->organization ?? \App\Models\Organization::find($project->organization_id);
            $orgName  = \App\Services\SureSignFolderPathService::sanitizeSegment($org->name ?? 'Unknown');
            $projName = \App\Services\SureSignFolderPathService::projectFolderName(
                $project->name,
                $project->code
            );

            // Create the flat package folder — files go directly inside, no subfolders
            $packageDir = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $orgName
                . DIRECTORY_SEPARATOR . $projName
                . DIRECTORY_SEPARATOR . '01_Contracts'
                . DIRECTORY_SEPARATOR . 'Subcontracts'
                . DIRECTORY_SEPARATOR . \App\Services\SureSignFolderPathService::sanitizeSegment($package->name);

            if (!is_dir($packageDir)) {
                @mkdir($packageDir, 0755, true);
            }

            Log::info('[Mirror] Trade package folder created', ['package' => $package->name, 'path' => $packageDir]);

        } catch (\Throwable $e) {
            Log::warning('[Mirror] Trade package folder creation failed', [
                'package' => $package->name,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
