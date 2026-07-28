<?php

namespace App\Jobs;

use App\Models\ContractAiAnalysis;
use App\Models\FileUpload;
use App\Models\User;
use App\Services\AI\AiCreditSimulator;
use App\Services\AI\AiCreditWorkflowLifecycle;
use App\Services\AI\AiFailureClassifier;
use App\Services\AI\ContractAnalysisService;
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

class AnalyseContractWithAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 480; // < queue retry_after (600s); > HTTP timeout (420s)

    public function __construct(
        private int $analysisId,
        private int $fileUploadId,
        private int $requestingUserId,
    ) {}

    public function handle(ContractAnalysisService $service): void
    {
        $credits = app(AiCreditWorkflowLifecycle::class);
        $analysis = ContractAiAnalysis::find($this->analysisId);

        if (!$analysis) {
            Log::warning("AnalyseContractWithAiJob: analysis {$this->analysisId} not found.");
            return;
        }

        // Idempotency guard: only a freshly-dispatched ('pending') analysis should run.
        // If it is already processing/completed/failed, this is a duplicate delivery
        // (e.g. retry_after re-reservation or a worker restart) — skip it so we never
        // call Claude twice and double-bill for the same analysis. This also means a
        // duplicate delivery never reaches the credit lifecycle below at all — the
        // ledger's own idempotency (see AiCreditLedgerService) is defense-in-depth,
        // not the only protection.
        if ($analysis->status !== 'pending') {
            Log::warning("AnalyseContractWithAiJob: analysis {$this->analysisId} already '{$analysis->status}', skipping duplicate run.");
            return;
        }

        // Laravel queue attempt bookkeeping — both AI jobs run with $tries = 1, so
        // this mainly documents that no queue-level retry happened; a manual
        // force_new retry after a failure is always a separate analysis row, never
        // a second attempt on this one (see AiFailureClassifier's docblock).
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
                throw new \RuntimeException('Contract file not found.');
            }

            // Phase G4C.3BC — resolve the shadow credit amount and reserve BEFORE
            // the provider is called, using a first, separate extraction pass (see
            // ContractAnalysisService::extractAndRecordDocumentMetrics()) so
            // analyse() itself never re-extracts the same document twice.
            $prepared = $service->extractAndRecordDocumentMetrics($analysis, $fileUpload);
            $shadow = $credits->reserveFor(
                AiWorkflow::CONTRACT_ANALYSIS,
                ContractAiAnalysis::class,
                $analysis->id,
                $analysis->organization_id,
                $prepared['normalized_input_char_count']
            );
            $analysis->update($shadow);

            // Phase G4C.3I — real enforcement, only active in ENFORCED mode
            // (suresign_settings.ai_credit_operating_mode, toggled via the
            // Super Admin AI Credits operating mode control — defaults to
            // SHADOW). Thrown BEFORE the provider is ever called, so a
            // blocked analysis never incurs a real AI cost. Flows through
            // the catch block below exactly like any other curated
            // RuntimeException.
            if ($credits->shouldBlock($shadow)) {
                throw new AiCreditEnforcementException(
                    "This organisation's monthly AI usage allowance has been used. AI analysis will resume once your allowance resets, or contact support to increase it."
                );
            }

            $result       = $service->analyse($analysis, $fileUpload, $prepared);

            // If the user cancelled while the Claude call was in flight, honour the
            // cancellation: keep the raw response (already saved by the service, since it
            // was paid for) but do NOT overwrite the 'cancelled' status with 'completed'.
            $analysis->refresh();
            if ($analysis->status === 'cancelled') {
                Log::info("AnalyseContractWithAiJob: analysis {$this->analysisId} was cancelled during processing; discarding result.");
                $credits->releaseFor(AiWorkflow::CONTRACT_ANALYSIS, ContractAiAnalysis::class, $analysis->id, 'Analysis cancelled during processing');
                return;
            }

            $data         = $result['data'];
            $tokensInput  = $result['tokens_input'];
            $tokensOutput = $result['tokens_output'];
            $normalizedInputCharCount = $result['normalized_input_char_count'] ?? null;

            // Extract summary from AI response — see ContractAnalysisPrompt::extractSummary()
            // for why this isn't a flat data_get('contract_summary') anymore.
            $summary = \App\Services\AI\ContractAnalysisPrompt::extractSummary($data);

            $completedAt = now();

            // estimated_cost is deliberately NOT set here — ContractAnalysisService::analyse()
            // is the single authoritative place it's computed and persisted (both the real
            // provider-call path and the cache-hit path), so this update never recomputes it.
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

            // Phase G4C.3BC — settle the shadow reservation now that the analysis
            // genuinely completed. A no-op (safe, logged, non-fatal) if reserveFor()
            // above never opened a reservation (shadow_enforcement_result was
            // 'unresolved' — no shadow policy configured).
            $credits->settleFor(AiWorkflow::CONTRACT_ANALYSIS, ContractAiAnalysis::class, $analysis->id);

            // Phase G4C.2C-2 — non-enforcing AI Credit simulation. Runs after the
            // customer-visible 'completed' write above, on the same already-queued
            // job (no extra customer wait), makes no provider call, and must never
            // affect the analysis itself — any failure here is caught and logged,
            // never rethrown into this job's own catch block below. Skipped
            // entirely in DISABLED mode — the operating mode's contract is that
            // no simulation is attempted, not merely that it's non-blocking.
            if (!AiCreditOperatingMode::isDisabled()) {
                try {
                    app(AiCreditSimulator::class)->simulate(
                        $analysis->fresh(),
                        AiWorkflow::CONTRACT_ANALYSIS,
                        $normalizedInputCharCount,
                        $completedAt,
                        AiCreditSimulator::SOURCE_PROSPECTIVE
                    );
                } catch (\Throwable $e) {
                    Log::error('AnalyseContractWithAiJob: AI Credit simulation failed (non-fatal, analysis unaffected)', [
                        'analysis_id' => $this->analysisId,
                        'exception'   => $e,
                    ]);
                }
            }

            if ($user) {
                // Asynchronous outcome — the requesting user wasn't watching when
                // this finished, so they're included alongside the rest of the org.
                NotificationService::sendToOrganization(
                    $user->organization,
                    NotificationService::AI_ANALYSIS_COMPLETED,
                    'Contract analysis completed',
                    "AI analysis is ready for contract: {$analysis->contract->title}.",
                    ['analysis_id' => $analysis->id, 'contract_id' => $analysis->contract_id],
                    [
                        'organization_id' => $user->organization_id,
                        'source_type' => 'contract_ai_analysis', 'source_id' => $analysis->id, 'source_field' => 'completed',
                        'action_url' => \App\Services\TradePackages\WorkspaceNavigationResolver::actionUrl(
                            $analysis->contract->project_id, 'contract_ai_analysis', $analysis->id
                        ),
                    ],
                    $user,
                    includeActor: true,
                );

                EmailNotificationService::send(
                    'ai_analysis.completed',
                    'Contract Analysis Complete',
                    "AI analysis is ready for contract: {$analysis->contract->title}. Log in to review and confirm the results.",
                    [],
                    $user->organization
                );
            }
        } catch (\Throwable $e) {
            Log::error('AnalyseContractWithAiJob failed', [
                'analysis_id' => $this->analysisId,
                'contract_id' => $analysis->contract_id,
                'user_id'     => $this->requestingUserId,
                'exception'   => $e,
            ]);

            // RuntimeException is this AI pipeline's own convention for an
            // already-curated, safe-to-display message (missing file,
            // unsupported type, provider unavailable, etc — see
            // ContractAnalysisService/ClaudeAiProvider). Anything else is an
            // unexpected failure (DB, memory, type error, ...) and must not
            // have its raw message persisted to error_message, since that
            // column is returned as-is to the client via showAnalysis/
            // getLatestAnalysis.
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

            // Phase G4C.3BC — release whatever reservation reserveFor() opened above.
            // Safe (logged, non-fatal) even when no reservation exists — e.g. the
            // file-not-found branch throws before reserveFor() is ever reached.
            $credits->releaseFor(AiWorkflow::CONTRACT_ANALYSIS, ContractAiAnalysis::class, $analysis->id, 'AI analysis failed: ' . $safeMessage);

            if ($user) {
                NotificationService::sendToOrganization(
                    $user->organization,
                    NotificationService::AI_ANALYSIS_COMPLETED,
                    'Contract analysis failed',
                    "AI analysis failed for contract: {$analysis->contract->title}. {$safeMessage}",
                    ['analysis_id' => $analysis->id, 'contract_id' => $analysis->contract_id],
                    [
                        'organization_id' => $user->organization_id,
                        'priority' => \App\Models\SuresignNotification::PRIORITY_WARNING,
                        'source_type' => 'contract_ai_analysis', 'source_id' => $analysis->id, 'source_field' => 'failed',
                        'action_url' => \App\Services\TradePackages\WorkspaceNavigationResolver::actionUrl(
                            $analysis->contract->project_id, 'contract_ai_analysis', $analysis->id
                        ),
                    ],
                    $user,
                    includeActor: true,
                );

                EmailNotificationService::send(
                    'ai_analysis.failed',
                    'Contract Analysis Failed',
                    "AI analysis failed for contract: {$analysis->contract->title}. {$safeMessage}",
                    [],
                    $user->organization
                );
            }
        }
    }

    /**
     * Phase G4C.3BC — Laravel calls this when the job ultimately fails outside
     * handle()'s own try/catch, most notably a hard $timeout kill (the worker
     * terminates the child process directly; handle()'s catch never runs).
     * Pre-dates credits: this closes a real, previously-unhandled gap where a
     * timed-out analysis was left stuck at status='processing' forever with no
     * automatic recovery — and, now, also releases whatever shadow reservation
     * was open, so a hard timeout never leaves an orphaned reservation either.
     */
    public function failed(\Throwable $exception): void
    {
        $analysis = ContractAiAnalysis::find($this->analysisId);

        if (!$analysis || $analysis->status !== 'processing') {
            // Already resolved by handle() itself, or never got that far — nothing to do.
            return;
        }

        Log::error('AnalyseContractWithAiJob: job-level failure (likely a timeout) — recovering the stuck analysis', [
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
            AiWorkflow::CONTRACT_ANALYSIS,
            ContractAiAnalysis::class,
            $analysis->id,
            'AI analysis job failed at the queue level (timeout or unrecoverable error)'
        );
    }
}
