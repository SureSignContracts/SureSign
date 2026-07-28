<?php

namespace App\Services\Monitoring;

use App\Models\AiCreditSimulationResult;
use App\Models\ContractAiAnalysis;
use App\Models\Organization;
use App\Models\TradePackageAiAnalysis;
use App\Support\AI\AiAnalysisPresenter;
use App\Support\AI\AiWorkflow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Phase G4C.2C-2 — internal, Super Admin/Admin-only AI execution and
 * non-enforcing AI Credit simulation reporting. Deliberately separate from
 * App\Services\Monitoring\ApplicationMonitoringService (that service's
 * `aiBlock()` is a single today-only operational snapshot with no
 * filters/pagination/cost figures — not the right shape for calibration
 * work) and from any customer-facing surface: every row here is built
 * from AiAnalysisPresenter's internal*() methods, never the
 * customerFacing*() ones.
 *
 * Read-only. Never mutates an analysis, a simulation result, or any
 * commercial/billing record.
 *
 * Scale note (deliberately not engineered away — see CLAUDE.md's "design
 * for the current phase" guidance): detail()/exportRows() fetch full
 * Eloquent collections per workflow rather than a single DB-level UNION
 * paginator, then merge/sort/paginate in memory. This keeps the query
 * layer simple and lets every row go through the same internal presenter
 * used elsewhere. Correct and fast at today's real analysis volumes; a
 * future phase should revisit this (e.g. a materialized reporting table)
 * if/when per-organisation analysis counts grow into the thousands.
 *
 * Phase G4C.2G — Metric Semantic Layers. Every figure this service
 * produces belongs to exactly one of four layers; mixing them (counting
 * the same input at two layers, or a layer's identity leaking a wrong
 * unit) is the class of bug this phase fixed (see below) and must never
 * be reintroduced:
 *
 *  - DOCUMENT metric: one observation per unique source document, keyed
 *    by (analysable model class, document_hash) — deliberately equivalent
 *    to "workflow + document_hash" (each model class maps 1:1 to exactly
 *    one AiWorkflow constant), NEVER by analysable id. A provider-backed
 *    execution and any cache-hit reuse of the exact same document
 *    collapse to ONE document observation. See uniqueCalibrationDocuments().
 *    Used for: normalized input-size distribution/percentiles,
 *    hypothetical-credit distribution (a candidate policy prices a
 *    document, not a request).
 *  - EXECUTION metric: one observation per analysis row, regardless of
 *    whether it shares a document with another row. Used for: total
 *    volume, status breakdown, cache-hit rate, failure categories,
 *    average duration, telemetry-completeness health checks. A cache hit
 *    IS a real execution (a real request happened) — it just isn't a new
 *    document or a new provider-cost sample.
 *  - PROVIDER metric: one observation per execution where
 *    `provider_called === true` only. A cache hit contributes $0 in
 *    total spend (mathematically inert — adding zero never changes a
 *    sum) but MUST NOT be included in an average/rate computed over
 *    provider cost, or it dilutes the true per-call cost downward. Used
 *    for: total/average provider spend, cost-per-token figures.
 *  - CUSTOMER metric: one observation per distinct real Organization.
 *    Used for: organisation diversity/count. Never satisfied by
 *    duplicating or renaming an internal test organisation — see
 *    `internal-docs/commercial/ai-credit-policy-and-consumption-model-v1.md`
 *    §55.
 *
 * The bug this phase fixed: `normalizedInputSizes()`/the pre-G4C.2G
 * `simulationSummary()` sampled one value per EXECUTION rather than per
 * DOCUMENT. A single document analysed once for real and once more via
 * cache-hit reuse was counted as two data points, silently weighting the
 * percentile/distribution calculations toward whichever document
 * happened to have the most retries/reuses — completely independent of
 * how many genuinely different documents exist. Fixed by introducing
 * uniqueCalibrationDocuments() as the one place document identity is
 * resolved, and routing every document-layer metric through it.
 *
 * See the class-level metric classification table maintained in
 * `internal-docs/commercial/ai-credit-policy-and-consumption-model-v1.md`
 * §58 for the full per-field table (classification, cache-hit
 * contribution, rationale) — kept there rather than duplicated field-by-
 * field in this docblock, since that document is also where the
 * commercial reasoning for each choice lives.
 */
class AiTelemetryReportingService
{
    private const MAX_ROWS = 5000;

    /**
     * @param array{organization_id?: int, workflow?: string, date_from?: string, date_to?: string} $filters
     */
    public function summary(array $filters): array
    {
        ['contract' => $contract, 'trade_package' => $tradePackage, 'all' => $all] = $this->fetchAnalyses($filters);

        $totalCost = $all->whereNotNull('estimated_cost')->sum('estimated_cost');
        $missingCostCount = $all->whereNull('estimated_cost')->count();

        $calibrationEligible = $all->whereIn('status', ['completed', 'confirmed']);
        $providerCalledKnown = $all->whereNotNull('provider_called');
        // PROVIDER metric basis — only executions where the provider was
        // genuinely called contribute to cost averages/rates. A cache hit's
        // real $0 cost still contributes to totals below (adding zero is
        // inert to a sum) but must never dilute an *average* real-call cost.
        $providerBacked = $calibrationEligible->where('provider_called', true);

        // DOCUMENT metric basis — one canonical analysis per unique source
        // document per workflow (see uniqueCalibrationDocuments()). Computed
        // once here and reused for every document-layer figure below so a
        // cache-hit reuse of the same document is never counted twice.
        $contractDocuments = $this->uniqueCalibrationDocuments($contract);
        $tradePackageDocuments = $this->uniqueCalibrationDocuments($tradePackage);
        $uniqueDocuments = $contractDocuments->concat($tradePackageDocuments);

        $normalizedSizes = $this->normalizedInputSizes($uniqueDocuments);
        $byWorkflowCount = $all->groupBy('workflow')->map->count();

        return [
            'total_analyses'  => $all->count(), // EXECUTION metric
            'by_status'       => $all->groupBy('status')->map->count(), // EXECUTION metric
            'provider_called' => [ // EXECUTION metric
                'true'  => $all->where('provider_called', true)->count(),
                'false' => $all->where('provider_called', false)->count(),
                'null'  => $all->whereNull('provider_called')->count(),
            ],
            'by_failure_category' => $all->whereNotNull('failure_category')->groupBy('failure_category')->map->count(), // EXECUTION metric
            'total_estimated_cost' => round($totalCost, 6), // PROVIDER metric (total; cache-hit $0 rows are inert)
            'analyses_missing_cost' => $missingCostCount, // EXECUTION metric (coarse; see telemetryHealth()['missing_provider_cost'] for the terminal/non-failed-only version)
            'by_workflow' => [
                AiWorkflow::CONTRACT_ANALYSIS => $this->workflowBreakdown($contract, $contractDocuments),
                AiWorkflow::TRADE_PACKAGE_ANALYSIS => $this->workflowBreakdown($tradePackage, $tradePackageDocuments),
            ],
            'simulation' => $this->simulationSummary($uniqueDocuments), // DOCUMENT metric (one execution's simulation rows per unique document)

            // Phase G4C.2D — Commercial Calibration Dashboard additions.
            // Every figure below is derived strictly from telemetry already
            // collected; none is fabricated when the underlying data is
            // absent (see the null handling in each helper).
            'calibration' => [
                'completed_executions' => $calibrationEligible->count(), // EXECUTION metric
                'unique_documents' => $uniqueDocuments->count(), // DOCUMENT metric
                'failed_executions' => $all->where('status', 'failed')->count(), // EXECUTION metric
                'excluded_from_calibration' => $all->count() - $calibrationEligible->count(), // EXECUTION metric
                'cache_hit_rate' => $providerCalledKnown->count() > 0 // EXECUTION metric
                    ? round($providerCalledKnown->where('provider_called', false)->count() / $providerCalledKnown->count(), 4)
                    : null,
                'average_provider_cost' => $providerBacked->whereNotNull('estimated_cost')->count() > 0 // PROVIDER metric — provider-backed only, never diluted by cache-hit $0 rows
                    ? round((float) $providerBacked->whereNotNull('estimated_cost')->avg('estimated_cost'), 6)
                    : null,
                'total_provider_spend' => round($totalCost, 6), // PROVIDER metric (total)
                'average_execution_duration_ms' => $all->whereNotNull('duration_ms')->count() > 0 // EXECUTION metric
                    ? round((float) $all->whereNotNull('duration_ms')->avg('duration_ms'))
                    : null,
                'most_used_workflow' => $byWorkflowCount->isNotEmpty() ? $byWorkflowCount->sortDesc()->keys()->first() : null, // EXECUTION metric
                'organizations_using_ai' => $all->pluck('organization_id')->unique()->count(), // CUSTOMER metric — real distinct organisation IDs only, never inflated
                'normalized_input_size' => $this->percentileSummary($normalizedSizes), // DOCUMENT metric
            ],
        ];
    }

    /**
     * One canonical calibration-eligible (completed/confirmed) analysis per
     * unique source document — the document-identity key is the analysable
     * model's class (a 1:1 proxy for "workflow", since each model class maps
     * to exactly one AiWorkflow constant) plus `document_hash`. This is the
     * DOCUMENT metric layer's single source of identity — every document-
     * layer figure in this service must be built from this method's output,
     * never from a raw per-execution collection.
     *
     * A provider-backed execution and any cache-hit reuse of the exact same
     * document collapse to ONE entry — the provider-backed one is preferred
     * as canonical when both exist (it carries real token/cost telemetry);
     * otherwise the most recent. Rows with a null `document_hash` (legacy,
     * or a terminal state reached before hashing occurred) are excluded
     * explicitly — never treated as a distinct "unknown document" and never
     * silently merged with a real document.
     *
     * Deliberately operates on a single already-filtered, single-model-class
     * collection (the caller passes $contract and $tradePackage separately,
     * never a combined collection) — this is what guarantees a Contract
     * Analysis and a Trade Package Analysis can never collide into the same
     * "document" even in the extreme case of an identical hash.
     *
     * @param Collection<int, \App\Models\ContractAiAnalysis|\App\Models\TradePackageAiAnalysis> $analyses
     * @return Collection<int, \App\Models\ContractAiAnalysis|\App\Models\TradePackageAiAnalysis>
     */
    private function uniqueCalibrationDocuments(Collection $analyses): Collection
    {
        return $analyses
            ->whereIn('status', ['completed', 'confirmed'])
            ->whereNotNull('document_hash')
            ->groupBy(fn ($a) => $a->document_hash)
            ->map(fn (Collection $group) => $group
                ->sortByDesc(fn ($a) => $a->provider_called ? 1 : 0)
                ->first())
            ->values();
    }

    /**
     * Normalized input character count per unique document (see
     * uniqueCalibrationDocuments()) — DOCUMENT metric layer. The normalized
     * count itself is still stored per-simulation-row (AiCreditSimulator
     * writes it once per candidate for the same analysis), so this still
     * queries AiCreditSimulationResult, but only for the canonical analysis
     * id of each unique document — never for every execution that shares a
     * document. Null values (unavailable input) are excluded, not treated
     * as zero.
     *
     * @param Collection<int, \App\Models\ContractAiAnalysis|\App\Models\TradePackageAiAnalysis> $uniqueDocuments
     */
    private function normalizedInputSizes(Collection $uniqueDocuments): array
    {
        if ($uniqueDocuments->isEmpty()) {
            return [];
        }

        $keys = $uniqueDocuments->map(fn ($a) => $a::class . ':' . $a->id)->all();

        return AiCreditSimulationResult::query()
            ->whereNotNull('normalized_input_char_count')
            ->get(['analysable_type', 'analysable_id', 'normalized_input_char_count'])
            ->filter(fn ($r) => in_array($r->analysable_type . ':' . $r->analysable_id, $keys, true))
            ->unique(fn ($r) => $r->analysable_type . ':' . $r->analysable_id)
            ->pluck('normalized_input_char_count')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param array<int, int> $sortedValues Ascending-sorted values.
     */
    private function percentileSummary(array $sortedValues): array
    {
        $count = count($sortedValues);

        if ($count === 0) {
            return ['sample_size' => 0, 'average' => null, 'p50' => null, 'p90' => null, 'p99' => null];
        }

        return [
            'sample_size' => $count,
            'average' => round(array_sum($sortedValues) / $count),
            'p50' => $this->percentile($sortedValues, 0.50),
            'p90' => $this->percentile($sortedValues, 0.90),
            'p99' => $this->percentile($sortedValues, 0.99),
        ];
    }

    /**
     * Nearest-rank percentile — simplest correct method for a small,
     * discrete sample; no interpolation, since an interpolated "2.4th
     * document" isn't a meaningful figure here.
     */
    private function percentile(array $sortedValues, float $fraction): int
    {
        $index = (int) ceil($fraction * count($sortedValues)) - 1;
        $index = max(0, min(count($sortedValues) - 1, $index));

        return $sortedValues[$index];
    }

    /**
     * Phase G4C.2E holistic-review fix — summary() and telemetryHealth()
     * previously duplicated this exact fetch-and-concat block. Extracted
     * here so both read from one definition. Does NOT eliminate the fact
     * that a single `ai:credits:calibration-report` run still executes
     * this query twice (once per method call) — fully de-duplicating that
     * would mean changing both public methods' signatures to accept a
     * pre-fetched collection, which would ripple into their existing
     * callers/tests for a query-count optimisation this service's own
     * "Scale note" already treats as acceptable at today's volumes. Revisit
     * only if real usage volume makes the double query measurably costly.
     *
     * @param array{organization_id?: int, workflow?: string, date_from?: string, date_to?: string} $filters
     * @return array{contract: Collection, trade_package: Collection, all: Collection}
     */
    private function fetchAnalyses(array $filters): array
    {
        $workflowFilter = $filters['workflow'] ?? null;

        $contract = ($workflowFilter === null || $workflowFilter === AiWorkflow::CONTRACT_ANALYSIS)
            ? $this->filteredQuery(ContractAiAnalysis::query(), $filters)->get()
            : collect();

        $tradePackage = ($workflowFilter === null || $workflowFilter === AiWorkflow::TRADE_PACKAGE_ANALYSIS)
            ? $this->filteredQuery(TradePackageAiAnalysis::query(), $filters)->get()
            : collect();

        return ['contract' => $contract, 'trade_package' => $tradePackage, 'all' => $contract->concat($tradePackage)];
    }

    /**
     * @param Collection $rows all executions for this workflow (EXECUTION metric basis)
     * @param Collection $uniqueDocuments this workflow's unique documents, from uniqueCalibrationDocuments() (DOCUMENT metric basis)
     */
    private function workflowBreakdown(Collection $rows, Collection $uniqueDocuments): array
    {
        return [
            'count'     => $rows->count(), // EXECUTION metric
            'completed' => $rows->whereIn('status', ['completed', 'confirmed'])->count(), // EXECUTION metric
            'unique_documents' => $uniqueDocuments->count(), // DOCUMENT metric
            'failed'    => $rows->where('status', 'failed')->count(), // EXECUTION metric
            'total_estimated_cost' => round($rows->whereNotNull('estimated_cost')->sum('estimated_cost'), 6), // PROVIDER metric (total)
        ];
    }

    /**
     * Per-candidate-policy simulation summary — non-enforcing, informational
     * only. NEVER labelled as an approved rate anywhere in this output; see
     * internal-docs/commercial/ai-credit-policy-and-consumption-model-v1.md.
     *
     * DOCUMENT metric layer: only the canonical analysis id's own simulation
     * rows (one per unique document — see uniqueCalibrationDocuments()) are
     * included, so a document analysed once for real and once more via
     * cache-hit reuse contributes exactly one "calculated"/hypothetical-
     * credits observation, never two. $filters is no longer accepted
     * directly here — filtering already happened once, upstream, when
     * $uniqueDocuments was built from the already-filtered analyses in
     * summary(), so re-filtering this query independently would risk the
     * two filters silently diverging.
     *
     * @param Collection<int, \App\Models\ContractAiAnalysis|\App\Models\TradePackageAiAnalysis> $uniqueDocuments
     */
    private function simulationSummary(Collection $uniqueDocuments): array
    {
        if ($uniqueDocuments->isEmpty()) {
            return [];
        }

        $rows = $uniqueDocuments
            ->groupBy(fn ($a) => $a::class)
            ->flatMap(fn (Collection $docs, string $class) => AiCreditSimulationResult::query()
                ->where('analysable_type', $class)
                ->whereIn('analysable_id', $docs->pluck('id'))
                ->get());

        return $rows
            ->groupBy(fn ($r) => $r->workflow . '::' . $r->candidate_policy_key)
            ->map(function (Collection $group) {
                $calculated = $group->where('simulation_status', 'calculated');

                // Distinct calendar months actually represented in this
                // candidate's calculated rows — used only to turn a total
                // into a rate; a candidate with data from a single month
                // has no meaningful "average month" yet (reported null,
                // never divided by 1 and presented as if it were a trend).
                $monthsRepresented = $calculated->map(fn ($r) => $r->calculated_at?->format('Y-m'))->filter()->unique()->count();

                return [
                    'workflow'              => $group->first()->workflow,
                    'candidate_policy_key'  => $group->first()->candidate_policy_key,
                    'charging_strategy'     => $group->first()->charging_strategy,
                    'calculated'            => $calculated->count(),
                    'unresolved'            => $group->where('simulation_status', 'unresolved')->count(),
                    'unavailable'           => $group->where('simulation_status', 'unavailable')->count(),
                    'error'                 => $group->where('simulation_status', 'error')->count(),
                    'total_hypothetical_credits' => round((float) $calculated->sum('hypothetical_credits'), 2),
                    'average_hypothetical_credits' => $calculated->count() > 0
                        ? round((float) $calculated->avg('hypothetical_credits'), 2)
                        : null,
                    'average_monthly_hypothetical_credits' => $monthsRepresented > 1
                        ? round((float) $calculated->sum('hypothetical_credits') / $monthsRepresented, 2)
                        : null,
                    'months_represented' => $monthsRepresented,
                    'organizations_represented' => $group->pluck('organization_id')->unique()->count(),
                    // Phase G4C.3G — reads the ONE recorded founder-approval
                    // marker (config('ai_credit_shadow.approved_candidate'),
                    // null until a real approval happens). Never true by any
                    // other means — no enforcement/billing decision anywhere
                    // reads this flag; it exists purely so this report can
                    // truthfully reflect an approval that has actually
                    // occurred instead of always asserting false.
                    'is_approved_policy' => $group->first()->candidate_policy_key === config('ai_credit_shadow.approved_candidate'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Phase G4C.2D — telemetry maturity: the smallest useful set of checks
     * that improve calibration confidence, reusing this service's own
     * existing filtered queries rather than a new monitoring subsystem.
     * Read-only; never repairs anything (unlike, e.g.,
     * billing:subscriptions:check-integrity --repair, there is no
     * "recoverable" telemetry case here — a missing execution telemetry
     * field can never be safely reconstructed after the fact).
     *
     * @param array{organization_id?: int, workflow?: string, date_from?: string, date_to?: string} $filters
     */
    public function telemetryHealth(array $filters): array
    {
        ['all' => $all] = $this->fetchAnalyses($filters);

        $terminal = $all->whereIn('status', ['completed', 'confirmed', 'failed']);
        $calibrationEligible = $all->whereIn('status', ['completed', 'confirmed']);

        $legacyRecords = $all->whereNull('telemetry_schema_version')->count();

        // A terminal (non-cancelled) row that never recorded whether the
        // provider was actually called is incomplete — that decision point
        // is always reached before a completed/failed transition in both
        // AnalyseContractWithAiJob/AnalyseTradePackageWithAiJob.
        $incompleteTelemetry = $terminal->whereNull('provider_called')->count();

        $impossibleValues = $all
            ->filter(fn ($a) => $a->started_at !== null && $a->completed_at !== null && $a->completed_at->lt($a->started_at))
            ->count();

        $missingSimulation = $this->calibrationEligibleWithoutSimulation($calibrationEligible);

        $duplicatedSimulations = $this->duplicatedSimulationKeyCount($filters);
        $simulationErrors = $this->simulationStatusCount($filters, 'error');

        return [
            'legacy_records' => $legacyRecords,
            'incomplete_telemetry' => $incompleteTelemetry,
            'missing_provider_cost' => $terminal->whereNull('estimated_cost')->where('status', '!=', 'failed')->count(),
            'missing_normalized_input_or_simulation' => $missingSimulation,
            'impossible_values' => $impossibleValues,
            'duplicated_simulations' => $duplicatedSimulations,
            // Phase G4C.2E, Part 4 (Monitoring & Alerting Review) — a
            // candidate policy that threw during simulation (caught and
            // logged by AiCreditSimulator, never propagated to the
            // customer's analysis) is exactly the kind of "simulation
            // failure" that review asked this existing health surface to
            // report, rather than building a new alerting mechanism.
            'simulation_errors' => $simulationErrors,
            'calibration_eligible_total' => $calibrationEligible->count(),
        ];
    }

    private function simulationStatusCount(array $filters, string $status): int
    {
        $query = AiCreditSimulationResult::query()->where('simulation_status', $status);

        if (!empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }
        if (!empty($filters['workflow'])) {
            $query->where('workflow', $filters['workflow']);
        }

        return $query->count();
    }

    /**
     * Completed/confirmed analyses (both real workflows) with zero
     * AiCreditSimulationResult rows at all — either the prospective
     * simulation call never ran (a bug, since it's wired into both jobs'
     * post-completion path) or the analysis predates simulation existing
     * and has not yet been backfilled (ai:credits:backfill-simulations).
     */
    private function calibrationEligibleWithoutSimulation(Collection $calibrationEligible): int
    {
        if ($calibrationEligible->isEmpty()) {
            return 0;
        }

        $simulatedKeys = AiCreditSimulationResult::query()
            ->select(['analysable_type', 'analysable_id'])
            ->get()
            ->map(fn ($r) => $r->analysable_type . ':' . $r->analysable_id)
            ->flip();

        return $calibrationEligible
            ->reject(fn ($a) => $simulatedKeys->has($a::class . ':' . $a->id))
            ->count();
    }

    /**
     * Sanity check only — the DB unique constraint on
     * ai_credit_sim_results_idempotency_key should make this structurally
     * impossible. A non-zero count here means the constraint was bypassed
     * (e.g. a raw insert outside AiCreditSimulator) and needs investigation,
     * not that this service can repair it.
     */
    private function duplicatedSimulationKeyCount(array $filters): int
    {
        $query = AiCreditSimulationResult::query()
            ->select(['analysable_type', 'analysable_id', 'candidate_policy_key', 'candidate_policy_version', 'normalization_version'])
            ->selectRaw('COUNT(*) as row_count')
            ->groupBy(['analysable_type', 'analysable_id', 'candidate_policy_key', 'candidate_policy_version', 'normalization_version'])
            ->having('row_count', '>', 1);

        if (!empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }
        if (!empty($filters['workflow'])) {
            $query->where('workflow', $filters['workflow']);
        }

        return $query->get()->count();
    }

    /**
     * @param array{organization_id?: int, workflow?: string, status?: string, provider_called?: bool, failure_category?: string, date_from?: string, date_to?: string} $filters
     */
    public function detail(array $filters, int $perPage, int $page): LengthAwarePaginatorContract
    {
        $merged = $this->mergedRows($filters);

        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $items = $merged->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator($items, $merged->count(), $perPage, $page);
    }

    /**
     * Every matching row, unpaginated — used by the CSV export. Capped at
     * MAX_ROWS as an explicit, logged safety net, never a silent truncation
     * (see CLAUDE.md's "no silent caps" workflow guidance applied here too).
     */
    public function exportRows(array $filters): Collection
    {
        return $this->mergedRows($filters)->take(self::MAX_ROWS);
    }

    private function mergedRows(array $filters): Collection
    {
        $workflowFilter = $filters['workflow'] ?? null;

        $contractRows = ($workflowFilter === null || $workflowFilter === AiWorkflow::CONTRACT_ANALYSIS)
            ? $this->filteredQuery(ContractAiAnalysis::query()->with(['organization:id,name', 'creator', 'contract']), $filters)->get()
            : collect();

        $tradePackageRows = ($workflowFilter === null || $workflowFilter === AiWorkflow::TRADE_PACKAGE_ANALYSIS)
            ? $this->filteredQuery(TradePackageAiAnalysis::query()->with(['organization:id,name', 'creator', 'tradePackage']), $filters)->get()
            : collect();

        $orgNames = Organization::query()->pluck('name', 'id');

        $contractShaped = $contractRows->map(fn ($a) => array_merge(
            AiAnalysisPresenter::internalContractAnalysis($a),
            [
                'organization_name' => $orgNames->get($a->organization_id),
                'simulations' => $this->simulationsFor($a),
            ]
        ));

        $tradePackageShaped = $tradePackageRows->map(fn ($a) => array_merge(
            AiAnalysisPresenter::internalTradePackageAnalysis($a),
            [
                'organization_name' => $orgNames->get($a->organization_id),
                'simulations' => $this->simulationsFor($a),
            ]
        ));

        return $contractShaped->concat($tradePackageShaped)
            ->sortByDesc(fn ($row) => $row['completed_at'] ?? $row['created_at'])
            ->values();
    }

    private function simulationsFor($analysis): array
    {
        return AiCreditSimulationResult::query()
            ->where('analysable_type', $analysis::class)
            ->where('analysable_id', $analysis->id)
            ->get()
            ->map(fn ($r) => [
                'candidate_policy_key' => $r->candidate_policy_key,
                'charging_strategy'    => $r->charging_strategy,
                'hypothetical_band'    => $r->hypothetical_band,
                'hypothetical_credits' => $r->hypothetical_credits,
                'simulation_status'    => $r->simulation_status,
            ])
            ->all();
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param Builder<TModel> $query
     */
    private function filteredQuery(Builder $query, array $filters): Builder
    {
        if (!empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (array_key_exists('provider_called', $filters) && $filters['provider_called'] !== null) {
            $query->where('provider_called', $filters['provider_called']);
        }
        if (!empty($filters['failure_category'])) {
            $query->where('failure_category', $filters['failure_category']);
        }
        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->latest()->limit(self::MAX_ROWS);
    }
}
