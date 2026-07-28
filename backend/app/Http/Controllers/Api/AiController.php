<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyseContractWithAiJob;
use App\Jobs\GenerateProjectNotificationsJob;
use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\FileUpload;
use App\Models\Project;
use App\Models\SuresignSetting;
use App\Services\AI\ContractAnalysisService;
use App\Support\AI\AiAnalysisPresenter;
use App\Support\AI\AiTelemetrySchema;
use App\Support\AI\AiWorkflow;
use App\Services\CalendarSyncService;
use App\Services\ContractIntelligenceSyncService;
use App\Services\DocumentGenerationService;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class AiController extends Controller
{
    public function __construct(
        private ContractAnalysisService $analysisService,
        private ContractIntelligenceSyncService $syncService,
        private CalendarSyncService $calendarSync,
    ) {}

    // ─── GET /ai/status ───────────────────────────────────────────────────────

    public function status()
    {
        return response()->json([
            'ai_enabled' => $this->analysisService->isEnabled(),
        ]);
    }

    // ─── POST /contracts/{contract}/ai-analysis ───────────────────────────────

    public function startAnalysis(Request $request, Contract $contract)
    {
        $user = $request->user();
        $this->authorizeContractAccess($user, $contract);

        if (!$this->analysisService->isEnabled()) {
            return response()->json(['message' => 'AI features are disabled.'], 403);
        }

        // One active analysis at a time per contract
        $active = ContractAiAnalysis::where('contract_id', $contract->id)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if ($active) {
            return response()->json(['message' => 'An analysis is already in progress for this contract.'], 409);
        }

        // Return existing completed analysis info so frontend can offer "View Existing" option
        if (!$request->boolean('force_new')) {
            $existing = ContractAiAnalysis::where('contract_id', $contract->id)
                ->whereIn('status', ['completed', 'confirmed'])
                ->latest()
                ->first();

            if ($existing) {
                return response()->json([
                    'existing_analysis' => AiAnalysisPresenter::customerFacingContractAnalysis($existing),
                    'message'           => 'A completed analysis already exists for this contract.',
                ], 200);
            }
        }

        // Find the contract file — prefer explicit file_upload_id, else latest matching upload
        $fileUpload = null;
        if ($request->filled('file_upload_id')) {
            $fileUpload = FileUpload::where('id', $request->input('file_upload_id'))
                ->where('organization_id', $contract->organization_id)
                ->first();
        }

        if (!$fileUpload) {
            $fileUpload = FileUpload::where('attachable_type', Contract::class)
                ->where('attachable_id', $contract->id)
                ->whereIn('mime_type', [
                    'application/pdf',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/msword',
                    'text/plain',
                ])
                ->latest()
                ->first();
        }

        if (!$fileUpload) {
            return response()->json([
                'message' => 'No supported contract file found. Please upload a PDF, DOCX, or TXT file first.',
            ], 422);
        }

        $settings = SuresignSetting::instance();

        $analysis = ContractAiAnalysis::create([
            'contract_id'     => $contract->id,
            'organization_id' => $contract->organization_id,
            'project_id'      => $contract->project_id,
            'file_upload_id'  => $fileUpload->id,
            'status'          => 'pending',
            'provider'        => $settings->ai_provider ?? 'anthropic',
            'model'           => $settings->ai_model ?? config('ai.anthropic.model'),
            'workflow'        => AiWorkflow::CONTRACT_ANALYSIS,
            'telemetry_schema_version' => AiTelemetrySchema::CURRENT_VERSION,
            'created_by'      => $user->id,
        ]);

        AnalyseContractWithAiJob::dispatch($analysis->id, $fileUpload->id, $user->id);

        return response()->json([
            'data'    => AiAnalysisPresenter::customerFacingContractAnalysis($analysis->fresh()),
            'message' => 'Contract analysis started.',
        ], 201);
    }

    // ─── GET /contracts/{contract}/ai-analysis ────────────────────────────────

    public function getLatestAnalysis(Request $request, Contract $contract)
    {
        $user = $request->user();
        $this->authorizeContractAccess($user, $contract);

        $analysis = ContractAiAnalysis::where('contract_id', $contract->id)
            ->latest()
            ->first();

        return response()->json([
            'data' => $analysis ? AiAnalysisPresenter::customerFacingContractAnalysis($analysis) : null,
        ]);
    }

    // ─── GET /ai/analyses/{analysis} ──────────────────────────────────────────

    public function showAnalysis(Request $request, ContractAiAnalysis $analysis)
    {
        $this->authorizeAnalysisAccess($request->user(), $analysis);

        return response()->json([
            'data' => AiAnalysisPresenter::customerFacingContractAnalysis($analysis->load(['contract', 'creator'])),
        ]);
    }

    // ─── POST /ai/analyses/{analysis}/reparse ─────────────────────────────────

    /**
     * Re-parse a previously stored AI response WITHOUT calling Claude again.
     * Useful when a paid-for response failed JSON parsing for a fixable reason
     * (wrapper text, formatting). Consumes no AI credits.
     */
    public function reparseAnalysis(Request $request, ContractAiAnalysis $analysis)
    {
        $this->authorizeAnalysisAccess($request->user(), $analysis);

        if (empty($analysis->raw_response_text)) {
            return response()->json([
                'message' => 'No saved response is available to re-parse for this analysis.',
            ], 422);
        }

        $decoded = $this->analysisService->reparse($analysis);

        if ($decoded === null) {
            return response()->json([
                'message' => $analysis->stop_reason === 'max_tokens'
                    ? 'The saved response was truncated and cannot be repaired. Please re-run the analysis.'
                    : 'The saved response still could not be parsed. Please re-run the analysis.',
            ], 422);
        }

        $summary = \App\Services\AI\ContractAnalysisPrompt::extractSummary($decoded);

        $analysis->update([
            'status'            => 'completed',
            'raw_response_json' => $decoded,
            'summary'           => $summary,
            'error_message'     => null,
            'completed_at'      => now(),
        ]);

        return response()->json([
            'data'    => AiAnalysisPresenter::customerFacingContractAnalysis($analysis->fresh()),
            'message' => 'Saved response re-parsed successfully. No AI credits were used.',
        ]);
    }

    // ─── POST /ai/analyses/{analysis}/confirm ─────────────────────────────────

    public function confirmAnalysis(Request $request, ContractAiAnalysis $analysis)
    {
        $this->authorizeAnalysisAccess($request->user(), $analysis);

        if (!$analysis->isCompleted() && !$analysis->isConfirmed()) {
            return response()->json(['message' => 'Analysis must be completed before confirming.'], 422);
        }

        $validated = $request->validate([
            'confirmed_data'  => 'required|array',
            'force_overwrite' => 'boolean',
        ]);

        $analysis->update([
            'status'              => 'confirmed',
            'confirmed_data_json' => $validated['confirmed_data'],
        ]);

        $overwrite = (bool) ($validated['force_overwrite'] ?? false);

        $this->syncService->sync($analysis, $validated['confirmed_data'], $overwrite);

        // Sync calendar events from the freshly-confirmed intelligence,
        // then queue notification generation so it reads the latest state.
        $contract = $analysis->contract;
        if ($contract) {
            // dispatchNotifications=false: we dispatch the job ourselves below
            // so calendar sync and notification generation don't race each other.
            $this->calendarSync->syncForContract($contract, dispatchNotifications: false);
            GenerateProjectNotificationsJob::dispatch($contract->project_id);
        }

        ActivityLog::record(
            'ai_analysis.confirmed',
            'AI analysis confirmed for contract "' . $analysis->contract->title . '"' . ($overwrite ? ' (overwrite)' : ''),
            $request->user(),
            $analysis->contract,
            ['analysis_id' => $analysis->id, 'overwrite' => $overwrite],
            $analysis->contract->project_id,
            $analysis->contract->organization_id
        );

        return response()->json([
            'data'    => AiAnalysisPresenter::customerFacingContractAnalysis($analysis->fresh()),
            'message' => 'Analysis confirmed.',
        ]);
    }

    // ─── POST /ai/analyses/{analysis}/cancel ──────────────────────────────────

    public function cancelAnalysis(Request $request, ContractAiAnalysis $analysis)
    {
        $this->authorizeAnalysisAccess($request->user(), $analysis);

        if (!in_array($analysis->status, ['pending', 'processing', 'completed', 'failed'])) {
            return response()->json(['message' => 'This analysis cannot be cancelled.'], 422);
        }

        // Free only if the worker hasn't sent the request to Claude yet (still 'pending').
        // Once 'processing', the API call is in flight and Anthropic will bill for it.
        $wasFree = $analysis->status === 'pending';

        $analysis->update(['status' => 'cancelled']);

        ActivityLog::record(
            'ai_analysis.cancelled',
            'AI analysis cancelled for contract "' . $analysis->contract->title . '"',
            $request->user(),
            $analysis->contract,
            ['analysis_id' => $analysis->id],
            $analysis->contract->project_id,
            $analysis->contract->organization_id
        );

        return response()->json([
            'message'  => $wasFree
                ? 'Analysis cancelled before it started. No AI credits were used.'
                : 'Analysis cancelled. It had already started, so the in-progress AI usage may still be charged.',
            'was_free' => $wasFree,
        ]);
    }

    // ─── POST /ai/analyses/{analysis}/generate-brief ─────────────────────────

    /**
     * Generate a Contract Intelligence Brief PDF from a confirmed AI analysis.
     * Does not call Claude — uses data already stored on the analysis and contract.
     */
    public function generateBrief(Request $request, ContractAiAnalysis $analysis)
    {
        $this->authorizeAnalysisAccess($request->user(), $analysis);

        if (!$analysis->isConfirmed()) {
            return response()->json([
                'message' => 'The analysis must be confirmed before generating a brief.',
            ], 422);
        }

        $contract = $analysis->contract;
        $project  = $contract->project;
        $user     = $request->user();

        $ref = 'CIB-' . str_pad((string) $analysis->id, 5, '0', STR_PAD_LEFT);

        try {
            $document = DocumentGenerationService::generatePdf(
                $project,
                $user,
                'pdfs.contract-intelligence-brief',
                [
                    'analysis'    => $analysis,
                    'contract'    => $contract,
                    'generatedBy' => $user,
                ],
                "Contract Intelligence Brief — {$contract->title}",
                'contract_intelligence_brief',
                '01_Contracts',
                $ref,
                $contract
            );
        } catch (\Throwable $e) {
            \Log::error('Contract Intelligence Brief generation failed', [
                'user_id'     => $user->id,
                'analysis_id' => $analysis->id,
                'contract_id' => $contract->id,
                'exception'   => $e,
            ]);
            return response()->json(['message' => 'The document could not be generated.'], 500);
        }

        ActivityLog::record(
            'ai_analysis.brief_generated',
            'Contract Intelligence Brief generated for "' . $contract->title . '"',
            $user,
            $contract,
            ['analysis_id' => $analysis->id, 'document_id' => $document->id],
            $project->id,
            $project->organization_id
        );

        NotificationService::send(
            $user,
            NotificationService::DOCUMENT_GENERATED,
            'Contract Intelligence Brief generated',
            "The Contract Intelligence Brief for \"{$contract->title}\" has been saved to Documents.",
            ['document_id' => $document->id, 'contract_id' => $contract->id]
        );

        return response()->json([
            'data'    => $document,
            'message' => 'Contract Intelligence Brief generated successfully.',
        ], 201);
    }

    // ─── GET /contracts/{contract}/ai-analyses ────────────────────────────────

    public function listAnalyses(Request $request, Contract $contract)
    {
        $user = $request->user();
        $this->authorizeContractAccess($user, $contract);

        $analyses = ContractAiAnalysis::where('contract_id', $contract->id)
            ->with(['creator:id,name,email'])
            ->latest()
            ->get();

        return response()->json([
            'data' => $analyses->map(fn (ContractAiAnalysis $a) => AiAnalysisPresenter::customerFacingContractAnalysis($a))->all(),
        ]);
    }

    // ─── GET /projects/{project}/ai-analyses ─────────────────────────────────

    public function listForProject(Request $request, Project $project)
    {
        $user = $request->user();

        if (!$user->hasRole('Super Admin') && !$user->hasRole('Admin')) {
            if ($user->organization_id !== $project->organization_id) {
                abort(403, 'Access denied.');
            }
        }

        $analyses = ContractAiAnalysis::where('project_id', $project->id)
            ->with(['contract:id,title', 'creator:id,name,email'])
            ->latest()
            ->get();

        return response()->json([
            'data' => $analyses->map(fn (ContractAiAnalysis $a) => AiAnalysisPresenter::customerFacingContractAnalysis($a))->all(),
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function authorizeContractAccess($user, Contract $contract): void
    {
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $contract->organization_id) abort(403, 'Access denied.');
    }

    private function authorizeAnalysisAccess($user, ContractAiAnalysis $analysis): void
    {
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $analysis->organization_id) abort(403, 'Access denied.');
    }
}
