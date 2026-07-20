<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\FileUpload;
use App\Models\Project;
use App\Models\TradePackage;
use App\Models\User;
use App\Services\Commercial\CommercialAggregationService;
use App\Services\TimezoneResolver;
use Illuminate\Support\Collection;

/**
 * Builds the Global Documents "Organisation Document Centre" payload —
 * organisation-wide document discovery across every accessible project.
 * Distinct from the project-level Documents Explorer (folder/module
 * browsing, uploads, local mirror) and from Reports/Commercial/Dashboard —
 * this is search and retrieval only. Read-only: no upload, edit, or delete
 * action exists here.
 *
 * Two separate tables back documents in this codebase and both are
 * genuinely part of "every document belonging to the organisation":
 * `documents` (workflow-generated PDFs such as payment certificates,
 * notices, variation orders, plus ad-hoc manual uploads) and `file_uploads`
 * (the folder/module-organised system behind the project Documents
 * Explorer, plus Contract and Trade Package files). Both already share the
 * same preview/download controller (DocumentController) — this service
 * only searches and normalises metadata, it never duplicates preview or
 * download logic.
 */
class OrganisationDocumentService
{
    private const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE = 50;

    private const VALID_SORTS = ['newest', 'oldest', 'filename', 'project', 'module'];

    /**
     * FileUpload rows with these attachable_type values are Help Centre
     * attachments (support tickets), never project documents — excluded
     * entirely, not merely unmapped for navigation.
     */
    private const FILE_UPLOAD_EXCLUDED_ATTACHABLE_TYPES = DocumentSourceMapper::FILE_UPLOAD_EXCLUDED_ATTACHABLE_TYPES;

    public function __construct(private CommercialAggregationService $aggregation) {}

    public function build(User $user, array $params): array
    {
        $projectIds = $this->aggregation->scopedProjectIds($user);
        $projects   = Project::whereIn('id', $projectIds)->get(['id', 'name']);
        $projectsById = $projects->keyBy('id');

        $tradePackageIds = collect()
            ->concat(Document::whereIn('project_id', $projectIds)->whereNotNull('trade_package_id')->pluck('trade_package_id'))
            ->concat(FileUpload::whereIn('project_id', $projectIds)->whereNotNull('trade_package_id')->pluck('trade_package_id'))
            ->unique();
        $tradePackagesById = TradePackage::whereIn('id', $tradePackageIds)->pluck('name', 'id');

        $documentRows = $this->fetchDocuments($projectIds, $projectsById, $tradePackagesById);
        $fileUploadRows = $this->fetchFileUploads($projectIds, $projectsById, $tradePackagesById);

        $all = $documentRows->concat($fileUploadRows);

        $summary = [
            'total_documents' => $all->count(),
            'uploaded'        => $all->where('origin', 'uploaded')->count(),
            'generated'       => $all->where('origin', 'generated')->count(),
            'ai_generated'    => $all->where('ai_generated', true)->count(),
        ];

        $filtered = $this->applyFilters($all, $params);
        $filtered = $this->applySearch($filtered, $params['search'] ?? null);

        $sort = in_array($params['sort'] ?? null, self::VALID_SORTS, true) ? $params['sort'] : 'newest';
        $sorted = $this->applySort($filtered, $sort);

        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($params['per_page'] ?? self::DEFAULT_PER_PAGE)));
        $total   = $sorted->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $pageRows = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        return [
            'summary'   => $summary,
            'documents' => [
                'data'       => $pageRows->all(),
                'pagination' => [
                    'current_page' => $page,
                    'last_page'    => $lastPage,
                    'per_page'     => $perPage,
                    'total'        => $total,
                ],
            ],
            'filters' => [
                'projects'       => $projects->map(fn(Project $p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
                'modules'        => $all->pluck('module')->filter()->unique()->sort()->values()->all(),
                'document_types' => $all->pluck('document_type')->filter()->unique()->sort()->values()->all(),
                'file_types'     => $all->pluck('file_type')->filter()->unique()->sort()->values()->all(),
                'origins'        => ['uploaded', 'generated'],
            ],
            'meta' => [
                'effective_timezone' => TimezoneResolver::effectiveTimezone($user, $user->organization),
                'generated_at'       => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Document rows — one batched query, then normalised. `documentable_type
     * IS NOT NULL` means the document was generated by a workflow (payment
     * certificate, notice, variation order, etc.); `documentable_type IS
     * NULL` means a plain manual upload via DocumentController::store().
     * `ai_generated` is a genuine stored boolean column, never inferred.
     */
    private function fetchDocuments(Collection $projectIds, Collection $projectsById, Collection $tradePackagesById): Collection
    {
        return Document::whereIn('project_id', $projectIds)
            ->with('creator:id,name')
            ->get([
                'id', 'project_id', 'trade_package_id', 'title', 'type', 'category', 'reference_number',
                'status', 'documentable_type', 'documentable_id', 'file_name', 'mime_type', 'file_size',
                'ai_generated', 'created_by', 'created_at',
            ])
            ->map(function (Document $document) use ($projectsById, $tradePackagesById) {
                $project = $projectsById->get($document->project_id);
                if (!$project) return null;

                return [
                    'source'          => 'document',
                    'id'              => $document->id,
                    'composite_id'    => "document:{$document->id}",
                    'filename'        => $document->file_name ?? $document->title,
                    'title'           => $document->title,
                    'reference'       => $document->reference_number,
                    'project_id'      => $project->id,
                    'project_name'    => $project->name,
                    'trade_package'   => $document->trade_package_id ? ($tradePackagesById[$document->trade_package_id] ?? null) : null,
                    'module'          => $document->category,
                    'document_type'   => $document->type,
                    'status'          => $document->status,
                    'origin'          => $document->documentable_type ? 'generated' : 'uploaded',
                    'ai_generated'    => (bool) $document->ai_generated,
                    'created_at'      => $document->created_at?->toIso8601String(),
                    'uploaded_by'     => $document->creator?->name,
                    'file_size'       => $document->file_size,
                    'mime_type'       => $document->mime_type,
                    'file_type'       => self::fileTypeFromMime($document->mime_type),
                    'action_url'      => DocumentSourceMapper::actionUrlForDocument($document),
                    'preview_url'     => "/documents/{$document->id}/preview",
                    'download_url'    => "/documents/{$document->id}/download",
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * FileUpload rows — one batched query, excluding Help Centre
     * attachments (support tickets), which are never project documents.
     * Every FileUpload row is 'uploaded' origin: no code path in this
     * application ever creates one as generated or AI-generated (confirmed
     * by an exhaustive audit of every FileUpload::create() call site).
     */
    private function fetchFileUploads(Collection $projectIds, Collection $projectsById, Collection $tradePackagesById): Collection
    {
        return FileUpload::whereIn('project_id', $projectIds)
            // whereNotIn alone would silently exclude every row where
            // attachable_type IS NULL (SQL's `NULL NOT IN (...)` is UNKNOWN,
            // not true) — the vast majority of FileUpload rows (plain
            // uploads, trade package files) have no attachable_type at all,
            // so that condition must be explicitly allowed through.
            ->where(fn($q) => $q->whereNull('attachable_type')->orWhereNotIn('attachable_type', self::FILE_UPLOAD_EXCLUDED_ATTACHABLE_TYPES))
            ->with('uploader:id,name')
            ->get([
                'id', 'project_id', 'trade_package_id', 'attachable_type', 'attachable_id',
                'original_name', 'module_key', 'document_type', 'status', 'mime_type', 'file_size',
                'uploaded_by', 'created_at',
            ])
            ->map(function (FileUpload $fileUpload) use ($projectsById, $tradePackagesById) {
                $project = $projectsById->get($fileUpload->project_id);
                if (!$project) return null;

                return [
                    'source'          => 'file_upload',
                    'id'              => $fileUpload->id,
                    'composite_id'    => "file_upload:{$fileUpload->id}",
                    'filename'        => $fileUpload->original_name,
                    'title'           => null,
                    'reference'       => null,
                    'project_id'      => $project->id,
                    'project_name'    => $project->name,
                    'trade_package'   => $fileUpload->trade_package_id ? ($tradePackagesById[$fileUpload->trade_package_id] ?? null) : null,
                    'module'          => $fileUpload->module_key,
                    'document_type'   => $fileUpload->document_type,
                    'status'          => $fileUpload->status,
                    'origin'          => 'uploaded',
                    'ai_generated'    => false,
                    'created_at'      => $fileUpload->created_at?->toIso8601String(),
                    'uploaded_by'     => $fileUpload->uploader?->name,
                    'file_size'       => $fileUpload->file_size,
                    'mime_type'       => $fileUpload->mime_type,
                    'file_type'       => self::fileTypeFromMime($fileUpload->mime_type),
                    'action_url'      => DocumentSourceMapper::actionUrlForFileUpload($fileUpload),
                    'preview_url'     => "/file-uploads/{$fileUpload->id}/preview",
                    'download_url'    => "/file-uploads/{$fileUpload->id}/download",
                ];
            })
            ->filter()
            ->values();
    }

    private static function fileTypeFromMime(?string $mimeType): ?string
    {
        if (!$mimeType) return null;

        return match (true) {
            str_contains($mimeType, 'pdf')             => 'PDF',
            str_contains($mimeType, 'wordprocessingml') => 'DOCX',
            str_contains($mimeType, 'spreadsheetml')    => 'XLSX',
            str_contains($mimeType, 'msword')           => 'DOC',
            str_contains($mimeType, 'ms-excel')         => 'XLS',
            str_contains($mimeType, 'image/')           => 'Image',
            str_contains($mimeType, 'csv')               => 'CSV',
            str_contains($mimeType, 'text/')             => 'Text',
            default                                       => 'File',
        };
    }

    private function applyFilters(Collection $rows, array $params): Collection
    {
        $project      = $params['project_id'] ?? null;
        $module       = $params['module'] ?? null;
        $documentType = $params['document_type'] ?? null;
        $origin       = $params['origin'] ?? null;
        $aiOnly       = $params['ai_generated'] ?? null;
        $fileType     = $params['file_type'] ?? null;
        $dateFrom     = $params['date_from'] ?? null;
        $dateTo       = $params['date_to'] ?? null;

        return $rows->filter(function (array $row) use ($project, $module, $documentType, $origin, $aiOnly, $fileType, $dateFrom, $dateTo) {
            if ($project && (int) $project !== $row['project_id']) return false;
            if ($module && $row['module'] !== $module) return false;
            if ($documentType && $row['document_type'] !== $documentType) return false;
            if ($origin && $row['origin'] !== $origin) return false;
            if ($aiOnly && !$row['ai_generated']) return false;
            if ($fileType && $row['file_type'] !== $fileType) return false;
            if ($dateFrom && ($row['created_at'] === null || $row['created_at'] < $dateFrom)) return false;
            if ($dateTo && ($row['created_at'] === null || $row['created_at'] > $dateTo . 'T23:59:59+00:00')) return false;
            return true;
        })->values();
    }

    /**
     * Search across filename, title, reference number, project name, and
     * trade package name — the fields genuinely populated and consistently
     * available across both tables. Contract reference and free-text tags
     * are deliberately not included: neither is a real, reliably populated
     * field on Document/FileUpload (see the Final Delivery Report).
     */
    private function applySearch(Collection $rows, ?string $search): Collection
    {
        $search = trim((string) $search);
        if ($search === '') {
            return $rows;
        }

        $needle = mb_strtolower($search);

        return $rows->filter(function (array $row) use ($needle) {
            $haystack = mb_strtolower(implode(' ', array_filter([
                $row['filename'], $row['title'], $row['reference'], $row['project_name'], $row['trade_package'],
            ])));
            return str_contains($haystack, $needle);
        })->values();
    }

    private function applySort(Collection $rows, string $sort): Collection
    {
        return match ($sort) {
            'oldest'   => $rows->sortBy('created_at')->values(),
            'filename' => $rows->sortBy(fn($r) => mb_strtolower((string) $r['filename']))->values(),
            'project'  => $rows->sortBy(fn($r) => mb_strtolower($r['project_name']))->values(),
            'module'   => $rows->sortBy(fn($r) => mb_strtolower((string) $r['module']))->values(),
            default    => $rows->sortByDesc('created_at')->values(), // newest
        };
    }
}
