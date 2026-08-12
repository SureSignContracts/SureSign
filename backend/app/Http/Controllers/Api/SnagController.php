<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DrawingHotspot;
use App\Models\FileUpload;
use App\Models\Project;
use App\Models\Snag;
use App\Services\Documents\RecordAttachmentService;
use App\Services\Drawings\DrawingHotspotLinkService;
use App\Services\ProjectActivityService;
use App\Support\Drawings\DrawingLinkableType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SnagController extends Controller
{
    private function authorize(Request $request, Project|Snag $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    public function index(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $query = Snag::where('project_id', $project->id)
            ->with(['creator:id,name', 'assignee:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%");
            });
        }

        return response()->json($query->latest()->paginate(50));
    }

    public function store(Request $request, Project $project, DrawingHotspotLinkService $linkService)
    {
        $this->authorize($request, $project);

        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'location'    => 'nullable|string|max:255',
            'category'    => 'nullable|string|max:100',
            'priority'    => 'nullable|in:low,medium,high,critical',
            'status'      => 'nullable|in:open,in_progress,ready_for_review,closed',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'due_date'    => 'nullable|date',
            'notes'       => 'nullable|string',
            // Drawing Phase 7B1 — optional; absent behaves exactly as
            // before. `exists:drawing_hotspots,id` only checks the row
            // exists at all (any organisation) — same-Project/same-
            // Organisation ownership is re-checked below via
            // DrawingHotspotLinkService, never trusted from this alone.
            'drawing_hotspot_id' => 'nullable|integer|exists:drawing_hotspots,id',
        ]);

        // Record creation + optional hotspot link are atomic — either both
        // exist or neither does. See DrawingHotspotLinkService's own
        // docblock for why ownership is resolved from the hotspot's own
        // Drawing rather than a separately-trusted client value.
        $snag = DB::transaction(function () use ($request, $project, $validated, $linkService) {
            $snagNumber = (Snag::where('project_id', $project->id)->max('snag_number') ?? 0) + 1;

            $snag = Snag::create(array_merge(
                collect($validated)->except('drawing_hotspot_id')->all(),
                [
                    'project_id'      => $project->id,
                    'organization_id' => $project->organization_id,
                    'created_by'      => $request->user()->id,
                    'snag_number'     => $snagNumber,
                    'status'          => $validated['status'] ?? 'open',
                    'priority'        => $validated['priority'] ?? 'medium',
                ]
            ));

            ProjectActivityService::record(
                $project,
                $request->user(),
                'snag_created',
                "Snag #{$snagNumber} raised: {$snag->title}",
                null,
                $snag
            );

            if (! empty($validated['drawing_hotspot_id'])) {
                $hotspot = DrawingHotspot::find($validated['drawing_hotspot_id']);
                if (! $hotspot) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'drawing_hotspot_id' => 'The selected drawing location could not be found.',
                    ]);
                }
                // linkOrFail() throws ValidationException on any ownership/
                // type/duplicate failure — propagating out of this closure
                // rolls back the Snag create above along with it.
                $linkService->linkOrFail($hotspot, DrawingLinkableType::SNAG, $snag, $request->user());
            }

            return $snag;
        });

        // No notification/job side effect exists for Snag creation today
        // (Phase 7A confirmed) — nothing to defer to after-commit here.
        return response()->json($snag->load(['creator:id,name', 'assignee:id,name']), 201);
    }

    // Route parameter is {snagging} (from Route::apiResource('snagging', ...)),
    // not {snag} — Laravel's implicit binding matches by name, so a $snag
    // parameter here silently receives null instead of the bound model.
    // Also not shallow (api/projects/{project}/snagging/{snagging}), so
    // Project $project must be declared too even though unused, matching
    // the same fix already applied to the other Delivery controllers.
    public function show(Request $request, Project $project, Snag $snagging)
    {
        $this->authorize($request, $snagging);

        return response()->json($snagging->load(['creator:id,name', 'assignee:id,name']));
    }

    public function update(Request $request, Project $project, Snag $snagging)
    {
        $this->authorize($request, $snagging);

        $snag = $snagging;
        $oldStatus = $snag->status;

        $validated = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'location'    => 'nullable|string|max:255',
            'category'    => 'nullable|string|max:100',
            // priority/status are NOT NULL columns whose DB defaults only
            // apply when the column is omitted from an UPDATE, not when
            // explicit NULL is sent — 'sometimes' leaves them untouched if
            // absent from the request instead of nulling them out (same fix
            // already applied to Rfi/SiteDiary/Meeting/QaReport).
            'priority'    => 'sometimes|in:low,medium,high,critical',
            'status'      => 'sometimes|in:open,in_progress,ready_for_review,closed',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'due_date'    => 'nullable|date',
            'notes'       => 'nullable|string',
        ]);

        // Auto-set closed_at when closing
        if (isset($validated['status']) && $validated['status'] === 'closed' && $oldStatus !== 'closed') {
            $validated['closed_at'] = now();
        } elseif (isset($validated['status']) && $validated['status'] !== 'closed') {
            $validated['closed_at'] = null;
        }

        $snag->update($validated);

        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            $project = $snag->project;
            ProjectActivityService::record(
                $project,
                $request->user(),
                'snag_updated',
                "Snag #{$snag->snag_number} status changed to {$validated['status']}",
                null,
                $snag
            );
        }

        return response()->json($snag->fresh()->load(['creator:id,name', 'assignee:id,name']));
    }

    public function destroy(Request $request, Project $project, Snag $snagging)
    {
        $this->authorize($request, $snagging);

        $snagging->delete();
        return response()->json(null, 204);
    }

    // ── Evidence attachments (Phase 0) ───────────────────────────────────
    // See App\Services\Documents\RecordAttachmentService — the same shared
    // service backs Rfi/QaReport's identical methods below.

    public function attachments(Request $request, Project $project, Snag $snagging)
    {
        $this->authorize($request, $snagging);

        return response()->json(
            (new RecordAttachmentService())->list($snagging)
        );
    }

    public function uploadAttachment(Request $request, Project $project, Snag $snagging)
    {
        $this->authorize($request, $snagging);

        $upload = (new RecordAttachmentService())->upload(
            $request, $project, $snagging, $request->user(),
            'snagging', "Snag #{$snagging->snag_number}", 'snag_evidence_uploaded',
        );

        return response()->json($upload, 201);
    }

    public function deleteAttachment(Request $request, Project $project, Snag $snagging, FileUpload $fileUpload)
    {
        $this->authorize($request, $snagging);

        (new RecordAttachmentService())->delete(
            $fileUpload, $snagging, $project, $request->user(),
            "Snag #{$snagging->snag_number}", 'snag_evidence_removed',
        );

        return response()->json(null, 204);
    }
}
