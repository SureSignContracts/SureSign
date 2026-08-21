<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentRegister;
use App\Models\Project;
use App\Services\DocumentNumberService;
use Illuminate\Http\Request;

class DocumentRegisterController extends Controller
{
    public function __construct(private DocumentNumberService $numberService) {}

    /**
     * Mirrors the standard SureSign project-authorization convention used
     * across every other project-nested controller (e.g.
     * RiskController::authorize()) — Super Admin/Admin bypass, otherwise
     * strict organisation match.
     */
    private function authorize(Request $request, Project $project): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $project->organization_id) abort(403, 'Access denied.');
    }

    /**
     * GET /api/projects/{project}/document-register
     *
     * Security fix (P0, 2026-08-21) — this previously received the route
     * segment as a plain `int $projectId`, never resolved through Laravel's
     * implicit model binding, and performed no authorization check at all:
     * any authenticated user (any organisation) could request another
     * organisation's Project ID and receive its Document Register metadata
     * (document numbers/titles, project name/code, trade package names).
     * Changing the parameter to a real, route-bound `Project $project` and
     * calling the new authorize() above closes it — the route's own
     * `{project}` segment name already matches, so no route file change
     * was needed.
     */
    public function index(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $query = DocumentRegister::with(['package:id,name,package_code'])
            ->where('project_id', $project->id)
            ->orderBy('created_at', 'desc');

        if ($search = $request->input('search')) {
            $query->where(fn($q) => $q->where('document_number', 'like', "%{$search}%")
                                      ->orWhere('title', 'like', "%{$search}%"));
        }
        if ($type = $request->input('document_type')) {
            $query->where('document_type', strtoupper($type));
        }
        if ($packageId = $request->input('package_id')) {
            $query->where('package_id', $packageId);
        }

        $perPage = min((int) ($request->input('per_page', 25)), 100);
        $results = $query->paginate($perPage)->through(fn($r) => $this->formatEntry($r));

        return response()->json($results);
    }

    /**
     * GET /api/document-types
     */
    public function types()
    {
        $types = collect(DocumentNumberService::TYPES)
            ->map(fn($label, $code) => ['code' => $code, 'label' => $label])
            ->values();

        return response()->json(['data' => $types]);
    }

    /**
     * GET /api/admin/document-register
     */
    public function adminIndex(Request $request)
    {
        $query = DocumentRegister::with([
                'project:id,name,code',
                'package:id,name,package_code',
            ])
            ->orderBy('created_at', 'desc');

        if ($search = $request->input('search')) {
            $query->where(fn($q) => $q->where('document_number', 'like', "%{$search}%")
                                      ->orWhere('title', 'like', "%{$search}%"));
        }
        if ($type = $request->input('document_type')) {
            $query->where('document_type', strtoupper($type));
        }
        if ($projectId = $request->input('project_id')) {
            $query->where('project_id', $projectId);
        }

        $perPage = min((int) ($request->input('per_page', 25)), 100);
        $results = $query->paginate($perPage)->through(fn($r) => $this->formatEntry($r));

        return response()->json($results);
    }

    /**
     * GET /api/admin/document-register/projects
     * Projects that have at least one document register entry (for filter dropdown).
     */
    public function adminProjects()
    {
        $projects = Project::whereHas('documentRegister')
            ->select('id', 'name', 'code')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $projects]);
    }

    // ── Shared formatter ──────────────────────────────────────────────────────

    private function formatEntry(DocumentRegister $r): array
    {
        return [
            'id'                 => $r->id,
            'document_number'    => $r->document_number,
            'title'              => $r->title,
            'document_type'      => $r->document_type,
            'document_type_label'=> DocumentNumberService::TYPES[$r->document_type] ?? $r->document_type,
            'project_id'         => $r->project_id,
            'project_name'       => $r->project?->name,
            'project_code'       => $r->project?->code,
            'package_name'       => $r->package?->name,
            'created_at'         => $r->created_at,
        ];
    }
}
