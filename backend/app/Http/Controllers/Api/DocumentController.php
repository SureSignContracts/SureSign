<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\FileUpload;
use App\Models\Project;
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
        if ($document->file_path) { Storage::disk('local')->delete($document->file_path); }
        $document->delete();
        return response()->json(['message' => 'Document deleted.']);
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

    public function destroyFile(Request $request, FileUpload $fileUpload)
    {
        $user = $request->user();
        if ($user->organization_id !== $fileUpload->organization_id
            && !$user->hasRole('Super Admin') && !$user->hasRole('Admin')) {
            abort(403);
        }

        Storage::disk('local')->delete($fileUpload->file_path);
        $fileUpload->delete();

        return response()->json(['message' => 'File deleted.']);
    }

    // ── Module Explorer ───────────────────────────────────────────────

    private const MODULE_FOLDERS = [
        'contracts'            => 'Contracts',
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
            $count = ($row ? (int) $row->files_count : 0) + ($key === 'general' ? $generalExtra : 0);
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
     * Returns paginated files in a module folder for a project.
     */
    public function projectModuleFiles(Request $request, Project $project, string $moduleKey)
    {
        $this->authorizeProject($request, $project);

        $query = FileUpload::where('project_id', $project->id)
            ->with('uploader:id,name');

        if ($moduleKey === 'general') {
            $query->where(function ($q) {
                $q->where('module_key', 'general')->orWhereNull('module_key');
            });
        } else {
            $query->where('module_key', $moduleKey);
        }

        return response()->json($query->latest()->paginate(50));
    }
}
