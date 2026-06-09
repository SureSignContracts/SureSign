<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Services\NotificationService;
use Illuminate\Support\Str;

class DocumentTemplateController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user && ($user->hasRole('Super Admin') || $user->hasRole('Admin'));

        $query = DocumentTemplate::with('organization:id,name')
            ->when(!$isAdmin, function ($q) use ($user) {
                $q->where('is_active', true)
                    ->where(function ($inner) use ($user) {
                        $inner->where('is_global', true);
                        if ($user?->organization_id) {
                            $inner->orWhere('organization_id', $user->organization_id);
                        }
                    });
            })
            ->latest();

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('template_type')) {
            $query->where('template_type', $request->template_type);
        }

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(fn($q) => $q->where('name', 'like', $term)->orWhere('description', 'like', $term));
        }

        // organization_id=0 means global (no org), any other value filters by org
        if ($request->filled('organization_id')) {
            $orgId = (int) $request->input('organization_id');
            if ($orgId === 0) $query->whereNull('organization_id');
            else              $query->where('organization_id', $orgId);
        }

        $perPage   = min((int) ($request->input('per_page', 25)), 100);
        $templates = $query->paginate($perPage)->through(fn($t) => $this->format($t));

        return response()->json($templates);
    }

    public function store(Request $request)
    {
        $allTemplateTypeKeys = implode(',', array_keys(DocumentTemplate::ALL_TEMPLATE_TYPES));

        $data = $request->validate([
            'name'            => 'required|string|max:255',
            'category'        => 'required|string|in:' . implode(',', array_keys(DocumentTemplate::CATEGORIES)),
            'template_type'   => 'nullable|string|in:' . $allTemplateTypeKeys,
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
            'template_type'   => $data['template_type'] ?? null,
            'type'            => $data['type'] ?? ($filePath ? 'docx' : 'html'),
            'description'     => $data['description'] ?? null,
            'content'         => $data['content'] ?? null,
            'variables'       => $data['variables'] ?? null,
            'file_path'       => $filePath,
            'is_global'       => $orgId ? false : ($data['is_global'] ?? true),
            'is_active'       => $data['is_active'] ?? true,
        ]);

        NotificationService::send(
            $request->user(),
            NotificationService::TEMPLATE_UPLOADED,
            'Template Uploaded',
            ($template->name ?? 'Template') . ' uploaded successfully.'
        );

        return response()->json(['data' => $this->format($template)], 201);
    }

    public function show(DocumentTemplate $template)
    {
        return response()->json(['data' => $this->format($template)]);
    }

    public function update(Request $request, DocumentTemplate $template)
    {
        $allTemplateTypeKeys = implode(',', array_keys(DocumentTemplate::ALL_TEMPLATE_TYPES));

        $data = $request->validate([
            'name'            => 'sometimes|required|string|max:255',
            'category'        => 'sometimes|required|string|in:' . implode(',', array_keys(DocumentTemplate::CATEGORIES)),
            'template_type'   => 'nullable|string|in:' . $allTemplateTypeKeys,
            'type'            => 'nullable|in:pdf,docx,html',
            'description'     => 'nullable|string|max:1000',
            'content'         => 'nullable|string',
            'variables'       => 'nullable|array',
            'organization_id' => 'nullable|exists:organizations,id',
            'is_global'       => 'nullable|boolean',
            'is_active'       => 'nullable|boolean',
            'file'            => 'nullable|file|mimes:docx,pdf,doc|max:20480',
        ]);

        if (!empty($data['organization_id'])) {
            $data['is_global'] = false;
        }

        if ($request->hasFile('file')) {
            if ($template->file_path) {
                Storage::disk('local')->delete($template->file_path);
            }
            $file     = $request->file('file');
            $filename = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '_' . time() . '.' . $file->getClientOriginalExtension();
            $data['file_path'] = $file->storeAs('templates', $filename, 'local');
            $data['type']      = $data['type'] ?? 'docx';
        }

        if (isset($data['name']) && $data['name'] !== $template->name) {
            $data['slug'] = DocumentTemplate::generateSlug($data['name']);
        }

        $template->update($data);

        return response()->json(['data' => $this->format($template->fresh())]);
    }

    public function preview(Request $request, DocumentTemplate $template)
    {
        $user = $request->user();
        if (!$user->hasRole('Super Admin') && !$user->hasRole('Admin')) {
            abort(403);
        }

        if (!$template->file_path || !Storage::disk('local')->exists($template->file_path)) {
            abort(404, 'File not found.');
        }

        $fullPath = Storage::disk('local')->path($template->file_path);
        $mimeType = Storage::disk('local')->mimeType($template->file_path) ?: 'application/octet-stream';
        $ext      = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

        if ($ext === 'docx' || str_contains($mimeType, 'wordprocessingml')) {
            try {
                $pdfBytes = \App\Services\DocxToPdfService::convertToPdfBytes($fullPath);

                return response($pdfBytes, 200)
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', 'inline; filename="' . basename($fullPath, '.docx') . '.pdf"');
            } catch (\Throwable $e) {
                \Log::warning('Template DOCX preview failed: ' . $e->getMessage());
                abort(422, 'Preview could not be generated for this template.');
            }
        }

        return response()->file($fullPath, ['Content-Type' => $mimeType]);
    }

    public function destroy(DocumentTemplate $template)
    {
        if ($template->file_path) {
            Storage::disk('local')->delete($template->file_path);
        }
        $template->delete();

        return response()->json(['message' => 'Template deleted.']);
    }

    private function format(DocumentTemplate $t): array
    {
        $templateTypeLabel = null;
        if ($t->template_type) {
            $templateTypeLabel = DocumentTemplate::ALL_TEMPLATE_TYPES[$t->template_type] ?? $t->template_type;
        }

        return [
            'id'                  => $t->id,
            'name'                => $t->name,
            'slug'                => $t->slug,
            'category'            => $t->category,
            'category_label'      => DocumentTemplate::CATEGORIES[$t->category] ?? $t->category,
            'template_type'       => $t->template_type,
            'template_type_label' => $templateTypeLabel,
            'type'                => $t->type,
            'description'         => $t->description,
            'content'             => $t->content,
            'variables'           => $t->variables ?? [],
            'has_file'            => !empty($t->file_path),
            'file_path'           => $t->file_path,
            'is_global'           => $t->is_global,
            'is_active'           => $t->is_active,
            'organization_id'     => $t->organization_id,
            'organization_name'   => $t->organization?->name,
            'created_at'          => $t->created_at?->toDateString(),
            'updated_at'          => $t->updated_at?->toDateString(),
        ];
    }
}
