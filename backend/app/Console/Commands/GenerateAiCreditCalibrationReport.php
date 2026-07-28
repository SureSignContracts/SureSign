<?php

namespace App\Console\Commands;

use App\Services\Monitoring\AiTelemetryReportingService;
use App\Support\AI\AiCreditReadinessGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Phase G4C.2D, Workstream 3 (Commercial Approval Pack) — extended by
 * Phase G4C.2E, Parts 3 & 5 (Founder Approval Package + G4C.3 Readiness
 * Gate). Deliberately kept as ONE artifact rather than split into separate
 * reports/commands: the Approval Package summarises the same operational
 * evidence for management review that the Readiness Gate evaluates for
 * sufficiency — two perspectives on one dataset, not two datasets.
 *
 * Manual/on-demand only (same convention as
 * ai:credits:backfill-simulations / billing:subscriptions:check-integrity)
 * — generates a markdown report from EXISTING telemetry/simulation data via
 * AiTelemetryReportingService, for internal management review. Never
 * queries anything this service doesn't already expose, never calls the AI
 * provider, never mutates any record.
 *
 * Hard constraint (see internal-docs/commercial/ai-credit-policy-and-
 * consumption-model-v1.md and CLAUDE.md's AI Workflow Context): this report
 * NEVER recommends, implies, or approves a commercial AI Credit rate. It
 * separates "Observed Facts" (drawn directly from telemetry) from a fixed,
 * unconditional "Commercial Recommendations" section stating that none is
 * given — that statement does not change based on how much data exists,
 * because approving a rate is exclusively a founder/business decision this
 * command has no authority to make regardless of sample size. The
 * G4C.3 Readiness Gate (App\Support\AI\AiCreditReadinessGate) is a
 * structured Ready/Not Ready/Blocked/Unknown evaluation, not a
 * recommendation either — see that class's own docblock.
 */
class GenerateAiCreditCalibrationReport extends Command
{
    protected $signature = 'ai:credits:calibration-report
        {--workflow= : Limit to one workflow (contract_analysis|trade_package_analysis)}
        {--organization-id= : Limit to one organisation}
        {--date-from= : Only include analyses created on/after this date}
        {--date-to= : Only include analyses created on/before this date}
        {--output= : Output file path relative to the local (private) disk; default is timestamped under internal-reports/ai-credits/}';

    protected $description = 'Generate the internal AI Credit Commercial Approval Pack (markdown, observed facts only — no rate recommendation) from existing telemetry and simulation data';

    public function handle(AiTelemetryReportingService $service): int
    {
        $filters = [
            'workflow'        => $this->option('workflow'),
            'organization_id' => $this->option('organization-id') ? (int) $this->option('organization-id') : null,
            'date_from'       => $this->option('date-from'),
            'date_to'         => $this->option('date-to'),
        ];

        $summary = $service->summary($filters);
        $health = $service->telemetryHealth($filters);
        $readiness = AiCreditReadinessGate::evaluate($summary, $health, config('ai_credit_readiness', []));

        $markdown = $this->render($filters, $summary, $health, $readiness);

        $path = $this->option('output') ?: 'internal-reports/ai-credits/calibration-report-' . now()->format('Y-m-d_His') . '.md';

        Storage::disk('local')->put($path, $markdown);

        $this->info("AI Credit Calibration Report written to storage/app/private/{$path}");

        return self::SUCCESS;
    }

    private function render(array $filters, array $summary, array $health, array $readiness): string
    {
        $calibration = $summary['calibration'];
        $normalized = $calibration['normalized_input_size'];
        // Reuse the readiness gate's own derived signals rather than
        // recomputing them independently from $summary — see the AI Credit
        // Policy document's Part Four §53 holistic-review note.
        $workflowsWithData = $readiness['signals']['workflows_with_data'];
        $sizeSpreadPresent = $readiness['signals']['size_spread_present'];
        $tradePackageCompleted = $readiness['signals']['trade_package_completed'];

        $lines = [];
        $lines[] = '# AI Credit Commercial Calibration Report & Founder Approval Package';
        $lines[] = '';
        $lines[] = '**Generated:** ' . now()->toDateTimeString() . ' (UTC)';
        $lines[] = '**Observation window:** ' . ($filters['date_from'] ?: 'all history') . ' to ' . ($filters['date_to'] ?: 'now');
        $lines[] = '**Filters applied:** ' . $this->describeFilters($filters);
        $lines[] = '';
        $lines[] = '> Non-enforcing, internal-only. No AI Credit balance, deduction, entitlement, or';
        $lines[] = '> approved commercial rate exists anywhere referenced by this report — see';
        $lines[] = '> `internal-docs/commercial/ai-credit-policy-and-consumption-model-v1.md`.';
        $lines[] = '';

        $lines[] = '## 1. Observed Facts';
        $lines[] = '';
        $lines[] = '### Sample & Participation';
        $lines[] = '';
        $lines[] = '| Metric | Value |';
        $lines[] = '|---|---|';
        $lines[] = '| Total executions in window | ' . $summary['total_analyses'] . ' |';
        $lines[] = '| Calibration-eligible (completed/confirmed) | ' . $calibration['completed_executions'] . ' |';
        $lines[] = '| Unique documents (execution-level duplicates collapsed) | ' . $calibration['unique_documents'] . ' |';
        $lines[] = '| Failed executions | ' . $calibration['failed_executions'] . ' |';
        $lines[] = '| Excluded from calibration (pending/processing/failed/cancelled) | ' . $calibration['excluded_from_calibration'] . ' |';
        $lines[] = '| Organisations represented | ' . $calibration['organizations_using_ai'] . ' |';
        $lines[] = '| Most-used workflow | ' . ($calibration['most_used_workflow'] ?? 'n/a') . ' |';
        $lines[] = '';

        $lines[] = '### Workflow Distribution';
        $lines[] = '';
        $lines[] = '| Workflow | Count | Completed | Unique Documents | Failed | Total Est. Cost |';
        $lines[] = '|---|---|---|---|---|---|';
        foreach ($summary['by_workflow'] as $workflow => $breakdown) {
            $lines[] = "| {$workflow} | {$breakdown['count']} | {$breakdown['completed']} | {$breakdown['unique_documents']} | {$breakdown['failed']} | \${$breakdown['total_estimated_cost']} |";
        }
        $lines[] = '';

        $lines[] = '### Normalized Input Size Distribution';
        $lines[] = '';
        $lines[] = '| Statistic | Value (normalized chars) |';
        $lines[] = '|---|---|';
        $lines[] = '| Sample size | ' . $normalized['sample_size'] . ' |';
        $lines[] = '| Average | ' . ($normalized['average'] ?? 'n/a') . ' |';
        $lines[] = '| P50 | ' . ($normalized['p50'] ?? 'n/a') . ' |';
        $lines[] = '| P90 | ' . ($normalized['p90'] ?? 'n/a') . ' |';
        $lines[] = '| P99 | ' . ($normalized['p99'] ?? 'n/a') . ' |';
        $lines[] = '';

        $lines[] = '### Provider Spend';
        $lines[] = '';
        $lines[] = '| Metric | Value |';
        $lines[] = '|---|---|';
        $lines[] = '| Total provider spend | $' . $calibration['total_provider_spend'] . ' |';
        $lines[] = '| Average provider cost per execution | ' . ($calibration['average_provider_cost'] !== null ? '$' . $calibration['average_provider_cost'] : 'n/a') . ' |';
        $lines[] = '| Executions missing cost data | ' . $summary['analyses_missing_cost'] . ' |';
        $lines[] = '| Cache hit rate | ' . ($calibration['cache_hit_rate'] !== null ? round($calibration['cache_hit_rate'] * 100, 1) . '%' : 'n/a') . ' |';
        $lines[] = '| Average execution duration | ' . ($calibration['average_execution_duration_ms'] !== null ? $calibration['average_execution_duration_ms'] . 'ms' : 'n/a') . ' |';
        $lines[] = '';

        $lines[] = '### Hypothetical Candidate Policy Comparison';
        $lines[] = '';
        $lines[] = 'A candidate marked "Approved" below has been approved as the internal accounting';
        $lines[] = 'model (see `config(\'ai_credit_shadow.approved_candidate\')`) — this is NOT the same';
        $lines[] = 'as an approved customer-facing commercial rate/price, which remains a separate,';
        $lines[] = 'not-yet-made decision.';
        $lines[] = '';
        $lines[] = '| Workflow | Candidate | Strategy | Approved (internal) | Calculated | Unresolved | Unavailable | Avg Credits | Avg Monthly Credits | Orgs | Months |';
        $lines[] = '|---|---|---|---|---|---|---|---|---|---|---|';
        foreach ($summary['simulation'] as $candidate) {
            $lines[] = "| {$candidate['workflow']} | {$candidate['candidate_policy_key']} | {$candidate['charging_strategy']} | "
                . ($candidate['is_approved_policy'] ? 'Yes' : 'No') . ' | '
                . "{$candidate['calculated']} | {$candidate['unresolved']} | {$candidate['unavailable']} | "
                . ($candidate['average_hypothetical_credits'] ?? 'n/a') . ' | '
                . ($candidate['average_monthly_hypothetical_credits'] ?? 'n/a') . ' | '
                . "{$candidate['organizations_represented']} | {$candidate['months_represented']} |";
        }
        if (empty($summary['simulation'])) {
            $lines[] = '| _no simulation data in this window_ | | | | | | | | | | |';
        }
        $lines[] = '';

        $lines[] = '### Telemetry Quality';
        $lines[] = '';
        $lines[] = '| Check | Count |';
        $lines[] = '|---|---|';
        $lines[] = '| Legacy records (pre-versioning) | ' . $health['legacy_records'] . ' |';
        $lines[] = '| Incomplete telemetry | ' . $health['incomplete_telemetry'] . ' |';
        $lines[] = '| Missing provider cost | ' . $health['missing_provider_cost'] . ' |';
        $lines[] = '| Missing normalized input / simulation | ' . $health['missing_normalized_input_or_simulation'] . ' |';
        $lines[] = '| Impossible values | ' . $health['impossible_values'] . ' |';
        $lines[] = '| Duplicated simulations | ' . $health['duplicated_simulations'] . ' |';
        $lines[] = '';

        $lines[] = '### Simulation Coverage';
        $lines[] = '';
        $coveragePercent = $health['calibration_eligible_total'] > 0
            ? round((($health['calibration_eligible_total'] - $health['missing_normalized_input_or_simulation']) / $health['calibration_eligible_total']) * 100, 1)
            : null;
        $lines[] = '| Metric | Value |';
        $lines[] = '|---|---|';
        $lines[] = '| Calibration-eligible executions | ' . $health['calibration_eligible_total'] . ' |';
        $lines[] = '| Missing a simulation result | ' . $health['missing_normalized_input_or_simulation'] . ' |';
        $lines[] = '| Coverage | ' . ($coveragePercent !== null ? "{$coveragePercent}%" : 'n/a') . ' |';
        $lines[] = '';

        // Phase G4C.2D's original "Calibration Readiness Checklist" (4 items)
        // is deliberately NOT reproduced here — it is superseded by the
        // ten-requirement G4C.3 Readiness Gate below, which subsumes every
        // condition it checked plus six more. Keeping both would duplicate
        // the same underlying facts under two different checklists.

        $lines[] = '## 2. Founder Approval Package';
        $lines[] = '';

        $lines[] = '### Commercial Risks';
        $lines[] = '';
        $risks = [];
        if ($calibration['organizations_using_ai'] <= 1) {
            $risks[] = 'Single-organisation (or zero) sample — cross-organisation cost/usage variance is unknown.';
        }
        if ($tradePackageCompleted === 0) {
            $risks[] = 'No completed Trade Package Analysis execution — its cost/size profile is entirely unvalidated.';
        }
        if (!$sizeSpreadPresent) {
            $risks[] = 'No genuine document-size spread observed — a banded policy cannot yet be distinguished from a flat one.';
        }
        if ($health['incomplete_telemetry'] + $health['missing_provider_cost'] + $health['impossible_values'] + $health['duplicated_simulations'] + $health['simulation_errors'] > 0) {
            $risks[] = 'Outstanding telemetry quality findings exist (see Telemetry Quality above) — resolve before treating this sample as reliable.';
        }
        if ($calibration['completed_executions'] > 0 && $calibration['completed_executions'] < 10) {
            $risks[] = 'Sample size is very small (' . $calibration['completed_executions'] . ') — early conclusions may not generalise.';
        }
        foreach ($risks === [] ? ['No commercial risks identified against the checks this report runs.'] : $risks as $risk) {
            $lines[] = '- ' . $risk;
        }
        $lines[] = '';

        $lines[] = '### Recommended Next Steps';
        $lines[] = '';
        $steps = [];
        if ($health['missing_normalized_input_or_simulation'] > 0) {
            $steps[] = 'Run `ai:credits:backfill-simulations` for calibration-eligible executions still missing a simulation result, where their source documents are reconstructable.';
        }
        if ($tradePackageCompleted === 0) {
            $steps[] = 'Obtain at least one completed Trade Package Analysis execution.';
        }
        if ($calibration['organizations_using_ai'] <= 1) {
            $steps[] = 'Continue collecting executions across more organisations.';
        }
        if (!$sizeSpreadPresent) {
            $steps[] = 'Continue the production observation period until a genuine document-size spread is observed (see the Production Observation Runbook).';
        }
        $steps[] = 'Do not approve a commercial rate until the G4C.3 Readiness Gate below reads Ready for every requirement.';
        foreach ($steps as $step) {
            $lines[] = '- ' . $step;
        }
        $lines[] = '';

        $lines[] = '### Unknowns';
        $lines[] = '';
        $unknowns = [];
        if ($health['missing_normalized_input_or_simulation'] > 0) {
            $unknowns[] = $health['missing_normalized_input_or_simulation'] . ' calibration-eligible execution(s) have no simulation result yet.';
        }
        if (!$sizeSpreadPresent) {
            $unknowns[] = 'No genuine size spread observed yet — a single (or near-identical) document sample cannot validate a banded policy against a flat one.';
        }
        if ($calibration['organizations_using_ai'] <= 1) {
            $unknowns[] = 'Only one (or zero) organisations represented — cross-organisation variance is unknown.';
        }
        if ($workflowsWithData <= 1) {
            $unknowns[] = 'One of the two real provider-backed workflows has no completed executions in this window.';
        }
        foreach ($unknowns === [] ? ['None identified against the checks this report runs — this is not the same as "no unknowns remain," only that these specific checks found nothing.'] : $unknowns as $unknown) {
            $lines[] = '- ' . $unknown;
        }
        $lines[] = '';

        $lines[] = '### Commercial Recommendations';
        $lines[] = '';
        $lines[] = 'This report makes **no commercial rate recommendation** and endorses no candidate';
        $lines[] = 'policy, regardless of how many Readiness Gate requirements below are met.';
        $lines[] = 'Approving an AI Credit charging rate is a founder/business decision (see';
        $lines[] = '`internal-docs/commercial/ai-credit-policy-and-consumption-model-v1.md` §41) that';
        $lines[] = 'this report cannot make on its own. Its only purpose is to make the observed facts';
        $lines[] = 'above available for that future decision.';
        $lines[] = '';

        $lines[] = '### Founder Decisions Required';
        $lines[] = '';
        $chargingApproved = $readiness['items']['founder_approval']['status'] === 'ready';
        $migrationApproved = $readiness['items']['entitlement_migration_readiness']['status'] === 'ready';
        $lines[] = '- Approve (or reject) a charging strategy — flat, banded, or a workflow-specific mix — for each real workflow.'
            . ($chargingApproved ? ' **Approved — see Founder Approval in the Readiness Gate below.**' : '');
        $lines[] = '- Approve the `ai_analyses_per_month` → `ai_credits_per_month` entitlement migration and its grandfathering formula.'
            . ($migrationApproved ? ' **Approved/executed — see Entitlement Migration Readiness below. Grandfathering was not a live concern (no production customers exist on the old key yet).**' : '');
        $lines[] = '- Approve whether purchased AI Credit top-up packs will ever be offered.';
        $lines[] = '- Approve that the evidence in this report (and the observation period it was drawn from) is sufficient before G4C.3 begins.';
        $lines[] = '';

        $lines[] = '### Approval Status';
        $lines[] = '';
        $lines[] = 'Not submitted. No founder approval workflow or sign-off mechanism exists in this';
        $lines[] = 'system — approval happens outside it (see';
        $lines[] = '`internal-docs/commercial/ai-credit-policy-and-consumption-model-v1.md` §41). This';
        $lines[] = 'field is not a status this command can set; it is a fixed statement of fact.';
        $lines[] = '';

        $lines[] = '## 3. G4C.3 Readiness Gate';
        $lines[] = '';
        $lines[] = '| Requirement | Status | Notes |';
        $lines[] = '|---|---|---|';
        foreach ($readiness['items'] as $key => $item) {
            $label = ucwords(str_replace('_', ' ', $key));
            $lines[] = "| {$label} | " . strtoupper($item['status']) . " | {$item['notes']} |";
        }
        $lines[] = '';
        $lines[] = '**Overall status:** ' . strtoupper($readiness['overall_status']);
        $lines[] = '';
        $lines[] = $readiness['overall_reason'];
        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }

    private function describeFilters(array $filters): string
    {
        $parts = [];
        foreach (['workflow', 'organization_id', 'date_from', 'date_to'] as $key) {
            if (!empty($filters[$key])) {
                $parts[] = "{$key}={$filters[$key]}";
            }
        }

        return $parts === [] ? 'none (full dataset)' : implode(', ', $parts);
    }
}
