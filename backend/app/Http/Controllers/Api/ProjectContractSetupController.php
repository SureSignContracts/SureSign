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
        } catch (\App\Services\Geocoding\GeocodingProviderException $e) {
            // Sanitized, fixed, customer-safe message only — never this
            // exception's own ->getMessage() (see its docblock). Nothing
            // selected in this request was persisted — the geocoding call
            // happens before ProjectContractSetupSyncService::apply() ever
            // opens its DB transaction.
            //
            // 'code' is required here, not optional: the frontend's own
            // Error Handling Standard (normalizeApiError) deliberately
            // discards a 5xx response's `message` and substitutes a fixed
            // generic string, to guard against a real infrastructure crash
            // leaking detail — but this 503 is a deliberate, already-safe
            // response from this controller, not a crash. 'code' is the
            // documented escape hatch for exactly that: the frontend checks
            // for this specific code to show this message instead of the
            // generic one, the same convention Billing already uses for
            // 'checkout_unavailable'/'plan_change_conflict'.
            return response()->json([
                'message' => 'Project Location could not be applied because the map location service is currently unavailable.',
                'code' => 'geocoding_unavailable',
            ], 503);
        }

        $locationResult = $result['project_location_result'] ?? null;

        if (empty($result['applied'])) {
            return response()->json([
                'message' => $locationResult !== null
                    ? $this->locationResultMessage($locationResult, false)
                    : 'Nothing was applied — the selected details already match the Project, or are no longer available.',
                'project' => $result['project'],
                'applied' => [],
                'project_location_result' => $locationResult,
            ]);
        }

        $locationApplied = in_array(ProjectContractSuggestionKeys::PROJECT_LOCATION, $result['applied'], true);

        \App\Services\ProjectActivityService::record(
            $project,
            $request->user(),
            'project_contract_suggestions_applied',
            'Applied confirmed Contract details to Project',
            'From "' . $contract->title . '" (Contract #' . $contract->id . ', analysis #' . $analysis->id . '): ' . implode(', ', $result['applied']),
            $contract,
            array_filter([
                'contract_id' => $contract->id,
                'analysis_id' => $analysis->id,
                'applied' => $result['applied'],
                // Part 37 — a small, safe boolean only; never the raw
                // Geoapify response, confidence score, or place_id.
                'map_position_updated' => $locationApplied ? ($locationResult['map_position'] ?? null) === 'updated' : null,
            ], fn ($v) => $v !== null)
        );

        return response()->json([
            'message' => $locationApplied && $locationResult !== null
                ? $this->locationResultMessage($locationResult, true)
                : 'Applied to the Project.',
            'project' => $result['project'],
            'applied' => $result['applied'],
            'project_location_result' => $locationResult,
        ]);
    }

    /**
     * The five customer-facing Project Location outcome messages (Part 25)
     * — never mentions AI, Geoapify, confidence scores, or internal
     * terminology. $wasApplied distinguishes "a real Project mutation
     * happened" (C/B/A) from "nothing changed, geocode-only action found no
     * reliable match" (D) — see ProjectContractSetupSyncService's own
     * Part 20/21 handling for why that specific case is never in `applied`.
     */
    private function locationResultMessage(array $locationResult, bool $wasApplied): string
    {
        $mapUpdated = ($locationResult['map_position'] ?? null) === 'updated';
        $textApplied = (bool) ($locationResult['textual_location_applied'] ?? false);

        if (!$wasApplied) {
            return 'SureSign could not confidently determine the map position.'; // (D)
        }

        if ($mapUpdated) {
            return $textApplied
                ? 'Project Location applied and map position updated.' // (A)
                : 'Project map position updated.'; // (C)
        }

        return 'Project Location applied. SureSign could not confidently determine the new map position, so no map pin has been set.'; // (B)
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
