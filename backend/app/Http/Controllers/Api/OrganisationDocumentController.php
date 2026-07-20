<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Documents\OrganisationDocumentService;
use Illuminate\Http\Request;

/**
 * Global Documents — organisation-wide document search and discovery.
 * Read-only. Reuses the existing DocumentController preview/download
 * pipeline entirely (see /documents/{id}/preview, /documents/{id}/download,
 * /file-uploads/{id}/preview, /file-uploads/{id}/download) — this
 * controller only returns which of those URLs applies to each result, it
 * never generates a preview or serves a file itself.
 */
class OrganisationDocumentController extends Controller
{
    public function __construct(private OrganisationDocumentService $documents) {}

    /**
     * GET /documents/portfolio
     */
    public function index(Request $request)
    {
        $params = $request->only([
            'search', 'project_id', 'module', 'document_type', 'origin',
            'ai_generated', 'file_type', 'date_from', 'date_to', 'sort', 'page', 'per_page',
        ]);

        return response()->json($this->documents->build($request->user(), $params));
    }
}
