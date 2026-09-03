<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FileUpload;
use App\Models\PlantItem;
use App\Models\Project;
use App\Services\Documents\RecordAttachmentService;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Friday Pack Realignment, R1E.2D — Plant & Equipment (reusable identity).
 * Mirrors SiteInductionController's structure (including its attachment
 * redaction pattern). Deployment (site-presence) actions live in the
 * sibling PlantDeploymentController, nested under this same Plant Item —
 * see that controller and App\Services\Plant\PlantDeploymentService for
 * the presence-period domain logic this controller never duplicates.
 */
class PlantItemController extends Controller
{
    private function authorize(Request $request, Project|PlantItem $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    /**
     * Re-derives the Plant Item's REAL parent project so a same-
     * organisation but mismatched project ID in the URL can't address an
     * item belonging to a different project — mirrors
     * SiteInductionController::authorizeProjectSiteInduction() exactly.
     */
    private function authorizeProjectPlantItem(Request $request, Project $project, PlantItem $plantItem): void
    {
        $this->authorize($request, $plantItem);
        if ($plantItem->project_id !== $project->id) {
            abort(404, 'Plant item not found for this project.');
        }
    }

    public function index(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $query = PlantItem::where('project_id', $project->id)
            ->with(['creator:id,name', 'deployments']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest()->paginate(25));
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'type'            => 'required|string|max:255',
            'identifier'      => 'nullable|string|max:255',
            'owner_supplier'  => 'nullable|string|max:255',
            'status'          => ['nullable', Rule::in(PlantItem::STATUSES)],
        ]);

        $plantItem = PlantItem::create(array_merge($validated, [
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $request->user()->id,
            'status'          => $validated['status'] ?? 'active',
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'plant_item_added',
            "Plant item added: {$plantItem->name} ({$plantItem->type})",
            null,
            $plantItem
        );

        return response()->json($plantItem->load(['creator:id,name', 'deployments']), 201);
    }

    // Not shallow (api/projects/{project}/plant-items/{plant_item}) —
    // both segments are typed model bindings, so Project $project must
    // be declared even though unused here, matching SiteInductionController's
    // identical convention.
    public function show(Request $request, Project $project, PlantItem $plantItem)
    {
        $this->authorizeProjectPlantItem($request, $project, $plantItem);

        return response()->json($plantItem->load(['creator:id,name', 'deployments']));
    }

    public function update(Request $request, Project $project, PlantItem $plantItem)
    {
        $this->authorizeProjectPlantItem($request, $project, $plantItem);

        $validated = $request->validate([
            'name'            => 'sometimes|string|max:255',
            'type'            => 'sometimes|string|max:255',
            'identifier'      => 'nullable|string|max:255',
            'owner_supplier'  => 'nullable|string|max:255',
            'status'          => ['sometimes', Rule::in(PlantItem::STATUSES)],
        ]);

        $plantItem->update($validated);

        ProjectActivityService::record(
            $project,
            $request->user(),
            'plant_item_updated',
            "Plant item updated: {$plantItem->name}",
            null,
            $plantItem
        );

        return response()->json($plantItem->fresh()->load(['creator:id,name', 'deployments']));
    }

    /**
     * Draft-only-style safety rule (R1E.2D's own explicit requirement):
     * a Plant Item currently carrying an OPEN deployment
     * (`off_site_at IS NULL`) must not be soft-deleted — the deployment
     * must be closed (or removed) first. Historical closed deployments
     * are never blocked from remaining once the item is later deleted.
     */
    public function destroy(Request $request, Project $project, PlantItem $plantItem)
    {
        $this->authorizeProjectPlantItem($request, $project, $plantItem);

        if ($plantItem->hasOpenDeployment()) {
            abort(409, 'This plant item has an open site presence period — close it before deleting the item.');
        }

        $name = $plantItem->name;
        $plantItem->delete();

        ProjectActivityService::record(
            $project,
            $request->user(),
            'plant_item_deleted',
            "Plant item deleted: {$name}",
            null,
            $plantItem
        );

        return response()->json(null, 204);
    }

    // ── Evidence attachments ─────────────────────────────────────────────
    // See App\Services\Documents\RecordAttachmentService — the same
    // shared service backs SiteInduction/HsInspection's identical
    // methods. No new attachment mechanism. Belongs to the reusable
    // PlantItem only — never a single deployment period, and never
    // automatically surfaced in the Friday Pack (source-record evidence
    // only). Uses the established safe redaction pattern — never exposes
    // disk/file_path (see this class's own presentAttachment()).

    public function attachments(Request $request, Project $project, PlantItem $plantItem)
    {
        $this->authorizeProjectPlantItem($request, $project, $plantItem);

        return response()->json(
            (new RecordAttachmentService())->list($plantItem)->map(fn (FileUpload $u) => $this->presentAttachment($u))
        );
    }

    public function uploadAttachment(Request $request, Project $project, PlantItem $plantItem)
    {
        $this->authorizeProjectPlantItem($request, $project, $plantItem);

        $upload = (new RecordAttachmentService())->upload(
            $request, $project, $plantItem, $request->user(),
            'plant_items', "Plant Item: {$plantItem->name}", 'plant_item_evidence_uploaded',
        );

        return response()->json($this->presentAttachment($upload), 201);
    }

    public function deleteAttachment(Request $request, Project $project, PlantItem $plantItem, FileUpload $fileUpload)
    {
        $this->authorizeProjectPlantItem($request, $project, $plantItem);

        (new RecordAttachmentService())->delete(
            $fileUpload, $plantItem, $project, $request->user(),
            "Plant Item: {$plantItem->name}", 'plant_item_evidence_removed',
        );

        return response()->json(null, 204);
    }

    /** Never exposes disk/file_path/attachable_type FQCN — only fields the frontend genuinely needs. */
    private function presentAttachment(FileUpload $upload): array
    {
        return [
            'id'                => $upload->id,
            'original_name'     => $upload->original_name,
            'mime_type'         => $upload->mime_type,
            'file_size'         => $upload->file_size,
            'uploaded_by'       => $upload->uploaded_by,
            'created_at'        => $upload->created_at,
        ];
    }
}
