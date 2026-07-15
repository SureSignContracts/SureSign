<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\FileUpload;
use App\Models\Project;
use App\Models\SuresignSetting;
use App\Services\FileSecurityService;
use App\Services\NotificationService;
use App\Services\TradePackages\WorkspaceNavigationResolver;
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
            'file'             => 'nullable|file|max:' . SuresignSetting::maxUploadKb(),
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
            FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);
            $storedName = FileSecurityService::randomStorageName($file);
            $path = $file->storeAs("documents/{$project->id}", $storedName, 'local');
            $data['file_path']  = $path;
            $data['file_name']  = FileSecurityService::sanitizeDisplayName($file->getClientOriginalName());
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

        return Storage::disk('local')->download(
            $document->file_path,
            $document->file_name,
            ['X-Content-Type-Options' => 'nosniff']
        );
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
            self::safeInlineHeaders($mimeType)
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
            'file'          => 'required|file|max:' . SuresignSetting::maxUploadKb(),
            'folder_path'   => 'nullable|string|max:255',
            'module_key'    => 'nullable|string|max:100',
            'folder_key'    => 'nullable|string|max:255',
            'document_type' => 'nullable|string|max:100',
            'title'         => 'nullable|string|max:255',
        ]);

        $file       = $request->file('file');
        FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);

        $moduleKey  = $request->input('module_key', 'general');
        $folderKey  = $request->input('folder_key') ?: $moduleKey;
        // folder_path is client-supplied — strip traversal sequences and
        // anything outside a safe path-segment charset before it is ever
        // interpolated into a storage path.
        $folderPath = self::sanitizeFolderPath($request->input('folder_path', $moduleKey));
        $storedName = FileSecurityService::randomStorageName($file);
        $path       = "projects/{$project->id}/{$folderPath}/{$storedName}";

        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        $upload = FileUpload::create([
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'uploaded_by'     => $request->user()->id,
            'original_name'   => $request->input('title') ?: FileSecurityService::sanitizeDisplayName($file->getClientOriginalName()),
            'stored_name'     => $storedName,
            'file_path'       => $path,
            'mime_type'       => $file->getMimeType(),
            'file_size'       => $file->getSize(),
            'folder_path'     => $folderPath,
            'module_key'      => $moduleKey,
            'folder_key'      => $folderKey,
            'document_type'   => $request->input('document_type'),
            'source_type'     => 'uploaded',
            'disk'            => 'local',
        ]);

        // Routine, synchronous event — actor already saw the upload succeed;
        // other Client users on the project's org are the ones who need telling.
        NotificationService::sendToOrganization(
            $project->organization,
            NotificationService::FILE_UPLOADED,
            'File Uploaded',
            FileSecurityService::sanitizeDisplayName($file->getClientOriginalName()) . ' was uploaded to ' . $project->name . '.',
            [],
            ['project_id' => $project->id, 'organization_id' => $project->organization_id,
             'source_type' => 'file_upload', 'source_id' => $upload->id,
             'action_url' => WorkspaceNavigationResolver::actionUrl($project->id, 'file_upload', $upload->id, $upload->trade_package_id)],
            $request->user(),
        );

        return response()->json($upload->load('uploader:id,name'), 201);
    }

    /**
     * Reduce a client-supplied folder path to safe, traversal-free segments
     * before it is interpolated into a storage path. Only letters, numbers,
     * spaces, dashes, underscores and single forward slashes survive.
     */
    private static function sanitizeFolderPath(string $folderPath): string
    {
        $segments = array_filter(array_map('trim', explode('/', $folderPath)), function ($segment) {
            return $segment !== '' && $segment !== '.' && $segment !== '..';
        });

        $safeSegments = array_map(function ($segment) {
            return preg_replace('/[^A-Za-z0-9 _-]/', '', $segment);
        }, $segments);

        $safeSegments = array_filter($safeSegments, fn ($segment) => $segment !== '');

        return $safeSegments !== [] ? implode('/', $safeSegments) : 'general';
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

        return Storage::disk('local')->download(
            $fileUpload->file_path,
            $fileUpload->original_name,
            ['X-Content-Type-Options' => 'nosniff']
        );
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
            self::safeInlineHeaders($mimeType)
        );
    }

    /**
     * Headers for `response()->file()` (inline) previews. Never render
     * uploaded HTML/SVG/XML/JS as active content — anything outside the
     * small set of formats known to be safe for inline display falls back
     * to a forced download instead of inline rendering.
     */
    private static function safeInlineHeaders(string $mimeType): array
    {
        $safeInlineMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

        $headers = [
            'Content-Type'           => $mimeType,
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (!in_array($mimeType, $safeInlineMimes, true)) {
            $headers['Content-Disposition'] = 'attachment';
        }

        return $headers;
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

        NotificationService::sendToOrganization(
            $fileUpload->organization,
            NotificationService::FILE_DELETED,
            'Document Deleted',
            "{$fileName} was removed from active documents.",
            [],
            [
                'project_id' => $project?->id, 'organization_id' => $fileUpload->organization_id,
                'source_type' => 'file_upload', 'source_id' => $fileUpload->id,
                'action_url' => $project
                    ? WorkspaceNavigationResolver::actionUrl($project->id, 'file_upload', $fileUpload->id, $fileUpload->trade_package_id)
                    : null,
            ],
            $user,
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
     * Maps module keys to the Document.category values used by DocumentGenerationService.
     * Used to surface generated PDF Documents alongside uploaded FileUploads.
     */
    private const MODULE_DOCUMENT_CATEGORY_MAP = [
        'contracts'            => '01_Contracts',
        'contracts/main_contract' => '01_Contracts',
        'commercial'           => '02_Commercial',
        'payment_applications' => '02_Commercial',
        'variations'           => '04_Variations',
        'notices'              => '02_Commercial',
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

        // Count generated Document records per category so folder badges reflect them too
        $docCounts = Document::where('project_id', $project->id)
            ->select('category', DB::raw('count(*) as doc_count'), DB::raw('max(created_at) as last_doc'))
            ->groupBy('category')
            ->get()
            ->keyBy('category');

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

            // Add generated Document counts for modules that produce PDFs
            $docCategory = self::MODULE_DOCUMENT_CATEGORY_MAP[$key] ?? null;
            if ($docCategory) {
                $docRow = $docCounts->get($docCategory);
                if ($docRow) {
                    $count += (int) $docRow->doc_count;
                    // Use the more recent timestamp for last_updated
                    $docLast  = $row?->last_updated;
                    $fileLast = $docRow->last_doc;
                    $row = (object) ['last_updated' => max($docLast, $fileLast)];
                }
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
                ['key' => 'contracts/subcontract',         'name' => 'Subcontract Agreements','type' => 'folder', 'files_count' => 0],
            ];

            foreach ($contractSubfolders as &$subfolder) {
                if ($subfolder['key'] === 'contracts/main_contract') {
                    // Backward-compat: old uploads used folder_key='contracts'; treat them as main contract
                    $subfolder['files_count'] = FileUpload::where('project_id', $project->id)
                        ->where(function ($q) {
                            $q->where('folder_key', 'contracts/main_contract')
                              ->orWhere(function ($q2) {
                                  $q2->where('module_key', 'contracts')
                                     ->whereIn('folder_key', ['contracts', ''])
                                     ->orWhere(function ($q3) {
                                         $q3->where('module_key', 'contracts')->whereNull('folder_key');
                                     });
                              });
                        })
                        ->count();
                } else {
                    $subfolder['files_count'] = FileUpload::where('project_id', $project->id)
                        ->where('folder_key', $subfolder['key'])
                        ->count();
                }
            }
            unset($subfolder);

            return response()->json([
                'type' => 'folders',
                'folders' => $contractSubfolders,
            ]);
        }

        if ($moduleKey === 'subcontracts') {
            $tradePackages = \App\Models\TradePackage::where('project_id', $project->id)
                ->whereNotIn('status', ['archived', 'inactive'])
                ->orderBy('name')
                ->get(['id', 'name', 'package_code', 'package_reference', 'contractor_name', 'description', 'status', 'contract_value', 'retention_percentage', 'is_custom'])
                ->map(function ($pkg) use ($project) {
                    return [
                        'type'              => 'trade_package',
                        'id'                => $pkg->id,
                        'name'              => $pkg->name,
                        'package_code'      => $pkg->package_code,
                        'package_reference' => $pkg->package_reference,
                        'contractor_name'   => $pkg->contractor_name,
                        'description'       => $pkg->description,
                        'status'            => $pkg->status,
                        'contract_value'    => $pkg->contract_value,
                        'retention_percentage' => $pkg->retention_percentage,
                        'is_custom'         => (bool) $pkg->is_custom,
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
                ->whereNotIn('status', ['archived', 'inactive'])
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

            // Generated documents (notices, certificates, statements, package docs) live in
            // a separate `documents` table — tagged with trade_package_id since Sprint 6C so
            // they can be surfaced here alongside uploaded files, without a second document
            // system or a second UI.
            $generatedDocuments = \App\Models\Document::where('trade_package_id', $tradePackageId)
                ->with(['creator:id,name', 'documentable'])
                ->latest()
                ->get()
                ->map(function (\App\Models\Document $doc) {
                    $doc->setAttribute('source', $this->classifyDocumentSource($doc));
                    return $doc;
                });

            return response()->json([
                'type'                => 'files',
                'trade_package'       => $tradePackage,
                'data'                => $paginated->items(),
                'total'               => $paginated->total(),
                'per_page'            => $paginated->perPage(),
                'from'                => $paginated->firstItem(),
                'to'                  => $paginated->lastItem(),
                'current_page'        => $paginated->currentPage(),
                'last_page'           => $paginated->lastPage(),
                'generated_documents' => $generatedDocuments,
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

        if ($moduleKey === 'contracts/main_contract') {
            // Also surface old uploads that used the legacy folder_key='contracts'
            $query->where(function ($q) {
                $q->where('folder_key', 'contracts/main_contract')
                  ->orWhere(function ($q2) {
                      $q2->where('module_key', 'contracts')
                         ->where(function ($q3) {
                             $q3->where('folder_key', 'contracts')
                                ->orWhere('folder_key', '')
                                ->orWhereNull('folder_key');
                         });
                  });
            });
        } elseif (str_starts_with($moduleKey, 'contracts/')) {
            $query->where('folder_key', $moduleKey);
        } elseif ($moduleKey === 'general') {
            $query->where(function ($q) {
                $q->where('module_key', 'general')->orWhereNull('module_key');
            });
        } else {
            $query->where('module_key', $moduleKey);
        }

        $paginated = $query->latest()->paginate(50);

        // Attach generated Document records for modules that produce PDFs
        // (e.g. Contract Intelligence Briefs in 'contracts', Variation PDFs in 'variations').
        // Returned as a separate `generated_docs` array so pagination isn't affected and
        // the frontend can use Document-specific download/preview endpoints.
        $generatedDocs = [];
        $docCategory   = self::MODULE_DOCUMENT_CATEGORY_MAP[$moduleKey] ?? null;
        if ($docCategory) {
            $generatedDocs = Document::where('project_id', $project->id)
                ->where('category', $docCategory)
                ->with('creator:id,name')
                ->latest()
                ->get()
                ->map(fn ($doc) => [
                    'id'               => $doc->id,
                    'title'            => $doc->title,
                    'file_name'        => $doc->file_name,
                    'type'             => $doc->type,
                    'reference_number' => $doc->reference_number,
                    'mime_type'        => $doc->mime_type ?? 'application/pdf',
                    'file_size'        => $doc->file_size ?? 0,
                    'created_at'       => $doc->created_at,
                    'creator'          => $doc->creator
                        ? ['id' => $doc->creator->id, 'name' => $doc->creator->name]
                        : null,
                ])
                ->toArray();
        }

        return response()->json(array_merge(
            $paginated->toArray(),
            ['generated_docs' => $generatedDocs],
        ));
    }

    /**
     * Sprint 6D Phase 3 — resolves a generated Document's `documentable` polymorphic
     * relation into a human-readable label plus a workspace tab/subtab reference,
     * mirroring the same source-classification convention used by
     * TradePackageActivityService and CalendarController this sprint.
     */
    private function classifyDocumentSource(\App\Models\Document $document): ?array
    {
        $model = $document->documentable;
        if (!$model) {
            return null;
        }

        return match (get_class($model)) {
            \App\Models\DelayEvent::class => [
                'type' => 'delay_event', 'id' => $model->id,
                'label' => "Delay Event #{$model->event_number}: {$model->title}",
                'tab' => 'delay-eot', 'subtab' => 'delay',
            ],
            \App\Models\EotRequest::class => [
                'type' => 'eot_request', 'id' => $model->id,
                'label' => "EOT #{$model->eot_number}: {$model->title}",
                'tab' => 'delay-eot', 'subtab' => 'eot',
            ],
            \App\Models\LossAndExpenseClaim::class => [
                'type' => 'loss_and_expense_claim', 'id' => $model->id,
                'label' => "L&E Claim #{$model->claim_number}: {$model->title}",
                'tab' => 'delay-eot', 'subtab' => 'loss-expense',
            ],
            \App\Models\FinalAccount::class => [
                'type' => 'final_account', 'id' => $model->id,
                'label' => "Final Account {$model->reference}",
                'tab' => 'commercial', 'subtab' => null,
            ],
            \App\Models\PaymentApplication::class => [
                'type' => 'payment_application', 'id' => $model->id,
                'label' => "Payment Application #{$model->application_number}",
                'tab' => 'commercial', 'subtab' => null,
            ],
            \App\Models\PaymentNotice::class => [
                'type' => 'payment_notice', 'id' => $model->id,
                'label' => 'Payment Notice' . ($model->reference ? " ({$model->reference})" : ''),
                'tab' => 'commercial', 'subtab' => null,
            ],
            \App\Models\PayLessNotice::class => [
                'type' => 'pay_less_notice', 'id' => $model->id,
                'label' => 'Pay Less Notice' . ($model->reference ? " ({$model->reference})" : ''),
                'tab' => 'commercial', 'subtab' => null,
            ],
            \App\Models\TradePackage::class => [
                'type' => 'trade_package', 'id' => $model->id,
                'label' => 'Trade Package generation',
                'tab' => 'overview', 'subtab' => null,
            ],
            default => null,
        };
    }
}
