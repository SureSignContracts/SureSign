<?php

namespace App\Jobs;

use App\Models\FileUpload;
use App\Models\TradePackageAiAnalysis;
use App\Models\User;
use App\Services\AI\AiCreditSimulator;
use App\Services\AI\AiCreditWorkflowLifecycle;
use App\Services\AI\AiFailureClassifier;
use App\Services\AI\TradePackageAnalysisService;
use App\Support\AI\AiCreditEnforcementException;
use App\Support\AI\AiCreditOperatingMode;
use App\Support\AI\AiWorkflow;
use App\Services\NotificationService;
use App\Services\EmailNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AnalyseTradePackageWithAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 480; // < queue retry_after (600s); > HTTP timeout (420s)

    public function __construct(
        private int $analysisId,
        private int $fileUploadId,
        private int $requestingUserId,
    ) {}

    public function handle(TradePackageAnalysisService $service): void
    {
        $credits = app(AiCreditWorkflowLifecycle::class);
        $analysis = TradePackageAiAnalysis::find($this->analysisId);

        if (!$analysis) {
            Log::warning("AnalyseTradePackageWithAiJob: analysis {$this->analysisId} not found.");
            return;
        }

        // Idempotency guard: only a freshly-dispatched ('pending') analysis should run.
        // This also means a duplicate delivery never reaches the credit lifecycle
        // below — see AnalyseContractWithAiJob's equivalent comment.
        if ($analysis->status !== 'pending') {
            Log::warning("AnalyseTradePackageWithAiJob: analysis {$this->analysisId} already '{$analysis->status}', skipping duplicate run.");
            return;
        }

        // See AnalyseContractWithAiJob for why this is recorded ($tries = 1 on both jobs).
        $attempt = $this->job?->attempts() ?? 1;

        $analysis->update([
            'status'           => 'processing',
            'started_at'       => now(),
            'queue_attempt'    => $attempt,
            'is_final_attempt' => $attempt >= $this->tries,
        ]);

        $fileUpload = FileUpload::find($this->fileUploadId);
        $user       = User::find($this->requestingUserId);

        try {
            if (!$fileUpload) {
                throw new \RuntimeException('Subcontract file not found.');
            }

            // Phase G4C.3BC — see AnalyseContractWithAiJob for the full rationale;
            // identical wiring for this workflow.
            $prepared = $service->extractAndRecordDocumentMetrics($analysis, $fileUpload);
            $shadow = $credits->reserveFor(
                AiWorkflow::TRADE_PACKAGE_ANALYSIS,
                TradePackageAiAnalysis::class,
                $analysis->id,
                $analysis->organization_id,
                $prepared['normalized_input_char_count']
            );
            $analysis->update($shadow);

            // Phase G4C.3I — see AnalyseContractWithAiJob for the full
            // rationale; identical wiring for this workflow.
            if ($credits->shouldBlock($shadow)) {
                throw new AiCreditEnforcementException(
                    "This organisation's monthly AI usage allowance has been used. AI analysis will resume once your allowance resets, or contact support to increase it."
                );
            }

            $result = $service->analyse($analysis, $fileUpload, $prepared);

            // If the user cancelled while the Claude call was in flight, honour the
            // cancellation: keep the raw response (already saved, since it was paid for)
            // but do NOT overwrite the 'cancelled' status with 'completed'.
            $analysis->refresh();
            if ($analysis->status === 'cancelled') {
                Log::info("AnalyseTradePackageWithAiJob: analysis {$this->analysisId} was cancelled during processing; discarding result.");
                $credits->releaseFor(AiWorkflow::TRADE_PACKAGE_ANALYSIS, TradePackageAiAnalysis::class, $analysis->id, 'Analysis cancelled during processing');
                return;
            }

            $data         = $result['data'];
            $tokensInput  = $result['tokens_input'];
            $tokensOutput = $result['tokens_output'];
            $normalizedInputCharCount = $result['normalized_input_char_count'] ?? null;

            $summary = Str::limit(data_get($data, 'general.subcontract_title', null), 1000) ?: null;

            $completedAt = now();

            // estimated_cost is deliberately NOT set here — see AnalyseContractWithAiJob;
            // TradePackageAnalysisService::analyse() is the single authoritative writer.
            $analysis->update([
                'status'            => 'completed',
                'raw_response_json' => $data,
                'summary'           => $summary,
                'tokens_input'      => $tokensInput,
                'tokens_output'     => $tokensOutput,
                'completed_at'      => $completedAt,
                'duration_ms'       => $analysis->started_at?->diffInMilliseconds($completedAt),
                'error_message'     => null,
            ]);

            // Phase G4C.3BC — see AnalyseContractWithAiJob for the full rationale;
            // identical wiring for this workflow.
            $credits->settleFor(AiWorkflow::TRADE_PACKAGE_ANALYSIS, TradePackageAiAnalysis::class, $analysis->id);

            // Phase G4C.2C-2 — see AnalyseContractWithAiJob for the full rationale;
            // identical non-fatal wiring for the Trade Package workflow. Skipped
            // entirely in DISABLED mode — see AnalyseContractWithAiJob's
            // equivalent comment.
            if (!AiCreditOperatingMode::isDisabled()) {
                try {
                    app(AiCreditSimulator::class)->simulate(
                        $analysis->fresh(),
                        AiWorkflow::TRADE_PACKAGE_ANALYSIS,
                        $normalizedInputCharCount,
                        $completedAt,
                        AiCreditSimulator::SOURCE_PROSPECTIVE
                    );
                } catch (\Throwable $e) {
                    Log::error('AnalyseTradePackageWithAiJob: AI Credit simulation failed (non-fatal, analysis unaffected)', [
                        'analysis_id' => $this->analysisId,
                        'exception'   => $e,
                    ]);
                }
            }

            if ($user) {
                $tradePackage = $analysis->tradePackage;

                NotificationService::sendToOrganization(
                    $user->organization,
                    NotificationService::AI_ANALYSIS_COMPLETED,
                    'Subcontract analysis completed',
                    "AI analysis is ready for trade package: {$tradePackage->name}.",
                    ['analysis_id' => $analysis->id, 'trade_package_id' => $analysis->trade_package_id],
                    [
                        'organization_id' => $user->organization_id, 'project_id' => $tradePackage->project_id,
                        'source_type' => 'trade_package_ai_analysis', 'source_id' => $analysis->id, 'source_field' => 'completed',
                        'action_url' => \App\Services\TradePackages\WorkspaceNavigationResolver::actionUrl(
                            $tradePackage->project_id, 'trade_package', $tradePackage->id, $tradePackage->id
                        ),
                    ],
                    $user,
                    includeActor: true,
                );

                EmailNotificationService::send(
                    'ai_analysis.completed',
                    'Subcontract Analysis Complete',
                    "AI analysis is ready for trade package: {$analysis->tradePackage->name}. Log in to review and confirm the results.",
                    [],
                    $user->organization
                );
            }
        } catch (\Throwable $e) {
            Log::error('AnalyseTradePackageWithAiJob failed', [
                'analysis_id'      => $this->analysisId,
                'trade_package_id' => $analysis->trade_package_id,
                'user_id'          => $this->requestingUserId,
                'exception'        => $e,
            ]);

            // See AnalyseContractWithAiJob for why RuntimeException is treated
            // as already-safe (this AI pipeline's convention for a curated,
            // user-facing message) while any other Throwable is genericized
            // before being persisted to error_message / shown in-app.
            $safeMessage = $e instanceof \RuntimeException
                ? $e->getMessage()
                : 'The AI analysis could not be completed.';

            $completedAt = now();

            $analysis->update([
                'status'           => 'failed',
                'error_message'    => $safeMessage,
                'failure_category' => AiFailureClassifier::classify($e),
                'completed_at'     => $completedAt,
                'duration_ms'      => $analysis->started_at?->diffInMilliseconds($completedAt),
            ]);

            // Phase G4C.3BC — see AnalyseContractWithAiJob for the full rationale.
            $credits->releaseFor(AiWorkflow::TRADE_PACKAGE_ANALYSIS, TradePackageAiAnalysis::class, $analysis->id, 'AI analysis failed: ' . $safeMessage);

            if ($user) {
                $tradePackage = $analysis->tradePackage;

                NotificationService::sendToOrganization(
                    $user->organization,
                    NotificationService::AI_ANALYSIS_COMPLETED,
                    'Subcontract analysis failed',
                    "AI analysis failed for trade package: {$tradePackage->name}. {$safeMessage}",
                    ['analysis_id' => $analysis->id, 'trade_package_id' => $analysis->trade_package_id],
                    [
                        'organization_id' => $user->organization_id, 'project_id' => $tradePackage->project_id,
                        'priority' => \App\Models\SuresignNotification::PRIORITY_WARNING,
                        'source_type' => 'trade_package_ai_analysis', 'source_id' => $analysis->id, 'source_field' => 'failed',
                        'action_url' => \App\Services\TradePackages\WorkspaceNavigationResolver::actionUrl(
                            $tradePackage->project_id, 'trade_package', $tradePackage->id, $tradePackage->id
                        ),
                    ],
                    $user,
                    includeActor: true,
                );

                EmailNotificationService::send(
                    'ai_analysis.failed',
                    'Subcontract Analysis Failed',
                    "AI analysis failed for trade package: {$tradePackage->name}. {$safeMessage}",
                    [],
                    $user->organization
                );
            }
        }
    }

    /** Phase G4C.3BC — see AnalyseContractWithAiJob::failed() for the full rationale. */
    public function failed(\Throwable $exception): void
    {
        $analysis = TradePackageAiAnalysis::find($this->analysisId);

        if (!$analysis || $analysis->status !== 'processing') {
            return;
        }

        Log::error('AnalyseTradePackageWithAiJob: job-level failure (likely a timeout) — recovering the stuck analysis', [
            'analysis_id' => $this->analysisId,
            'exception'   => $exception,
        ]);

        $completedAt = now();

        $analysis->update([
            'status'           => 'failed',
            'error_message'    => 'The AI analysis could not be completed.',
            'failure_category' => AiFailureClassifier::classify($exception),
            'completed_at'     => $completedAt,
            'duration_ms'      => $analysis->started_at?->diffInMilliseconds($completedAt),
        ]);

        app(AiCreditWorkflowLifecycle::class)->releaseFor(
            AiWorkflow::TRADE_PACKAGE_ANALYSIS,
            TradePackageAiAnalysis::class,
            $analysis->id,
            'AI analysis job failed at the queue level (timeout or unrecoverable error)'
        );
    }
}
