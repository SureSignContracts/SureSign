<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\FileUpload;
use App\Models\Project;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    private function authorizeProject(Request $request, Project $project): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $project->organization_id) abort(403, 'Access denied.');
    }

    // GET /projects/{project}/documents
    public function index(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $query = Document::where('project_id', $project->id)
            ->with('creator:id,name')
            ->latest();

        if ($request->filled('type'))     { $query->where('type', $request->type); }
        if ($request->filled('category')) { $query->where('category', $request->category); }

        return response()->json($query->paginate(50));
    }

    public function store(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $validated = $request->validate([
            'title'            => 'required|string|max:255',
            'type'             => 'nullable|string|max:100',
            'category'         => 'nullable|string|max:100',
            'status'           => 'nullable|in:draft,pending_approval,approved,issued,superseded,archived',
            'reference_number' => 'nullable|string|max:100',
            'file'             => 'nullable|file|max:51200',
        ]);

        $data = [
            'title'            => $validated['title'],
            'type'             => $validated['type']             ?? null,
            'category'         => $validated['category']         ?? null,
            'status'           => $validated['status']           ?? 'draft',
            'reference_number' => $validated['reference_number'] ?? null,
            'project_id'       => $project->id,
            'organization_id'  => $project->organization_id,
            'created_by'       => $request->user()->id,
        ];

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store("documents/{$project->id}", 'local');
            $data['file_path']  = $path;
            $data['file_name']  = $file->getClientOriginalName();
            $data['mime_type']  = $file->getMimeType();
            $data['file_size']  = $file->getSize();
        }

        $document = Document::create($data);

        return response()->json($document->load('creator:id,name'), 201);
    }

    public function show(Request $request, Document $document)
    {
        $this->authorizeProject($request, $document->project);
        return response()->json($document->load(['creator:id,name', 'project', 'documentable']));
    }

    public function update(Request $request, Document $document)
    {
        $this->authorizeProject($request, $document->project);

        $validated = $request->validate([
            'title'            => 'sometimes|string|max:255',
            'status'           => 'sometimes|in:draft,pending_approval,approved,issued,superseded,archived',
            'reference_number' => 'nullable|string|max:100',
        ]);

        $document->update($validated);
        return response()->json($document->fresh());
    }

    public function destroy(Request $request, Document $document)
    {
        $this->authorizeProject($request, $document->project);

        $fileName = $document->file_name ?? $document->title;
        $project  = $document->project;

        // Soft delete only — physical file is kept for future restore/audit
        $document->delete();

        if ($project) {
            \App\Services\ProjectActivityService::record(
                $project,
                $request->user(),
                'document_deleted',
                "Deleted document: {$fileName}",
                'subcontracts',
                $document
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Document deleted successfully.',
        ]);
    }

    // GET /documents/{document}/download
    public function download(Request $request, Document $document)
    {
        $this->authorizeProject($request, $document->project);

        if (!$document->file_path || !Storage::disk('local')->exists($document->file_path)) {
            abort(404, 'File not found.');
        }

        return Storage::disk('local')->download($document->file_path, $document->file_name);
    }

    // GET /documents/{document}/preview
    public function previewDocument(Request $request, Document $document)
    {
        $this->authorizeProject($request, $document->project);

        if (!$document->file_path || !Storage::disk('local')->exists($document->file_path)) {
            abort(404, 'File not found.');
        }

        $mimeType = $document->mime_type ?? 'application/octet-stream';

        // DOCX: serve PDF preview (generate + cache if not yet done)
        if (str_contains($mimeType, 'wordprocessingml')
            || strtolower(pathinfo($document->file_path, PATHINFO_EXTENSION)) === 'docx') {
            return $this->streamDocxAsPdf($document->file_path, $document, null);
        }

        return response()->file(
            Storage::disk('local')->path($document->file_path),
            ['Content-Type' => $mimeType]
        );
    }

    /**
     * Stream a cached PDF preview for a DOCX, generating and persisting it on first access.
     * Pass either a $document (Document model) or a $fileUpload (FileUpload model) — whichever owns the record.
     */
    private function streamDocxAsPdf(string $storagePath, ?Document $document, ?\App\Models\FileUpload $fileUpload)
    {
        // 1. Use existing PDF preview if already generated
        $existingPdfPath = $document?->preview_pdf_path ?? $fileUpload?->preview_pdf_path;

        if ($existingPdfPath && Storage::disk('local')->exists($existingPdfPath)) {
            return response()->file(
                Storage::disk('local')->path($existingPdfPath),
                ['Content-Type' => 'application/pdf']
            );
        }

        // 2. Generate PDF and persist the path for future requests
        try {
            $fullDocxPath = Storage::disk('local')->path($storagePath);
            $pdfBytes     = \App\Services\DocxToPdfService::convertToPdfBytes($fullDocxPath);

            $pdfPath = preg_replace('/\.docx$/i', '_preview.pdf', $storagePath);
            Storage::disk('local')->put($pdfPath, $pdfBytes);

            if ($document)    $document->update(['preview_pdf_path' => $pdfPath]);
            if ($fileUpload)  $fileUpload->update(['preview_pdf_path' => $pdfPath]);

            return response($pdfBytes, 200)->header('Content-Type', 'application/pdf');

        } catch (\Throwable $e) {
            \Log::warning('DOCX→PDF preview failed: ' . $e->getMessage());
            abort(422, 'Preview could not be generated for this file.');
        }
    }

    // ── File upload / management (folder-based) ──────────────────────

    public function indexFiles(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $query = FileUpload::where('project_id', $project->id)
            ->with('uploader:id,name')
            ->latest();

        if ($request->filled('folder')) {
            $query->where('folder_path', $request->query('folder'));
        }

        return response()->json($query->paginate(100));
    }

    public function uploadFile(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $request->validate([
            'file'        => 'required|file|max:51200',
            'folder_path' => 'nullable|string|max:255',
        ]);

        $file       = $request->file('file');
        $folder     = $request->input('folder_path', 'general');
        $storedName = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path       = "projects/{$project->id}/{$folder}/{$storedName}";

        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        $upload = FileUpload::create([
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'uploaded_by'     => $request->user()->id,
            'original_name'   => $file->getClientOriginalName(),
            'stored_name'     => $storedName,
            'file_path'       => $path,
            'mime_type'       => $file->getMimeType(),
            'file_size'       => $file->getSize(),
            'folder_path'     => $folder,
            'disk'            => 'local',
        ]);

        NotificationService::send(
            $request->user(),
            NotificationService::FILE_UPLOADED,
            'File Uploaded',
            $file->getClientOriginalName() . ' uploaded successfully.'
        );

        return response()->json($upload->load('uploader:id,name'), 201);
    }

    public function downloadFile(Request $request, FileUpload $fileUpload)
    {
        $user = $request->user();
        if ($user->organization_id !== $fileUpload->organization_id
            && !$user->hasRole('Super Admin') && !$user->hasRole('Admin')) {
            abort(403);
        }

        if (!Storage::disk('local')->exists($fileUpload->file_path)) {
            abort(404, 'File not found.');
        }

        return Storage::disk('local')->download($fileUpload->file_path, $fileUpload->original_name);
    }

    // GET /file-uploads/{fileUpload}/preview
    public function previewFile(Request $request, FileUpload $fileUpload)
    {
        $user = $request->user();
        if ($user->organization_id !== $fileUpload->organization_id
            && !$user->hasRole('Super Admin') && !$user->hasRole('Admin')) {
            abort(403);
        }

        if (!Storage::disk('local')->exists($fileUpload->file_path)) {
            abort(404, 'File not found.');
        }

        $mimeType = $fileUpload->mime_type ?? 'application/octet-stream';

        if (str_contains($mimeType, 'wordprocessingml')
            || strtolower(pathinfo($fileUpload->file_path, PATHINFO_EXTENSION)) === 'docx') {
            return $this->streamDocxAsPdf($fileUpload->file_path, null, $fileUpload);
        }

        return response()->file(
            Storage::disk('local')->path($fileUpload->file_path),
            ['Content-Type' => $mimeType]
        );
    }

    public function destroyFile(Request $request, FileUpload $fileUpload)
    {
        $user = $request->user();
        if (
            $user->organization_id !== $fileUpload->organization_id
            && !$user->hasRole('Super Admin')
            && !$user->hasRole('Admin')
        ) {
            abort(403, 'Access denied.');
        }

        $fileName = $fileUpload->original_name;
        $project  = $fileUpload->project;

        // Soft delete only — physical file is kept for future restore/audit
        $fileUpload->delete();

        if ($project) {
            \App\Services\ProjectActivityService::record(
                $project,
                $user,
                'document_deleted',
                "Deleted document: {$fileName}",
                'subcontracts',
                $fileUpload
            );
        }

        NotificationService::send(
            $request->user(),
            NotificationService::FILE_DELETED,
            'Document Deleted',
            'Document removed from active documents.'
        );

        return response()->json([
            'success' => true,
            'message' => 'Document deleted successfully.',
        ]);
    }

    // ── Module Explorer ───────────────────────────────────────────────

    private const MODULE_FOLDERS = [
        'contracts'            => 'Contracts',
        'subcontracts'         => 'Subcontracts',
        'commercial'           => 'Commercial',
        'payment_applications' => 'Payment Applications',
        'variations'           => 'Variations',
        'notices'              => 'Notices',
        'adjudication'         => 'Adjudication',
        'rfis'                 => 'RFIs',
        'meetings'             => 'Meetings',
        'qa_reports'           => 'QA Reports',
        'snagging'             => 'Snagging',
        'closeout'             => 'Closeout',
        'site_reports'         => 'Site Reports',
        'general'              => 'General Documents',
    ];

    /**
     * GET /projects/{project}/documents/explorer
     * Returns module folder summaries for a project.
     */
    public function projectExplorer(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $counts = FileUpload::where('project_id', $project->id)
            ->whereNotNull('module_key')
            ->select('module_key', DB::raw('count(*) as files_count'), DB::raw('max(created_at) as last_updated'))
            ->groupBy('module_key')
            ->get()
            ->keyBy('module_key');

        $generalExtra = FileUpload::where('project_id', $project->id)
            ->whereNull('module_key')
            ->count();

        $folders = [];
        foreach (self::MODULE_FOLDERS as $key => $name) {
            $row   = $counts->get($key);
            if ($key === 'subcontracts') {
                // Include backward-compat files stored with folder_key='contracts/subcontract'
                $count = FileUpload::where('project_id', $project->id)
                    ->where(function ($q) {
                        $q->where('module_key', 'subcontracts')
                          ->orWhere(function ($q2) {
                              $q2->where('folder_key', 'contracts/subcontract')
                                  ->whereNotNull('trade_package_id');
                          });
                    })
                    ->count();
            } else {
                $count = ($row ? (int) $row->files_count : 0) + ($key === 'general' ? $generalExtra : 0);
            }
            $folders[] = [
                'key'          => $key,
                'name'         => $name,
                'files_count'  => $count,
                'last_updated' => $row ? $row->last_updated : null,
            ];
        }

        return response()->json([
            'project' => [
                'id'   => $project->id,
                'name' => $project->name,
                'code' => $project->code,
            ],
            'folders' => $folders,
        ]);
    }

    /**
     * GET /projects/{project}/documents/module/{moduleKey}
     * Returns paginated files, nested contract folders, or trade package folders.
     */
    public function projectModuleFiles(Request $request, Project $project, string $moduleKey)
    {
        $this->authorizeProject($request, $project);

        if ($moduleKey === 'contracts') {
            $contractSubfolders = [
                ['key' => 'contracts/main_contract',       'name' => 'Main Contract',         'type' => 'folder', 'files_count' => 0],
                ['key' => 'contracts/consultant_agreement','name' => 'Consultant Agreements', 'type' => 'folder', 'files_count' => 0],
                ['key' => 'contracts/supplier_agreement',  'name' => 'Supplier Agreements',   'type' => 'folder', 'files_count' => 0],
            ];

            foreach ($contractSubfolders as &$subfolder) {
                $subfolder['files_count'] = FileUpload::where('project_id', $project->id)
                    ->where('folder_key', $subfolder['key'])
                    ->count();
            }
            unset($subfolder);

            return response()->json([
                'type' => 'folders',
                'folders' => $contractSubfolders,
            ]);
        }

        if ($moduleKey === 'subcontracts') {
            $tradePackages = \App\Models\TradePackage::where('project_id', $project->id)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'package_code', 'package_reference', 'contractor_name', 'description'])
                ->map(function ($pkg) use ($project) {
                    return [
                        'type'              => 'trade_package',
                        'id'                => $pkg->id,
                        'name'              => $pkg->name,
                        'package_code'      => $pkg->package_code,
                        'package_reference' => $pkg->package_reference,
                        'contractor_name'   => $pkg->contractor_name,
                        'description'       => $pkg->description,
                        'key'               => "subcontracts/package/{$pkg->id}",
                        'files_count'       => FileUpload::where('project_id', $project->id)
                                                ->where('trade_package_id', $pkg->id)
                                                ->count(),
                    ];
                });

            return response()->json([
                'type'           => 'trade_packages',
                'trade_packages' => $tradePackages,
            ]);
        }

        if ($moduleKey === 'contracts/subcontract') {
            $tradePackages = \App\Models\TradePackage::where('project_id', $project->id)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'package_code', 'package_reference'])
                ->map(function ($pkg) use ($project) {
                    return [
                        'type' => 'trade_package',
                        'id' => $pkg->id,
                        'name' => $pkg->name,
                        'package_code' => $pkg->package_code,
                        'package_reference' => $pkg->package_reference,
                        'key' => "contracts/subcontract/package/{$pkg->id}",
                        'files_count' => FileUpload::where('project_id', $project->id)
                            ->where('trade_package_id', $pkg->id)
                            ->count(),
                    ];
                });

            return response()->json([
                'type' => 'trade_packages',
                'trade_packages' => $tradePackages,
            ]);
        }

        if (preg_match('/^subcontracts\/package\/(\d+)$/', $moduleKey, $matches)) {
            $tradePackageId = (int) $matches[1];

            $tradePackage = \App\Models\TradePackage::where('project_id', $project->id)
                ->whereKey($tradePackageId)
                ->firstOrFail(['id', 'name', 'package_code', 'package_reference', 'contractor_name', 'description']);

            $paginated = FileUpload::where('project_id', $project->id)
                ->where('trade_package_id', $tradePackageId)
                ->with('uploader:id,name')
                ->latest()
                ->paginate(50);

            return response()->json([
                'type'          => 'files',
                'trade_package' => $tradePackage,
                'data'          => $paginated->items(),
                'total'         => $paginated->total(),
                'per_page'      => $paginated->perPage(),
                'from'          => $paginated->firstItem(),
                'to'            => $paginated->lastItem(),
                'current_page'  => $paginated->currentPage(),
                'last_page'     => $paginated->lastPage(),
            ]);
        }

        if (preg_match('/^contracts\/subcontract\/package\/(\d+)$/', $moduleKey, $matches)) {
            $tradePackageId = (int) $matches[1];

            $tradePackage = \App\Models\TradePackage::where('project_id', $project->id)
                ->whereKey($tradePackageId)
                ->firstOrFail(['id', 'name', 'package_code', 'package_reference', 'contractor_name', 'description']);

            $paginated = FileUpload::where('project_id', $project->id)
                ->where('trade_package_id', $tradePackageId)
                ->with('uploader:id,name')
                ->latest()
                ->paginate(50);

            return response()->json([
                'type' => 'files',
                'trade_package' => $tradePackage,
                'data' => $paginated->items(),
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'from' => $paginated->firstItem(),
                'to' => $paginated->lastItem(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ]);
        }

        $query = FileUpload::where('project_id', $project->id)
            ->with('uploader:id,name');

        if (str_starts_with($moduleKey, 'contracts/')) {
            $query->where('folder_key', $moduleKey);
        } elseif ($moduleKey === 'general') {
            $query->where(function ($q) {
                $q->where('module_key', 'general')->orWhereNull('module_key');
            });
        } else {
            $query->where('module_key', $moduleKey);
        }

        return response()->json($query->latest()->paginate(50));
    }
}
