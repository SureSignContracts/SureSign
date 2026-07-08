<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FileUpload;
use App\Models\Project;
use App\Models\TradePackage;
use App\Services\LocalDocumentMirrorService;
use App\Services\ProjectStorageService;
use App\Services\TradePackages\TradePackageActivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TradePackageController extends Controller
{
    /**
     * Validation rules for subcontract administration fields.
     * Shared between create, update, and project-scoped update.
     */
    private function subcontractRules(): array
    {
        return [
            'contract_value'       => 'nullable|numeric|min:0',
            'retention_percentage' => 'nullable|numeric|min:0|max:100',
            'payment_terms_days'   => 'nullable|integer|min:1|max:365',
            'payment_frequency'    => 'nullable|string|in:weekly,fortnightly,monthly,manual',
            // Subcontract dates
            'letter_of_intent_date'      => 'nullable|date',
            'award_date'                 => 'nullable|date',
            'execution_date'             => 'nullable|date',
            'commencement_date'          => 'nullable|date',
            'completion_date'            => 'nullable|date',
            'defects_liability_end_date' => 'nullable|date',
            // Extended contractor details
            'contractor_contact_name'   => 'nullable|string|max:255',
            'contractor_email'          => 'nullable|email|max:255',
            'contractor_phone'          => 'nullable|string|max:60',
            'contractor_address'        => 'nullable|string|max:255',
            'contractor_company_reg_no' => 'nullable|string|max:60',
            'contractor_vat_number'     => 'nullable|string|max:60',
            // Payment rule offsets
            'due_date_offset_days'        => 'nullable|integer|min:0|max:365',
            'final_date_offset_days'      => 'nullable|integer|min:0|max:365',
            'payment_notice_offset_days'  => 'nullable|integer|min:0|max:365',
            'pay_less_notice_offset_days' => 'nullable|integer|min:0|max:365',
        ];
    }

    private function authorizeProjectPackage(Request $request, Project $project, TradePackage $tradePackage): void
    {
        $user = $request->user();
        if (!$user->hasRole('Super Admin') && !$user->hasRole('Admin')
            && $user->organization_id !== $project->organization_id) {
            abort(403, 'Access denied.');
        }
        if ($tradePackage->project_id !== $project->id) {
            abort(404, 'Trade package not found for this project.');
        }
    }

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

    // ── Update ──────────────────────────────────────────────────────────────

    /**
     * PUT /api/admin/trade-packages/{tradePackage}
     */
    public function update(Request $request, TradePackage $tradePackage)
    {
        $data = $request->validate(array_merge([
            'name'                => 'sometimes|required|string|max:255',
            'package_code'        => 'nullable|string|max:50',
            'package_reference'   => 'nullable|string|max:100',
            'contractor_name'     => 'nullable|string|max:255',
            'description'         => 'nullable|string|max:2000',
            'status'              => 'nullable|string|in:' . implode(',', TradePackage::STATUSES),
        ], $this->subcontractRules()));

        $tradePackage->update($data);

        return response()->json($tradePackage->fresh());
    }

    // ── Project-scoped workspace + update (tenant-isolated) ───────────────────

    /**
     * GET /api/projects/{project}/trade-packages/{tradePackage}/workspace
     *
     * Returns the full trade package plus a read-only commercial summary and
     * folder/file overview, for the dedicated subcontract workspace page.
     */
    public function workspace(Request $request, Project $project, TradePackage $tradePackage)
    {
        $this->authorizeProjectPackage($request, $project, $tradePackage);

        $tradePackage->load('folders');

        $apps = $tradePackage->paymentApplications()
            ->select('id', 'application_number', 'status', 'application_date',
                     'gross_valuation', 'certified_amount', 'paid_amount',
                     'less_retention', 'amount_due')
            ->orderByDesc('application_number')
            ->get();

        $certifiedToDate = (float) $apps->whereIn('status', ['certified', 'paid'])->sum('certified_amount');
        $paidToDate      = (float) $apps->where('status', 'paid')->sum('paid_amount');
        $retentionHeld   = (float) $apps->whereIn('status', ['certified', 'paid'])->sum('less_retention');

        $released = (float) $tradePackage->retentionReleases()->sum('release_amount');
        $retentionHeld = max(0, $retentionHeld - $released);

        $outstanding = max(0, $certifiedToDate - $paidToDate);

        $filesCount = FileUpload::where('trade_package_id', $tradePackage->id)->count();

        return response()->json([
            'trade_package'      => $tradePackage,
            'files_count'        => $filesCount,
            'commercial_summary' => [
                'applications_count'  => $apps->count(),
                'certified_to_date'   => round($certifiedToDate, 2),
                'paid_to_date'        => round($paidToDate, 2),
                'retention_held'      => round($retentionHeld, 2),
                'retention_released'  => round($released, 2),
                'outstanding_balance' => round($outstanding, 2),
            ],
            'applications' => $apps,
        ]);
    }

    /**
     * GET /api/projects/{project}/trade-packages/{tradePackage}/activities
     *
     * Merged activity feed for this trade package — see TradePackageActivityService
     * for why this can't be a single-table query (Sprint 6C).
     */
    public function activities(Request $request, Project $project, TradePackage $tradePackage, TradePackageActivityService $activityService)
    {
        $this->authorizeProjectPackage($request, $project, $tradePackage);

        $page = max(1, (int) $request->query('page', 1));

        return response()->json($activityService->forTradePackage($tradePackage, 50, $page));
    }

    /**
     * PUT /api/projects/{project}/trade-packages/{tradePackage}
     *
     * Tenant-isolated update used by the project workspace / contracts page.
     */
    public function updateForProject(Request $request, Project $project, TradePackage $tradePackage)
    {
        $this->authorizeProjectPackage($request, $project, $tradePackage);

        $data = $request->validate(array_merge([
            'name'              => 'sometimes|required|string|max:255',
            'package_code'      => 'nullable|string|max:50',
            'package_reference' => 'nullable|string|max:100',
            'contractor_name'   => 'nullable|string|max:255',
            'description'       => 'nullable|string|max:2000',
            'status'            => 'nullable|string|in:' . implode(',', TradePackage::STATUSES),
        ], $this->subcontractRules()));

        $tradePackage->update($data);

        return response()->json($tradePackage->fresh());
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
     * POST /api/trade-packages/{tradePackage}/upload
     * Upload a file directly into a trade package (no subfolder required).
     */
    public function uploadFile(Request $request, TradePackage $tradePackage)
    {
        $project = $tradePackage->project;
        $user    = $request->user();

        if (
            $user->organization_id !== $project->organization_id
            && !$user->hasRole('Super Admin')
            && !$user->hasRole('Admin')
        ) {
            abort(403, 'Access denied.');
        }

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

        $file = $request->file('file');

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

}
