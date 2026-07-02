<?php

namespace App\Jobs;

use App\Models\ContractAiAnalysis;
use App\Models\FileUpload;
use App\Models\User;
use App\Services\AI\ContractAnalysisService;
use App\Services\NotificationService;
use App\Services\EmailNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        $analysis = ContractAiAnalysis::find($this->analysisId);

        if (!$analysis) {
            Log::warning("AnalyseContractWithAiJob: analysis {$this->analysisId} not found.");
            return;
        }

        // Idempotency guard: only a freshly-dispatched ('pending') analysis should run.
        // If it is already processing/completed/failed, this is a duplicate delivery
        // (e.g. retry_after re-reservation or a worker restart) — skip it so we never
        // call Claude twice and double-bill for the same analysis.
        if ($analysis->status !== 'pending') {
            Log::warning("AnalyseContractWithAiJob: analysis {$this->analysisId} already '{$analysis->status}', skipping duplicate run.");
            return;
        }

        $analysis->update(['status' => 'processing', 'started_at' => now()]);

        $fileUpload = FileUpload::find($this->fileUploadId);
        $user       = User::find($this->requestingUserId);

        try {
            if (!$fileUpload) {
                throw new \RuntimeException('Contract file not found.');
            }

            $result       = $service->analyse($analysis, $fileUpload);

            // If the user cancelled while the Claude call was in flight, honour the
            // cancellation: keep the raw response (already saved by the service, since it
            // was paid for) but do NOT overwrite the 'cancelled' status with 'completed'.
            $analysis->refresh();
            if ($analysis->status === 'cancelled') {
                Log::info("AnalyseContractWithAiJob: analysis {$this->analysisId} was cancelled during processing; discarding result.");
                return;
            }

            $data         = $result['data'];
            $tokensInput  = $result['tokens_input'];
            $tokensOutput = $result['tokens_output'];

            // Claude-sonnet-4-6 pricing: $3/M input, $15/M output
            $estimatedCost = round(($tokensInput * 3 + $tokensOutput * 15) / 1_000_000, 6);

            // Extract summary from AI response
            $summary = Str::limit(data_get($data, 'contract_summary', null), 1000) ?: null;

            $analysis->update([
                'status'            => 'completed',
                'raw_response_json' => $data,
                'summary'           => $summary,
                'tokens_input'      => $tokensInput,
                'tokens_output'     => $tokensOutput,
                'estimated_cost'    => $estimatedCost,
                'completed_at'      => now(),
                'error_message'     => null,
            ]);

            if ($user) {
                NotificationService::send(
                    $user,
                    NotificationService::AI_ANALYSIS_COMPLETED,
                    'Contract analysis completed',
                    "AI analysis is ready for contract: {$analysis->contract->title}.",
                    ['analysis_id' => $analysis->id, 'contract_id' => $analysis->contract_id]
                );

                EmailNotificationService::send(
                    'ai_analysis.completed',
                    'Contract Analysis Complete',
                    "AI analysis is ready for contract: {$analysis->contract->title}. Log in to review and confirm the results."
                );
            }
        } catch (\Throwable $e) {
            Log::error("AnalyseContractWithAiJob failed for analysis {$this->analysisId}: " . $e->getMessage());

            $analysis->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);

            if ($user) {
                NotificationService::send(
                    $user,
                    NotificationService::AI_ANALYSIS_COMPLETED,
                    'Contract analysis failed',
                    "AI analysis failed for contract: {$analysis->contract->title}. {$e->getMessage()}",
                    ['analysis_id' => $analysis->id, 'contract_id' => $analysis->contract_id]
                );
            }
        }
    }
}
