<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlantDeployment;
use App\Models\PlantItem;
use App\Models\Project;
use App\Services\Plant\PlantDeploymentService;
use App\Services\ProjectActivityService;
use App\Support\Plant\PlantDeploymentOverlapException;
use Illuminate\Http\Request;

/**
 * Friday Pack Realignment, R1E.2D — Plant & Equipment (site-presence
 * periods). Nested under a Plant Item — mirrors
 * FridayPackPhotoSelectionController's own nested-resource authorization
 * shape. Every mutation delegates entirely to
 * App\Services\Plant\PlantDeploymentService — this controller never
 * checks overlap itself.
 */
class PlantDeploymentController extends Controller
{
    private function authorize(Request $request, Project|PlantItem|PlantDeployment $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    private function authorizeProjectPlantItem(Request $request, Project $project, PlantItem $plantItem): void
    {
        $this->authorize($request, $plantItem);
        if ($plantItem->project_id !== $project->id) {
            abort(404, 'Plant item not found for this project.');
        }
    }

    /**
     * Re-derives the deployment's REAL parent Plant Item so a same-
     * organisation/same-project but mismatched Plant Item ID in the URL
     * can't address a deployment belonging to a different item.
     */
    private function authorizePlantItemDeployment(Request $request, Project $project, PlantItem $plantItem, PlantDeployment $deployment): void
    {
        $this->authorizeProjectPlantItem($request, $project, $plantItem);
        if ($deployment->plant_item_id !== $plantItem->id) {
            abort(404, 'Site presence period not found for this plant item.');
        }
    }

    public function index(Request $request, Project $project, PlantItem $plantItem)
    {
        $this->authorizeProjectPlantItem($request, $project, $plantItem);

        return response()->json($plantItem->deployments()->get());
    }

    public function store(Request $request, Project $project, PlantItem $plantItem, PlantDeploymentService $service)
    {
        $this->authorizeProjectPlantItem($request, $project, $plantItem);

        $validated = $request->validate([
            'on_site_from' => 'required|date',
            'off_site_at'  => 'nullable|date|after_or_equal:on_site_from',
            'notes'        => 'nullable|string',
        ]);

        try {
            $deployment = $service->create(
                $plantItem, $project, $request->user(),
                $validated['on_site_from'], $validated['off_site_at'] ?? null, $validated['notes'] ?? null,
            );
        } catch (PlantDeploymentOverlapException $e) {
            abort(409, $e->getMessage());
        }

        ProjectActivityService::record(
            $project,
            $request->user(),
            'plant_deployment_added',
            "Site presence recorded for {$plantItem->name} from " . $deployment->on_site_from->format('d M Y'),
            null,
            $deployment
        );

        return response()->json($deployment->fresh(), 201);
    }

    public function update(Request $request, Project $project, PlantItem $plantItem, PlantDeployment $deployment, PlantDeploymentService $service)
    {
        $this->authorizePlantItemDeployment($request, $project, $plantItem, $deployment);

        $validated = $request->validate([
            'on_site_from' => 'sometimes|date',
            'off_site_at'  => 'nullable|date',
            'notes'        => 'nullable|string',
        ]);

        $wasOpen = $deployment->isOpen();
        $offSiteAtProvided = array_key_exists('off_site_at', $validated);

        try {
            $deployment = $service->update(
                $deployment, $validated['on_site_from'] ?? null,
                $offSiteAtProvided ? $validated['off_site_at'] : null, $offSiteAtProvided,
                $validated['notes'] ?? null,
            );
        } catch (PlantDeploymentOverlapException $e) {
            abort(409, $e->getMessage());
        }

        ProjectActivityService::record(
            $project,
            $request->user(),
            'plant_deployment_updated',
            "Site presence updated for {$plantItem->name}",
            null,
            $deployment
        );

        // Distinct event only for the genuine "closed" transition
        // (open → a real off_site_at) — mirrors IncidentController/
        // HsInspectionController's own "only log a distinct event when
        // the specific transition genuinely occurred" pattern.
        if ($wasOpen && !$deployment->isOpen()) {
            ProjectActivityService::record(
                $project,
                $request->user(),
                'plant_deployment_closed',
                "{$plantItem->name} marked off site as of " . $deployment->off_site_at->format('d M Y'),
                null,
                $deployment
            );
        }

        return response()->json($deployment);
    }

    public function destroy(Request $request, Project $project, PlantItem $plantItem, PlantDeployment $deployment)
    {
        $this->authorizePlantItemDeployment($request, $project, $plantItem, $deployment);

        $deployment->delete();

        ProjectActivityService::record(
            $project,
            $request->user(),
            'plant_deployment_deleted',
            "Site presence period deleted for {$plantItem->name}",
            null,
            $deployment
        );

        return response()->json(null, 204);
    }
}
