<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentTemplateController extends Controller
{
    /**
     * List all global/admin templates.
     * GET /admin/templates
     */
    public function index(Request $request)
    {
        // Admins see everything: global + all org-specific templates
        $query = DocumentTemplate::with('organization:id,name')->latest();

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(fn($q) => $q->where('name', 'like', $term)->orWhere('description', 'like', $term));
        }

        $templates = $query->paginate(25)->through(fn($t) => $this->format($t));

        return response()->json($templates);
    }

    /**
     * Create a new template.
     * POST /admin/templates
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'            => 'required|string|max:255',
            'category'        => 'required|string|in:' . implode(',', array_keys(DocumentTemplate::CATEGORIES)),
            'type'            => 'nullable|in:pdf,docx,html',
            'description'     => 'nullable|string|max:1000',
            'content'         => 'nullable|string',
            'variables'       => 'nullable|array',
            'organization_id' => 'nullable|exists:organizations,id',
            'is_global'       => 'nullable|boolean',
            'is_active'       => 'nullable|boolean',
            'file'            => 'nullable|file|mimes:docx,pdf,doc|max:20480',
        ]);

        $filePath = null;
        if ($request->hasFile('file')) {
            $file     = $request->file('file');
            $filename = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '_' . time() . '.' . $file->getClientOriginalExtension();
            $filePath = $file->storeAs('templates', $filename, 'local');
        }

        $orgId = isset($data['organization_id']) && $data['organization_id'] ? (int) $data['organization_id'] : null;

        $template = DocumentTemplate::create([
            'organization_id' => $orgId,
            'name'            => $data['name'],
            'slug'            => DocumentTemplate::generateSlug($data['name']),
            'category'        => $data['category'],
            'type'            => $data['type'] ?? ($filePath ? 'docx' : 'html'),
            'description'     => $data['description'] ?? null,
            'content'         => $data['content'] ?? null,
            'variables'       => $data['variables'] ?? null,
            'file_path'       => $filePath,
            'is_global'       => $orgId ? false : ($data['is_global'] ?? true),
            'is_active'       => $data['is_active'] ?? true,
        ]);

        return response()->json(['data' => $this->format($template)], 201);
    }

    /**
     * Show a single template.
     * GET /admin/templates/{template}
     */
    public function show(DocumentTemplate $template)
    {
        return response()->json(['data' => $this->format($template)]);
    }

    /**
     * Update a template (supports file replacement via POST with _method=PUT).
     * POST /admin/templates/{template}
     */
    public function update(Request $request, DocumentTemplate $template)
    {
        $data = $request->validate([
            'name'            => 'sometimes|required|string|max:255',
            'category'        => 'sometimes|required|string|in:' . implode(',', array_keys(DocumentTemplate::CATEGORIES)),
            'type'            => 'nullable|in:pdf,docx,html',
            'description'     => 'nullable|string|max:1000',
            'content'         => 'nullable|string',
            'variables'       => 'nullable|array',
            'organization_id' => 'nullable|exists:organizations,id',
            'is_global'       => 'nullable|boolean',
            'is_active'       => 'nullable|boolean',
            'file'            => 'nullable|file|mimes:docx,pdf,doc|max:20480',
        ]);

        // If org-specific, force is_global = false
        if (!empty($data['organization_id'])) {
            $data['is_global'] = false;
        }

        if ($request->hasFile('file')) {
            // Delete old file
            if ($template->file_path) {
                Storage::disk('local')->delete($template->file_path);
            }
            $file     = $request->file('file');
            $filename = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '_' . time() . '.' . $file->getClientOriginalExtension();
            $data['file_path'] = $file->storeAs('templates', $filename, 'local');
            $data['type']      = $data['type'] ?? 'docx';
        }

        // Re-slug if name changed
        if (isset($data['name']) && $data['name'] !== $template->name) {
            $data['slug'] = DocumentTemplate::generateSlug($data['name']);
        }

        $template->update($data);

        return response()->json(['data' => $this->format($template->fresh())]);
    }

    /**
     * Delete a template.
     * DELETE /admin/templates/{template}
     */
    public function destroy(DocumentTemplate $template)
    {
        if ($template->file_path) {
            Storage::disk('local')->delete($template->file_path);
        }
        $template->delete();

        return response()->json(['message' => 'Template deleted.']);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function format(DocumentTemplate $t): array
    {
        return [
            'id'                => $t->id,
            'name'              => $t->name,
            'slug'              => $t->slug,
            'category'          => $t->category,
            'category_label'    => DocumentTemplate::CATEGORIES[$t->category] ?? $t->category,
            'type'              => $t->type,
            'description'       => $t->description,
            'content'           => $t->content,
            'variables'         => $t->variables ?? [],
            'has_file'          => !empty($t->file_path),
            'file_path'         => $t->file_path,
            'is_global'         => $t->is_global,
            'is_active'         => $t->is_active,
            'organization_id'   => $t->organization_id,
            'organization_name' => $t->organization?->name,
            'created_at'        => $t->created_at?->toDateString(),
            'updated_at'        => $t->updated_at?->toDateString(),
        ];
    }
}
