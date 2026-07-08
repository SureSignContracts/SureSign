<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyseTradePackageWithAiJob;
use App\Jobs\GenerateProjectNotificationsJob;
use App\Models\ActivityLog;
use App\Models\FileUpload;
use App\Models\SuresignSetting;
use App\Models\TradePackage;
use App\Models\TradePackageAiAnalysis;
use App\Services\AI\TradePackageAnalysisService;
use App\Services\CalendarSyncService;
use App\Services\TradePackages\TradePackageIntelligenceSyncService;
use Illuminate\Http\Request;

/**
 * Sibling to AiController, scoped to Trade Package (subcontract) onboarding.
 * Deliberately separate — see Sprint 6B review: the underlying analysis
 * table, prompt, and sync service are all trade-package-specific, so this
 * controller mirrors AiController's shape rather than extending it.
 */
class TradePackageAiController extends Controller
{
    private const SUPPORTED_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword',
        'text/plain',
    ];

    public function __construct(
        private TradePackageAnalysisService $analysisService,
        private TradePackageIntelligenceSyncService $syncService,
        private CalendarSyncService $calendarSync,
    ) {}

    // ─── POST /trade-packages/{tradePackage}/ai-analysis ──────────────────────

    public function startAnalysis(Request $request, TradePackage $tradePackage)
    {
        $user = $request->user();
        $this->authorizePackageAccess($user, $tradePackage);

        if (!$this->analysisService->isEnabled()) {
            return response()->json(['message' => 'AI features are disabled.'], 403);
        }

        $active = TradePackageAiAnalysis::where('trade_package_id', $tradePackage->id)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if ($active) {
            return response()->json(['message' => 'An analysis is already in progress for this trade package.'], 409);
        }

        if (!$request->boolean('force_new')) {
            $existing = TradePackageAiAnalysis::where('trade_package_id', $tradePackage->id)
                ->whereIn('status', ['completed', 'confirmed'])
                ->latest()
                ->first();

            if ($existing) {
                return response()->json([
                    'existing_analysis' => $existing,
                    'message'           => 'A completed analysis already exists for this trade package.',
                ], 200);
            }
        }

        $fileUpload = $this->resolveFileUpload($request, $tradePackage);

        if (!$fileUpload) {
            return response()->json([
                'message' => 'No supported subcontract file found. Please upload a PDF, DOCX, or TXT file first.',
            ], 422);
        }

        $settings = SuresignSetting::instance();

        $analysis = TradePackageAiAnalysis::create([
            'trade_package_id' => $tradePackage->id,
            'organization_id'  => $tradePackage->organization_id,
            'project_id'       => $tradePackage->project_id,
            'file_upload_id'   => $fileUpload->id,
            'status'           => 'pending',
            'provider'         => $settings->ai_provider ?? 'anthropic',
            'model'            => $settings->ai_model ?? config('ai.anthropic.model'),
            'created_by'       => $user->id,
        ]);

        AnalyseTradePackageWithAiJob::dispatch($analysis->id, $fileUpload->id, $user->id);

        return response()->json([
            'data'    => $analysis->fresh(),
            'message' => 'Subcontract analysis started.',
        ], 201);
    }

    // ─── GET /trade-packages/{tradePackage}/ai-analysis ───────────────────────

    public function getLatestAnalysis(Request $request, TradePackage $tradePackage)
    {
        $this->authorizePackageAccess($request->user(), $tradePackage);

        $analysis = TradePackageAiAnalysis::where('trade_package_id', $tradePackage->id)
            ->latest()
            ->first();

        return response()->json(['data' => $analysis]);
    }

    // ─── GET /trade-packages/{tradePackage}/ai-analyses ───────────────────────

    public function listAnalyses(Request $request, TradePackage $tradePackage)
    {
        $this->authorizePackageAccess($request->user(), $tradePackage);

        $analyses = TradePackageAiAnalysis::where('trade_package_id', $tradePackage->id)
            ->with(['creator:id,name,email'])
            ->latest()
            ->get();

        return response()->json(['data' => $analyses]);
    }

    // ─── GET /trade-package-ai-analyses/{analysis} ────────────────────────────

    public function showAnalysis(Request $request, TradePackageAiAnalysis $analysis)
    {
        $this->authorizeAnalysisAccess($request->user(), $analysis);

        return response()->json(['data' => $analysis->load(['tradePackage', 'fileUpload'])]);
    }

    // ─── POST /trade-package-ai-analyses/{analysis}/reparse ───────────────────

    public function reparseAnalysis(Request $request, TradePackageAiAnalysis $analysis)
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

        $summary = \Illuminate\Support\Str::limit(data_get($decoded, 'general.subcontract_title', null), 1000) ?: null;

        $analysis->update([
            'status'            => 'completed',
            'raw_response_json' => $decoded,
            'summary'           => $summary,
            'error_message'     => null,
            'completed_at'      => now(),
        ]);

        return response()->json([
            'data'    => $analysis->fresh(),
            'message' => 'Saved response re-parsed successfully. No AI credits were used.',
        ]);
    }

    // ─── POST /trade-package-ai-analyses/{analysis}/confirm ───────────────────

    public function confirmAnalysis(Request $request, TradePackageAiAnalysis $analysis)
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
            'confirmed_at'        => now(),
        ]);

        $overwrite = (bool) ($validated['force_overwrite'] ?? false);

        $this->syncService->sync($analysis, $validated['confirmed_data'], $overwrite);

        $tradePackage = $analysis->tradePackage;
        if ($tradePackage) {
            // dispatchNotifications=false: dispatched ourselves below so calendar sync
            // and notification generation don't race each other.
            $this->calendarSync->syncForTradePackage($tradePackage, dispatchNotifications: false);
            GenerateProjectNotificationsJob::dispatch($tradePackage->project_id);
        }

        ActivityLog::record(
            'trade_package_ai_analysis.confirmed',
            'AI analysis confirmed for trade package "' . $analysis->tradePackage->name . '"' . ($overwrite ? ' (overwrite)' : ''),
            $request->user(),
            $analysis->tradePackage,
            ['analysis_id' => $analysis->id, 'overwrite' => $overwrite],
            $analysis->tradePackage->project_id,
            $analysis->tradePackage->organization_id
        );

        return response()->json([
            'data'    => $analysis->fresh(),
            'message' => 'Analysis confirmed.',
        ]);
    }

    // ─── POST /trade-package-ai-analyses/{analysis}/cancel ────────────────────

    public function cancelAnalysis(Request $request, TradePackageAiAnalysis $analysis)
    {
        $this->authorizeAnalysisAccess($request->user(), $analysis);

        if (!in_array($analysis->status, ['pending', 'processing', 'completed', 'failed'])) {
            return response()->json(['message' => 'This analysis cannot be cancelled.'], 422);
        }

        $wasFree = $analysis->status === 'pending';

        $analysis->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        ActivityLog::record(
            'trade_package_ai_analysis.cancelled',
            'AI analysis cancelled for trade package "' . $analysis->tradePackage->name . '"',
            $request->user(),
            $analysis->tradePackage,
            ['analysis_id' => $analysis->id],
            $analysis->tradePackage->project_id,
            $analysis->tradePackage->organization_id
        );

        return response()->json([
            'message'  => $wasFree
                ? 'Analysis cancelled before it started. No AI credits were used.'
                : 'Analysis cancelled. It had already started, so the in-progress AI usage may still be charged.',
            'was_free' => $wasFree,
        ]);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function resolveFileUpload(Request $request, TradePackage $tradePackage): ?FileUpload
    {
        if ($request->filled('file_upload_id')) {
            $fileUpload = FileUpload::where('id', $request->input('file_upload_id'))
                ->where('organization_id', $tradePackage->organization_id)
                ->first();

            if ($fileUpload) return $fileUpload;
        }

        // Prefer the file explicitly tagged as the executed contract...
        $fileUpload = FileUpload::where('trade_package_id', $tradePackage->id)
            ->where('document_type', 'executed_contract')
            ->whereIn('mime_type', self::SUPPORTED_MIME_TYPES)
            ->latest()
            ->first();

        // ...else fall back to the latest supported upload on the package.
        return $fileUpload ?? FileUpload::where('trade_package_id', $tradePackage->id)
            ->whereIn('mime_type', self::SUPPORTED_MIME_TYPES)
            ->latest()
            ->first();
    }

    private function authorizePackageAccess($user, TradePackage $tradePackage): void
    {
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $tradePackage->organization_id) abort(403, 'Access denied.');
    }

    private function authorizeAnalysisAccess($user, TradePackageAiAnalysis $analysis): void
    {
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $analysis->organization_id) abort(403, 'Access denied.');
    }
}
