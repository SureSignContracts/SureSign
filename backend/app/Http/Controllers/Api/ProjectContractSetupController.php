<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\Project;
use App\Services\ProjectContractSetupSyncService;
use App\Services\ProjectContractSuggestionValidationException;
use App\Support\Projects\ProjectContractSuggestionKeys;
use Illuminate\Http\Request;

/**
 * Phase E — Contract-Assisted Project Setup: read-only suggestion preview
 * plus the one explicit apply action. Deliberately separate from
 * `AiController`/`ProjectController` — this is Setup-specific integration,
 * not a general AI or Project API. See `ProjectContractSetupSyncService`
 * for the actual suggestion/apply logic; this controller only authorizes
 * the ownership chain and validates the request shape.
 */
class ProjectContractSetupController extends Controller
{
    public function __construct(private ProjectContractSetupSyncService $syncService) {}

    public function suggestions(Request $request, Project $project, Contract $contract, ContractAiAnalysis $analysis)
    {
        $this->authorizeChain($request, $project, $contract, $analysis);

        if (!$analysis->isConfirmed()) {
            return response()->json([
                'message' => 'This Contract analysis has not been confirmed yet. Review and confirm it before Project suggestions are available.',
            ], 422);
        }

        return response()->json([
            'project_id'  => $project->id,
            'contract_id' => $contract->id,
            'analysis_id' => $analysis->id,
            'contract_title' => $contract->title,
            'suggestions' => $this->syncService->suggestions($project, $contract, $analysis),
        ]);
    }

    public function apply(Request $request, Project $project, Contract $contract, ContractAiAnalysis $analysis)
    {
        $this->authorizeChain($request, $project, $contract, $analysis);

        if (!$analysis->isConfirmed()) {
            return response()->json([
                'message' => 'This Contract analysis has not been confirmed yet. Review and confirm it before applying Project suggestions.',
            ], 422);
        }

        $validated = $request->validate([
            'suggestions'   => 'required|array|min:1',
            'suggestions.*' => 'required|string|in:' . implode(',', ProjectContractSuggestionKeys::ALL),
        ]);

        try {
            $result = $this->syncService->apply($project, $contract, $analysis, $validated['suggestions']);
        } catch (ProjectContractSuggestionValidationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (empty($result['applied'])) {
            return response()->json([
                'message' => 'Nothing was applied — the selected details already match the Project, or are no longer available.',
                'project' => $result['project'],
                'applied' => [],
            ]);
        }

        \App\Services\ProjectActivityService::record(
            $project,
            $request->user(),
            'project_contract_suggestions_applied',
            'Applied confirmed Contract details to Project',
            'From "' . $contract->title . '" (Contract #' . $contract->id . ', analysis #' . $analysis->id . '): ' . implode(', ', $result['applied']),
            $contract,
            ['contract_id' => $contract->id, 'analysis_id' => $analysis->id, 'applied' => $result['applied']]
        );

        return response()->json([
            'message' => 'Applied to the Project.',
            'project' => $result['project'],
            'applied' => $result['applied'],
        ]);
    }

    /**
     * Reuses the platform's standard organisation-membership authorization
     * convention (Super Admin/Admin bypass; otherwise the acting user's own
     * organization_id must match), plus an explicit re-validation of the
     * Project → Contract → Analysis ownership chain — a valid Contract ID
     * or Analysis ID belonging to a DIFFERENT Project/Contract than the one
     * named in the URL must never become reachable this way (matches the
     * existing IDOR-hardening precedent already established for Trade
     * Packages — see Batch2ClientPermissionsTest).
     */
    private function authorizeChain(Request $request, Project $project, Contract $contract, ContractAiAnalysis $analysis): void
    {
        $user = $request->user();
        if (!$user->hasRole('Super Admin') && !$user->hasRole('Admin')) {
            if ($user->organization_id !== $project->organization_id) {
                abort(403, 'Access denied.');
            }
        }

        if ($contract->project_id !== $project->id) {
            abort(404);
        }
        if ($analysis->contract_id !== $contract->id || $analysis->project_id !== $project->id) {
            abort(404);
        }
    }
}
