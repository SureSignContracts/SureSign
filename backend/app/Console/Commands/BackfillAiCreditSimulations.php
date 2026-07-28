<?php

namespace App\Console\Commands;

use App\Models\AiCreditSimulationResult;
use App\Models\ContractAiAnalysis;
use App\Models\FileUpload;
use App\Models\TradePackageAiAnalysis;
use App\Services\AI\AiCreditSimulator;
use App\Services\AI\AiInputNormalizer;
use App\Services\AI\ContractAnalysisService;
use App\Support\AI\AiWorkflow;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Phase G4C.2C-2 — manual/on-demand only, deliberately NOT scheduled (an
 * unbounded historical scan has no time-critical reason to run
 * automatically; the prospective path already covers everything going
 * forward — see AnalyseContractWithAiJob/AnalyseTradePackageWithAiJob).
 *
 * Populates App\Models\AiCreditSimulationResult for AI executions that
 * completed BEFORE simulation existed, so calibration has the full
 * historical sample, not just executions from this point forward.
 *
 * Hard invariants:
 *  - NEVER calls the AI provider and NEVER re-runs analysis — this command
 *    has no dependency on AiProviderInterface/ClaudeAiProvider at all.
 *  - NEVER alters the analysis row itself (status, telemetry, or any other
 *    column) — only writes to ai_credit_simulation_results.
 *  - Re-extracting document text from the original FileUpload is a LOCAL
 *    operation (same as ContractAnalysisService::extractText() already
 *    does at analysis time) — this is not a provider call and is
 *    explicitly permitted.
 *  - When the original document can no longer be reconstructed (file
 *    deleted, upload record missing, unsupported/corrupted content, or
 *    the re-extracted text's hash no longer matches the analysis's own
 *    document_hash — meaning the file has changed since analysis and can
 *    no longer be trusted as the same input), the input is recorded as
 *    genuinely unavailable (AiCreditSimulator::STATUS_UNAVAILABLE) —
 *    never guessed, never substituted with document_char_count alone.
 *  - Idempotent, resumable, and scoped: reruns only update existing rows
 *    (AiCreditSimulator's own upsert), --limit/--workflow/--analysis-id
 *    bound scope, and progress is reported per row so a long run can be
 *    observed or safely interrupted and resumed later.
 */
class BackfillAiCreditSimulations extends Command
{
    protected $signature = 'ai:credits:backfill-simulations
        {--workflow= : Only backfill this workflow (contract_analysis|trade_package_analysis)}
        {--analysis-id= : Only backfill this specific analysis id (requires --workflow)}
        {--limit=500 : Maximum analyses to process per workflow in this run}
        {--dry-run : Report what would happen without writing any simulation rows}';

    protected $description = 'Backfill non-enforcing AI Credit simulation results for already-completed AI executions (never calls the AI provider, never alters existing analyses)';

    public function handle(AiCreditSimulator $simulator, ContractAnalysisService $extractor): int
    {
        $workflowFilter = $this->option('workflow');
        $analysisId = $this->option('analysis-id') !== null ? (int) $this->option('analysis-id') : null;
        $limit = max(1, (int) ($this->option('limit') ?: 500));
        $dryRun = (bool) $this->option('dry-run');

        if ($analysisId !== null && $workflowFilter === null) {
            $this->error('--analysis-id requires --workflow to also be specified.');
            return self::FAILURE;
        }

        if ($workflowFilter !== null && !in_array($workflowFilter, AiWorkflow::ALL, true)) {
            $this->error("Unknown workflow '{$workflowFilter}'. Valid: " . implode(', ', AiWorkflow::ALL));
            return self::FAILURE;
        }

        $summary = ['calculated' => 0, 'unavailable' => 0, 'unresolved' => 0, 'error' => 0, 'skipped_no_source' => 0];

        foreach (AiWorkflow::ALL as $workflow) {
            if ($workflowFilter !== null && $workflow !== $workflowFilter) {
                continue;
            }

            $this->processWorkflow($workflow, $analysisId, $limit, $dryRun, $simulator, $extractor, $summary);
        }

        $this->newLine();
        $this->info('AI Credit simulation backfill summary (non-enforcing, informational only):');
        foreach ($summary as $key => $count) {
            $this->line("  {$key}: {$count}");
        }

        return self::SUCCESS;
    }

    private function processWorkflow(
        string $workflow,
        ?int $analysisId,
        int $limit,
        bool $dryRun,
        AiCreditSimulator $simulator,
        ContractAnalysisService $extractor,
        array &$summary
    ): void {
        $modelClass = $workflow === AiWorkflow::CONTRACT_ANALYSIS ? ContractAiAnalysis::class : TradePackageAiAnalysis::class;

        $query = $modelClass::query()
            ->whereIn('status', ['completed', 'confirmed', 'failed', 'cancelled'])
            ->whereNotNull('document_hash')
            ->when($analysisId !== null, fn (Builder $q) => $q->where('id', $analysisId))
            ->orderBy('id')
            ->limit($limit);

        $analyses = $query->get();

        if ($analyses->isEmpty()) {
            $this->line("[{$workflow}] no matching analyses found.");
            return;
        }

        $this->line("[{$workflow}] processing {$analyses->count()} analysis(es)...");

        foreach ($analyses as $analysis) {
            $normalizedCharCount = $this->reconstructNormalizedCharCount($analysis, $extractor);

            if ($normalizedCharCount === null) {
                $summary['skipped_no_source']++;
            }

            if ($dryRun) {
                $this->line("  #{$analysis->id}: would simulate (" . ($normalizedCharCount === null ? 'unavailable input' : "{$normalizedCharCount} normalized chars") . ')');
                continue;
            }

            $simulator->simulate(
                $analysis,
                $workflow,
                $normalizedCharCount,
                $analysis->completed_at ?? $analysis->created_at,
                AiCreditSimulator::SOURCE_BACKFILL
            );

            $rows = AiCreditSimulationResult::query()
                ->where('analysable_type', $modelClass)
                ->where('analysable_id', $analysis->id)
                ->get();

            foreach ($rows as $row) {
                $summary[$row->simulation_status] = ($summary[$row->simulation_status] ?? 0) + 1;
            }

            $this->line("  #{$analysis->id}: done");
        }
    }

    /**
     * Attempts to re-derive the exact normalized input measurement for an
     * already-completed analysis by re-extracting text from its original
     * FileUpload — purely local, no provider call. Returns null (never a
     * guess) whenever the original input can no longer be reliably
     * reconstructed.
     */
    private function reconstructNormalizedCharCount($analysis, ContractAnalysisService $extractor): ?int
    {
        if (!$analysis->file_upload_id) {
            return null;
        }

        $fileUpload = FileUpload::find($analysis->file_upload_id);

        if (!$fileUpload) {
            return null;
        }

        try {
            $text = $extractor->extractText($fileUpload);
        } catch (\Throwable $e) {
            Log::info('BackfillAiCreditSimulations: could not re-extract source text; recording unavailable', [
                'analysis_id' => $analysis->id,
                'reason'      => $e->getMessage(),
            ]);
            return null;
        }

        // Integrity check — the file at this path may have been replaced
        // since the analysis originally ran. Only trust the re-extraction if
        // it hashes to the exact same document_hash recorded at analysis
        // time; otherwise this is no longer the same input and must be
        // treated as unavailable, never silently substituted.
        if ($analysis->document_hash && hash('sha256', $text) !== $analysis->document_hash) {
            Log::warning('BackfillAiCreditSimulations: re-extracted text hash does not match recorded document_hash; recording unavailable', [
                'analysis_id' => $analysis->id,
            ]);
            return null;
        }

        return AiInputNormalizer::normalizedCharCount($text);
    }
}
