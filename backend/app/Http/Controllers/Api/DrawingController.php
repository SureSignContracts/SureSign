<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Drawing;
use App\Models\Project;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Drawing Register — structured drawing metadata layered on top of an
 * existing Document (see App\Models\Drawing's docblock). Drawing never
 * stores a file itself; every read/download/preview continues to resolve
 * through the existing Document endpoints.
 */
class DrawingController extends Controller
{
    private function authorize(Request $request, Project|Drawing $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) {
            return;
        }
        if ($user->organization_id !== $subject->organization_id) {
            abort(403, 'Access denied.');
        }
    }

    private const METADATA_RULES = [
        'drawing_number' => 'required|string|max:100',
        'title' => 'required|string|max:255',
        'discipline' => 'nullable|string|max:100',
        'status' => 'nullable|string|max:100',
        'location_reference' => 'nullable|string|max:255',
    ];

    private function documentSummary(): array
    {
        // mime_type added for Drawing Phase 3 — lets the Drawing Viewer
        // decide PDF vs. image vs. unsupported before making a preview
        // request, without duplicating Document's own preview-conversion
        // logic here.
        return ['id', 'title', 'file_name', 'reference_number', 'category', 'type', 'mime_type'];
    }

    /**
     * Eager-loads what effectiveDocument() and the `current_revision`
     * summary need, then overwrites the `document` relation with the
     * resolved effective Document (Phase 4 Part H/J) — the single place
     * this happens, so every response below carries the correct file
     * under the exact same `document` key the frontend has always read,
     * with zero frontend awareness of the legacy-fallback mechanism.
     */
    private function presentDrawing(Drawing $drawing): Drawing
    {
        $drawing->loadMissing([
            'currentRevision.document:'.implode(',', $this->documentSummary()),
            'document:'.implode(',', $this->documentSummary()),
            'creator:id,name',
        ]);
        $drawing->setRelation('document', $drawing->effectiveDocument());

        return $drawing;
    }

    /**
     * Eligible-document lookup for the Register Drawing selector (Phase 1B,
     * Part L). Deliberately NOT reusing DocumentController::index() —
     * that endpoint has no `search` at all (only exact-match `type`/
     * `category`), and has no way to exclude Documents already actively
     * registered as a Drawing (that knowledge only exists on this side).
     * This is a small, additive, Drawing-registration-scoped endpoint —
     * not a general Documents search/redesign. Excludes a Document only
     * while it has a non-soft-deleted Drawing row (the default query scope
     * already excludes soft-deleted Drawings), so a Document becomes
     * eligible again immediately after its Drawing registration is removed.
     */
    public function eligibleDocuments(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $query = Document::where('project_id', $project->id)
            ->whereDoesntHave('drawing')
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('file_name', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->paginate($request->integer('per_page', 25), [
                'id', 'title', 'file_name', 'reference_number', 'category', 'type',
            ])
        );
    }

    /**
     * Eligible-document lookup for the Add Revision selector (Phase 4 Part
     * L) — deliberately a SEPARATE endpoint from eligibleDocuments() above,
     * not a reuse, because the eligibility rule is genuinely different: a
     * Document already tied to a DIFFERENT Drawing is perfectly legitimate
     * here (that rule only ever existed to stop a single Document being
     * registered as two different Drawings), while a Document already used
     * by ANOTHER revision of THIS SAME Drawing is excluded (reusing one
     * file across two revisions of one drawing is never meaningful).
     */
    public function eligibleRevisionDocuments(Request $request, Project $project, Drawing $drawing)
    {
        $this->authorize($request, $drawing);

        $query = Document::where('project_id', $project->id)
            ->whereDoesntHave('revisions', function ($q) use ($drawing) {
                $q->where('drawing_id', $drawing->id);
            })
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('file_name', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->paginate($request->integer('per_page', 25), [
                'id', 'title', 'file_name', 'reference_number', 'category', 'type',
            ])
        );
    }

    public function index(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $query = Drawing::where('project_id', $project->id)
            ->with([
                'currentRevision.document:'.implode(',', $this->documentSummary()),
                'document:'.implode(',', $this->documentSummary()),
                'creator:id,name',
            ]);

        if ($request->filled('discipline')) {
            $query->where('discipline', $request->discipline);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            // Searches whichever Document is actually effective for each
            // Drawing (current revision's, or the legacy fallback) — a
            // Drawing with revision history must remain findable by its
            // current file's title/reference, not only its original one.
            $query->where(function ($q) use ($search) {
                $q->where('drawing_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhereHas('document', function ($dq) use ($search) {
                        $dq->where('title', 'like', "%{$search}%")
                            ->orWhere('reference_number', 'like', "%{$search}%");
                    })
                    ->orWhereHas('currentRevision.document', function ($dq) use ($search) {
                        $dq->where('title', 'like', "%{$search}%")
                            ->orWhere('reference_number', 'like', "%{$search}%");
                    });
            });
        }

        $paginated = $query->latest()->paginate($request->integer('per_page', 25));
        $paginated->getCollection()->each(fn (Drawing $d) => $d->setRelation('document', $d->effectiveDocument()));

        return response()->json($paginated);
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $validated = $request->validate(array_merge(self::METADATA_RULES, [
            'document_id' => 'required|integer|exists:documents,id',
        ]));

        // Document eligibility (Part L): same project, not soft-deleted
        // (the default query scope already excludes it), not already
        // authorized cross-tenant. Deliberately does NOT require
        // category = 'Drawings' — older Project Documents may be valid
        // drawings without perfect category metadata (that preference
        // belongs to the future frontend selector, not a backend rule).
        $document = Document::where('id', $validated['document_id'])
            ->where('project_id', $project->id)
            ->first();

        if (! $document) {
            throw ValidationException::withMessages([
                'document_id' => 'The selected document could not be found for this project.',
            ]);
        }
        // Cross-tenant defence in depth — $document->project_id already
        // guarantees same-project, but organization_id is checked directly
        // too since it's the field every authorize() call relies on.
        if ($document->organization_id !== $project->organization_id) {
            abort(403, 'Access denied.');
        }

        // Active-uniqueness enforcement (Part C) — see the drawings table
        // migration's own comment for why this is application-level rather
        // than a DB unique index: MySQL/MariaDB has no partial/filtered
        // unique index, so a plain composite unique would also bind
        // soft-deleted rows and block re-registering the same Document
        // after its Drawing is removed. lockForUpdate() inside a
        // transaction closes the check-then-insert race without reaching
        // for Cache::lock() (a single-row check, not cross-process rate
        // limiting).
        $drawing = DB::transaction(function () use ($validated, $project, $document, $request) {
            $existing = Drawing::where('project_id', $project->id)
                ->where('document_id', $document->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'document_id' => 'This document is already registered as a drawing in this project.',
                ]);
            }

            return Drawing::create(array_merge($validated, [
                'project_id' => $project->id,
                'organization_id' => $project->organization_id,
                'created_by' => $request->user()->id,
            ]));
        });

        ProjectActivityService::record(
            $project,
            $request->user(),
            'drawing_registered',
            "Drawing registered: {$drawing->drawing_number} — {$drawing->title}",
            null,
            $drawing
        );

        return response()->json($this->presentDrawing($drawing), 201);
    }

    // Not shallow (api/projects/{project}/drawings/{drawing}) — both
    // segments are typed model bindings, so Project $project must be
    // declared even though unused, matching the same fix already applied to
    // Snag/Rfi/QaReport/DeliveryDocument controllers.
    public function show(Request $request, Project $project, Drawing $drawing)
    {
        $this->authorize($request, $drawing);

        return response()->json($this->presentDrawing($drawing));
    }

    public function update(Request $request, Project $project, Drawing $drawing)
    {
        $this->authorize($request, $drawing);

        // Metadata only — document_id/project_id/created_by are immutable
        // after registration (Part N). A wrong Document means removing this
        // registration and registering the correct one, never swapping the
        // association in place.
        $validated = $request->validate(array_merge(self::METADATA_RULES, [
            'drawing_number' => 'sometimes|string|max:100',
            'title' => 'sometimes|string|max:255',
        ]));

        if (empty($validated)) {
            return response()->json($this->presentDrawing($drawing));
        }

        $drawing->update($validated);

        ProjectActivityService::record(
            $project,
            $request->user(),
            'drawing_updated',
            "Drawing updated: {$drawing->drawing_number} — {$drawing->title}",
            null,
            $drawing
        );

        return response()->json($this->presentDrawing($drawing->fresh()));
    }

    public function destroy(Request $request, Project $project, Drawing $drawing)
    {
        $this->authorize($request, $drawing);

        $label = "{$drawing->drawing_number} — {$drawing->title}";

        // Soft-delete Drawing metadata only — the underlying Document,
        // FileUpload, and stored file are never touched (Part O, mandatory).
        $drawing->delete();

        ProjectActivityService::record(
            $project,
            $request->user(),
            'drawing_registration_removed',
            "Drawing registration removed: {$label}",
            null,
            $drawing
        );

        return response()->json(null, 204);
    }
}
