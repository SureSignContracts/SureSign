<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FridayPack;
use App\Models\FridayPackPhotoSelection;
use App\Models\Project;
use App\Services\FridayPack\FridayPackPhotoDiscoveryService;
use App\Services\FridayPack\FridayPackPhotoSelectionService;
use App\Support\FridayPack\FridayPackPhotoSourceType;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Automated Friday Pack, R1B — Site Photographs & Evidence Selection.
 * Mirrors FridayPackDeliveryController's own authorization structure
 * exactly; delegates all discovery/mutation logic to
 * FridayPackPhotoDiscoveryService/FridayPackPhotoSelectionService — this
 * controller never queries FileUpload/SiteDiary/ToolboxTalk or writes to
 * friday_pack_photo_selections directly.
 */
class FridayPackPhotoSelectionController extends Controller
{
    private function authorize(Request $request, Project|FridayPack $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    private function authorizeProjectFridayPack(Request $request, Project $project, FridayPack $fridayPack): void
    {
        $this->authorize($request, $fridayPack);
        if ($fridayPack->project_id !== $project->id) {
            abort(404, 'Friday Pack not found for this project.');
        }
    }

    /** GET .../photo-candidates — read-only discovery, never persisted. */
    public function candidates(Request $request, Project $project, FridayPack $fridayPack, FridayPackPhotoDiscoveryService $discovery)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        $period = [
            'period_start' => $fridayPack->period_start->toDateString(),
            'period_end'   => $fridayPack->period_end->toDateString(),
        ];

        $candidates = $discovery->discover($project, $period);

        return response()->json([
            'candidates'      => $candidates,
            'candidate_count' => count($candidates),
            // R1B readiness future hook — deterministic counts only, no
            // percentage/status calculation (that's a later, separate
            // readiness phase).
            'selected_count'  => $fridayPack->photoSelections()->count(),
        ]);
    }

    public function index(Request $request, Project $project, FridayPack $fridayPack)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        return response()->json(
            $fridayPack->photoSelections()
                ->get()
                ->map(fn (FridayPackPhotoSelection $s) => $this->presentSelection($s))
        );
    }

    public function store(Request $request, Project $project, FridayPack $fridayPack, FridayPackPhotoSelectionService $service)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        $validated = $request->validate([
            'file_upload_id' => 'required|integer',
            'caption'        => 'nullable|string|max:255',
            'location'       => 'nullable|string|max:255',
        ]);

        try {
            $selection = $service->select(
                $fridayPack, $project, $request->user(),
                (int) $validated['file_upload_id'], $validated['caption'] ?? null, $validated['location'] ?? null,
            );
        } catch (HttpExceptionInterface $e) {
            // A deliberate abort() (e.g. 422 tenant/evidence validation)
            // thrown deeper in the service — Symfony's HttpException
            // itself extends RuntimeException, so this must be caught
            // and rethrown BEFORE the generic RuntimeException catch
            // below, or its real status code would be silently
            // overwritten with 409.
            throw $e;
        } catch (RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return response()->json($this->presentSelection($selection), 201);
    }

    public function update(Request $request, Project $project, FridayPack $fridayPack, FridayPackPhotoSelection $photoSelection, FridayPackPhotoSelectionService $service)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        $validated = $request->validate([
            'caption'  => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
        ]);

        try {
            $selection = $service->updateMetadata(
                $fridayPack, $project, $request->user(), $photoSelection,
                $validated['caption'] ?? null, $validated['location'] ?? null,
            );
        } catch (HttpExceptionInterface $e) {
            // A deliberate abort() (e.g. 422 tenant/evidence validation)
            // thrown deeper in the service — Symfony's HttpException
            // itself extends RuntimeException, so this must be caught
            // and rethrown BEFORE the generic RuntimeException catch
            // below, or its real status code would be silently
            // overwritten with 409.
            throw $e;
        } catch (RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return response()->json($this->presentSelection($selection));
    }

    public function destroy(Request $request, Project $project, FridayPack $fridayPack, FridayPackPhotoSelection $photoSelection, FridayPackPhotoSelectionService $service)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        try {
            $service->deselect($fridayPack, $project, $request->user(), $photoSelection);
        } catch (HttpExceptionInterface $e) {
            // A deliberate abort() (e.g. 422 tenant/evidence validation)
            // thrown deeper in the service — Symfony's HttpException
            // itself extends RuntimeException, so this must be caught
            // and rethrown BEFORE the generic RuntimeException catch
            // below, or its real status code would be silently
            // overwritten with 409.
            throw $e;
        } catch (RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return response()->json(null, 204);
    }

    public function reorder(Request $request, Project $project, FridayPack $fridayPack, FridayPackPhotoSelectionService $service)
    {
        $this->authorizeProjectFridayPack($request, $project, $fridayPack);

        $validated = $request->validate([
            'ordered_selection_ids'   => 'required|array',
            'ordered_selection_ids.*' => 'integer',
        ]);

        try {
            $service->reorder($fridayPack, $project, $request->user(), $validated['ordered_selection_ids']);
        } catch (HttpExceptionInterface $e) {
            // A deliberate abort() (e.g. 422 tenant/evidence validation)
            // thrown deeper in the service — Symfony's HttpException
            // itself extends RuntimeException, so this must be caught
            // and rethrown BEFORE the generic RuntimeException catch
            // below, or its real status code would be silently
            // overwritten with 409.
            throw $e;
        } catch (RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return response()->json(
            $fridayPack->photoSelections()->get()->map(fn (FridayPackPhotoSelection $s) => $this->presentSelection($s))
        );
    }

    /**
     * Deliberately never exposes file_path/disk — only a preview URL
     * (the existing authenticated /file-uploads/{id}/preview route) and
     * a friendly source label, never a raw PHP FQCN.
     */
    private function presentSelection(FridayPackPhotoSelection $selection): array
    {
        return [
            'id'                 => $selection->id,
            'file_upload_id'     => $selection->file_upload_id,
            'preview_url'        => "/api/file-uploads/{$selection->file_upload_id}/preview",
            'source_type'        => $selection->source_type,
            'source_label'       => FridayPackPhotoSourceType::label($selection->source_type),
            'source_id'          => $selection->source_id,
            'source_date'        => optional($selection->source_date)->toDateString(),
            'original_file_name' => $selection->original_file_name,
            'caption'            => $selection->caption,
            'location'           => $selection->location,
            'sort_order'         => $selection->sort_order,
        ];
    }
}
