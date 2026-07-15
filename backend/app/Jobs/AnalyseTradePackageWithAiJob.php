<?php

namespace App\Jobs;

use App\Models\FileUpload;
use App\Models\TradePackageAiAnalysis;
use App\Models\User;
use App\Services\AI\TradePackageAnalysisService;
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
        $analysis = TradePackageAiAnalysis::find($this->analysisId);

        if (!$analysis) {
            Log::warning("AnalyseTradePackageWithAiJob: analysis {$this->analysisId} not found.");
            return;
        }

        // Idempotency guard: only a freshly-dispatched ('pending') analysis should run.
        if ($analysis->status !== 'pending') {
            Log::warning("AnalyseTradePackageWithAiJob: analysis {$this->analysisId} already '{$analysis->status}', skipping duplicate run.");
            return;
        }

        $analysis->update(['status' => 'processing', 'started_at' => now()]);

        $fileUpload = FileUpload::find($this->fileUploadId);
        $user       = User::find($this->requestingUserId);

        try {
            if (!$fileUpload) {
                throw new \RuntimeException('Subcontract file not found.');
            }

            $result = $service->analyse($analysis, $fileUpload);

            // If the user cancelled while the Claude call was in flight, honour the
            // cancellation: keep the raw response (already saved, since it was paid for)
            // but do NOT overwrite the 'cancelled' status with 'completed'.
            $analysis->refresh();
            if ($analysis->status === 'cancelled') {
                Log::info("AnalyseTradePackageWithAiJob: analysis {$this->analysisId} was cancelled during processing; discarding result.");
                return;
            }

            $data         = $result['data'];
            $tokensInput  = $result['tokens_input'];
            $tokensOutput = $result['tokens_output'];
            $estimatedCost = round(($tokensInput * 3 + $tokensOutput * 15) / 1_000_000, 6);

            $summary = Str::limit(data_get($data, 'general.subcontract_title', null), 1000) ?: null;

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

            $analysis->update([
                'status'        => 'failed',
                'error_message' => $safeMessage,
                'completed_at'  => now(),
            ]);

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
}
