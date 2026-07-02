<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\RetentionRelease;
use App\Services\FinalAccountService;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;

class RetentionReleaseController extends Controller
{
    public function __construct(private FinalAccountService $finalAccountService) {}

    private function authorizeProject(Request $request, Project $project): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $project->organization_id) abort(403, 'Access denied.');
    }

    // GET /projects/{project}/retention-releases
    public function index(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $releases = RetentionRelease::where('project_id', $project->id)
            ->with(['creator:id,name', 'contract:id,title', 'tradePackage:id,name'])
            ->latest('release_date')
            ->paginate(50);

        return response()->json($releases);
    }

    // POST /projects/{project}/retention-releases
    public function store(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $validated = $request->validate([
            'contract_id'      => 'nullable|integer|exists:contracts,id',
            'trade_package_id' => 'nullable|integer|exists:trade_packages,id',
            'release_amount'   => 'required|numeric|min:0.01',
            'release_date'     => 'required|date',
            'release_reason'   => 'required|string|max:200',
            'moiety'           => 'nullable|in:half_1,half_2,other',
            'notes'            => 'nullable|string',
        ]);

        if (empty($validated['contract_id']) && empty($validated['trade_package_id'])) {
            return response()->json(['message' => 'A contract or trade package must be specified.'], 422);
        }

        // Default moiety to 'other' if not supplied (pre-moiety records or manual adjustments)
        $validated['moiety'] = $validated['moiety'] ?? \App\Models\RetentionRelease::MOIETY_OTHER;

        // Half 2 retention is blocked until the Final Certificate has been issued
        if ($validated['moiety'] === \App\Models\RetentionRelease::MOIETY_HALF_2) {
            $guard = $this->finalAccountService->canReleaseHalf2Retention(
                $validated['contract_id'] ?? null,
                $validated['trade_package_id'] ?? null
            );

            if (!$guard['allowed']) {
                return response()->json(['message' => $guard['reason']], 422);
            }
        }

        $release = RetentionRelease::create(array_merge($validated, [
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $request->user()->id,
        ]));

        $source = $release->contract?->title ?? $release->tradePackage?->name ?? '—';
        $moietyLabel = match($validated['moiety']) {
            'half_1' => 'Half 1 — Practical Completion',
            'half_2' => 'Half 2 — Making Good Defects',
            default  => 'Manual Adjustment',
        };

        ProjectActivityService::record(
            $project, $request->user(),
            'retention_released',
            "Retention released: " . number_format($validated['release_amount'], 2) . " — {$source}",
            "Moiety: {$moietyLabel}. Reason: {$validated['release_reason']}",
            $release
        );

        return response()->json($release->load(['creator:id,name', 'contract:id,title', 'tradePackage:id,name']), 201);
    }

    // DELETE /retention-releases/{retentionRelease}
    public function destroy(Request $request, RetentionRelease $retentionRelease)
    {
        $project = $retentionRelease->project;
        $this->authorizeProject($request, $project);

        $retentionRelease->delete();
        return response()->json(null, 204);
    }
}
