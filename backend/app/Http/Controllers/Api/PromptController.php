<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\PromptCategory;
use App\Models\PromptCopyLog;
use App\Models\PromptFavorite;
use App\Models\PromptTemplate;
use App\Services\PromptRenderService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PromptController extends Controller
{
    // -------------------------------------------------------------------------
    // CATEGORIES
    // -------------------------------------------------------------------------

    public function indexCategories()
    {
        $categories = PromptCategory::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->withCount(['templates as active_templates_count' => function ($q) {
                $q->where('is_active', true)->whereNull('deleted_at');
            }])
            ->get();

        return response()->json($categories);
    }

    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string',
            'icon'        => 'nullable|string|max:50',
            'sort_order'  => 'nullable|integer|min:0',
            'is_active'   => 'nullable|boolean',
        ]);

        $validated['slug'] = Str::slug($validated['name']);

        $category = PromptCategory::create($validated);

        return response()->json($category, 201);
    }

    public function updateCategory(Request $request, PromptCategory $category)
    {
        $validated = $request->validate([
            'name'        => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'icon'        => 'nullable|string|max:50',
            'sort_order'  => 'nullable|integer|min:0',
            'is_active'   => 'nullable|boolean',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $category->update($validated);

        return response()->json($category);
    }

    public function destroyCategory(PromptCategory $category)
    {
        $category->delete();
        return response()->json(['message' => 'Category deleted.']);
    }

    // -------------------------------------------------------------------------
    // TEMPLATES
    // -------------------------------------------------------------------------

    public function indexTemplates(Request $request)
    {
        $query = PromptTemplate::with('category:id,name,slug,icon')
            ->where('is_active', true);

        if ($request->filled('category')) {
            $query->whereHas('category', fn($q) => $q->where('slug', $request->category));
        }

        if ($request->filled('module')) {
            $query->where('module', $request->module);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('use_case', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        // Attach favorites for the authenticated user
        $userId = $request->user()->id;
        $query->withExists(['favorites as is_favorited' => function ($q) use ($userId) {
            $q->where('user_id', $userId);
        }]);

        $templates = $query->orderBy('sort_order')->orderBy('title')->paginate(50);

        return response()->json($templates);
    }

    public function showTemplate(Request $request, PromptTemplate $template)
    {
        $userId = $request->user()->id;
        $template->load('category:id,name,slug,icon');
        $template->is_favorited = PromptFavorite::where('user_id', $userId)
            ->where('prompt_template_id', $template->id)
            ->exists();

        return response()->json($template);
    }

    public function storeTemplate(Request $request)
    {
        $validated = $request->validate([
            'prompt_category_id' => 'nullable|exists:prompt_categories,id',
            'title'              => 'required|string|max:255',
            'description'        => 'nullable|string',
            'prompt_text'        => 'required|string',
            'module'             => 'nullable|string|max:100',
            'use_case'           => 'nullable|string|max:255',
            'variables'          => 'nullable|array',
            'is_global'          => 'nullable|boolean',
            'is_active'          => 'nullable|boolean',
            'is_featured'        => 'nullable|boolean',
            'sort_order'         => 'nullable|integer|min:0',
        ]);

        $validated['slug']       = Str::slug($validated['title']) . '-' . Str::random(5);
        $validated['created_by'] = $request->user()->id;

        $template = PromptTemplate::create($validated);
        $template->load('category:id,name,slug');

        return response()->json($template, 201);
    }

    public function updateTemplate(Request $request, PromptTemplate $template)
    {
        $validated = $request->validate([
            'prompt_category_id' => 'nullable|exists:prompt_categories,id',
            'title'              => 'sometimes|string|max:255',
            'description'        => 'nullable|string',
            'prompt_text'        => 'sometimes|string',
            'module'             => 'nullable|string|max:100',
            'use_case'           => 'nullable|string|max:255',
            'variables'          => 'nullable|array',
            'is_global'          => 'nullable|boolean',
            'is_active'          => 'nullable|boolean',
            'is_featured'        => 'nullable|boolean',
            'sort_order'         => 'nullable|integer|min:0',
        ]);

        $template->update($validated);
        $template->load('category:id,name,slug');

        return response()->json($template);
    }

    public function destroyTemplate(PromptTemplate $template)
    {
        $template->delete();
        return response()->json(['message' => 'Prompt deleted.']);
    }

    // -------------------------------------------------------------------------
    // RENDER (placeholder resolution)
    // -------------------------------------------------------------------------

    public function render(Request $request, PromptTemplate $template)
    {
        $request->validate([
            'project_id'  => 'nullable|exists:projects,id',
            'record_type' => 'nullable|string|in:contract,payment_application,variation,rfi,meeting,qa_report,snag,adjudication_case,document',
            'record_id'   => 'nullable|integer',
            'extra'       => 'nullable|array',
        ]);

        $service = new PromptRenderService();
        $project = null;

        if ($request->filled('project_id')) {
            $project = Project::find($request->project_id);
            // Non-admin users may only access their own organisation's projects
            if ($project && ! $request->user()->hasRole(['Super Admin', 'Admin'])) {
                if ($project->organization_id !== $request->user()->organization_id) {
                    return response()->json(['message' => 'Unauthorized.'], 403);
                }
            }
        }

        $context = array_merge(
            $service->buildBaseContext(),
            ['user_name' => $request->user()->name],
            $project ? $service->buildProjectContext($project) : [],
            ($request->filled('record_type') && $request->filled('record_id'))
                ? $service->buildRecordContext($request->record_type, (int) $request->record_id, $project)
                : [],
            $request->extra ?? []
        );

        $renderedPrompt  = $service->replacePlaceholders($template->prompt_text, $context);
        $allPlaceholders = $service->extractVariables($template->prompt_text);
        $used    = array_values(array_filter($allPlaceholders, fn($p) => isset($context[$p]) && $context[$p] !== ''));
        $missing = array_values(array_filter($allPlaceholders, fn($p) => ! isset($context[$p]) || $context[$p] === ''));

        return response()->json([
            'rendered_prompt'      => $renderedPrompt,
            'placeholders_used'    => $used,
            'missing_placeholders' => $missing,
        ]);
    }

    // -------------------------------------------------------------------------
    // COPY LOG
    // -------------------------------------------------------------------------

    public function copyTemplate(Request $request, PromptTemplate $template)
    {
        $request->validate([
            'project_id'      => 'nullable|exists:projects,id',
            'rendered_prompt' => 'nullable|string',
        ]);

        $template->incrementCopiedCount();

        PromptCopyLog::create([
            'user_id'                => $request->user()->id,
            'prompt_template_id'     => $template->id,
            'project_id'             => $request->project_id,
            'organization_id'        => $request->user()->organization_id,
            'copied_prompt_snapshot' => $request->rendered_prompt ?? $template->prompt_text,
            'created_at'             => now(),
        ]);

        return response()->json([
            'message'      => 'Copy logged.',
            'copied_count' => $template->fresh()->copied_count,
        ]);
    }

    // -------------------------------------------------------------------------
    // FAVORITES
    // -------------------------------------------------------------------------

    public function favoriteTemplate(Request $request, PromptTemplate $template)
    {
        PromptFavorite::firstOrCreate([
            'user_id'             => $request->user()->id,
            'prompt_template_id'  => $template->id,
        ]);

        return response()->json(['message' => 'Added to favorites.']);
    }

    public function unfavoriteTemplate(Request $request, PromptTemplate $template)
    {
        PromptFavorite::where('user_id', $request->user()->id)
            ->where('prompt_template_id', $template->id)
            ->delete();

        return response()->json(['message' => 'Removed from favorites.']);
    }

    public function myFavorites(Request $request)
    {
        $favorites = PromptFavorite::where('user_id', $request->user()->id)
            ->with(['template' => function ($q) {
                $q->with('category:id,name,slug,icon')->where('is_active', true);
            }])
            ->get()
            ->pluck('template')
            ->filter();

        return response()->json($favorites->values());
    }

    // -------------------------------------------------------------------------
    // LEGACY project-scoped render (kept for backward compatibility)
    // -------------------------------------------------------------------------

    public function renderForProject(Request $request, Project $project, PromptTemplate $template)
    {
        // Delegate to the unified render method with project_id pre-filled
        $request->merge(['project_id' => $project->id]);
        return $this->render($request, $template);
    }
}
