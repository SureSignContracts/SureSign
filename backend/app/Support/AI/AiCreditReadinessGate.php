<?php

namespace App\Support\AI;

/**
 * Phase G4C.2E, Part 5 — the G4C.3 Readiness Gate. A pure classifier, in
 * the same spirit as App\Services\Entitlements\SnapshotIntegrityClassifier:
 * takes already-computed data (this class makes no query of its own) and
 * returns a structured Ready/Not Ready/Blocked/Unknown verdict per
 * requirement, plus an overall status and an explicit reason.
 *
 * Never mutates anything, never calls the AI provider, never implies an
 * approved commercial rate. Six of the ten requirements are computed live
 * from telemetry/simulation data already gathered by
 * AiTelemetryReportingService; the other four (founder approval,
 * entitlement migration readiness, documentation, operational readiness)
 * are process/business facts that cannot be derived from telemetry — see
 * config/ai_credit_readiness.php, the single place those four are recorded.
 */
class AiCreditReadinessGate
{
    public const STATUS_READY = 'ready';
    public const STATUS_NOT_READY = 'not_ready';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_UNKNOWN = 'unknown';

    public const VALID_STATUSES = [
        self::STATUS_READY,
        self::STATUS_NOT_READY,
        self::STATUS_BLOCKED,
        self::STATUS_UNKNOWN,
    ];

    /**
     * @param array $summary The array returned by AiTelemetryReportingService::summary().
     * @param array $health The array returned by AiTelemetryReportingService::telemetryHealth().
     * @param array $processState config('ai_credit_readiness') — the four non-computable facts.
     */
    public static function evaluate(array $summary, array $health, array $processState): array
    {
        $calibration = $summary['calibration'];
        $normalized = $calibration['normalized_input_size'];

        $sizeSpreadPresent = $normalized['sample_size'] > 1 && $normalized['p50'] !== $normalized['p99'];
        $workflowsWithData = collect($summary['by_workflow'])->filter(fn ($w) => $w['completed'] > 0)->count();
        $tradePackageCompleted = $summary['by_workflow'][AiWorkflow::TRADE_PACKAGE_ANALYSIS]['completed'] ?? 0;

        $representativeTelemetry = self::representativeTelemetry($calibration, $workflowsWithData, $sizeSpreadPresent);
        $telemetryHealthItem = self::telemetryHealthItem($health);
        $simulationCoverage = self::simulationCoverage($health);
        $tradePackageCoverage = self::tradePackageCoverage($summary, $tradePackageCompleted);
        $organizationDiversity = self::organizationDiversity($calibration);
        $commercialConfidence = self::commercialConfidence(
            $representativeTelemetry, $telemetryHealthItem, $simulationCoverage, $tradePackageCoverage, $organizationDiversity
        );

        $items = [
            'representative_telemetry' => $representativeTelemetry,
            'telemetry_health' => $telemetryHealthItem,
            'simulation_coverage' => $simulationCoverage,
            'trade_package_coverage' => $tradePackageCoverage,
            'organization_diversity' => $organizationDiversity,
            'commercial_confidence' => $commercialConfidence,
            'founder_approval' => self::fromProcessState($processState, 'founder_approval'),
            'entitlement_migration_readiness' => self::fromProcessState($processState, 'entitlement_migration_readiness'),
            'documentation' => self::fromProcessState($processState, 'documentation'),
            'operational_readiness' => self::fromProcessState($processState, 'operational_readiness'),
        ];

        return [
            'items' => $items,
            'overall_status' => self::overallStatus($items),
            'overall_reason' => self::overallReason($items),
            // The same derived facts the items above were computed from,
            // exposed so callers (e.g. GenerateAiCreditCalibrationReport's
            // Commercial Risks/Recommended Next Steps/Unknowns sections)
            // never need to recompute them independently from $summary.
            'signals' => [
                'workflows_with_data' => $workflowsWithData,
                'size_spread_present' => $sizeSpreadPresent,
                'trade_package_completed' => $tradePackageCompleted,
            ],
        ];
    }

    private static function representativeTelemetry(array $calibration, int $workflowsWithData, bool $sizeSpreadPresent): array
    {
        if ($calibration['completed_executions'] === 0) {
            return self::item(self::STATUS_UNKNOWN, 'No completed executions exist yet — representativeness cannot be assessed.');
        }

        if ($calibration['organizations_using_ai'] > 1 && $workflowsWithData > 1 && $sizeSpreadPresent) {
            return self::item(self::STATUS_READY, 'More than one organisation, both real workflows, and a genuine document-size spread are all represented.');
        }

        $missing = [];
        if ($calibration['organizations_using_ai'] <= 1) {
            $missing[] = 'more than one organisation';
        }
        if ($workflowsWithData <= 1) {
            $missing[] = 'both real workflows with completed executions';
        }
        if (!$sizeSpreadPresent) {
            $missing[] = 'a genuine document-size spread';
        }

        return self::item(self::STATUS_NOT_READY, 'Still missing: ' . implode('; ', $missing) . '.');
    }

    private static function telemetryHealthItem(array $health): array
    {
        // duplicated_simulations should be structurally impossible (a DB
        // unique constraint enforces it) — a non-zero count means the
        // constraint was bypassed and indicates a real bug, not merely an
        // incomplete sample. That warrants Blocked, not just Not Ready.
        if ($health['duplicated_simulations'] > 0) {
            return self::item(self::STATUS_BLOCKED, 'Duplicated simulation rows detected (' . $health['duplicated_simulations'] . ') — this should be structurally impossible and requires investigation before this dataset can be trusted.');
        }

        $findings = $health['incomplete_telemetry'] + $health['missing_provider_cost']
            + $health['impossible_values'] + $health['simulation_errors'];

        if ($findings === 0) {
            return self::item(self::STATUS_READY, 'No outstanding telemetry quality findings.');
        }

        return self::item(self::STATUS_NOT_READY, 'Outstanding findings: incomplete_telemetry=' . $health['incomplete_telemetry']
            . ', missing_provider_cost=' . $health['missing_provider_cost']
            . ', impossible_values=' . $health['impossible_values']
            . ', simulation_errors=' . $health['simulation_errors'] . '.');
    }

    private static function simulationCoverage(array $health): array
    {
        if ($health['calibration_eligible_total'] === 0) {
            return self::item(self::STATUS_UNKNOWN, 'No calibration-eligible executions exist yet.');
        }

        if ($health['missing_normalized_input_or_simulation'] === 0) {
            return self::item(self::STATUS_READY, 'Every calibration-eligible execution has a simulation result.');
        }

        return self::item(self::STATUS_NOT_READY, $health['missing_normalized_input_or_simulation'] . ' calibration-eligible execution(s) have no simulation result — see ai:credits:backfill-simulations.');
    }

    private static function tradePackageCoverage(array $summary, int $tradePackageCompleted): array
    {
        if ($summary['total_analyses'] === 0) {
            return self::item(self::STATUS_UNKNOWN, 'No executions of any workflow exist yet.');
        }

        if ($tradePackageCompleted > 0) {
            return self::item(self::STATUS_READY, $tradePackageCompleted . ' completed Trade Package Analysis execution(s) observed.');
        }

        return self::item(self::STATUS_NOT_READY, 'No completed Trade Package Analysis execution observed yet — its cost/size profile remains entirely unvalidated.');
    }

    private static function organizationDiversity(array $calibration): array
    {
        if ($calibration['organizations_using_ai'] === 0) {
            return self::item(self::STATUS_UNKNOWN, 'No organisations have used AI analysis yet.');
        }

        if ($calibration['organizations_using_ai'] > 1) {
            return self::item(self::STATUS_READY, $calibration['organizations_using_ai'] . ' organisations represented.');
        }

        return self::item(self::STATUS_NOT_READY, 'Only one organisation represented — cross-organisation variance is unknown.');
    }

    private static function commercialConfidence(array ...$components): array
    {
        $statuses = array_map(fn ($c) => $c['status'], $components);

        if (in_array(self::STATUS_BLOCKED, $statuses, true)) {
            return self::item(self::STATUS_BLOCKED, 'Blocked by an outstanding telemetry integrity finding — see Telemetry Health.');
        }

        if (in_array(self::STATUS_NOT_READY, $statuses, true)) {
            return self::item(self::STATUS_NOT_READY, 'One or more underlying requirements (representative telemetry, telemetry health, simulation coverage, Trade Package coverage, organisation diversity) is not yet ready.');
        }

        if (in_array(self::STATUS_UNKNOWN, $statuses, true)) {
            return self::item(self::STATUS_UNKNOWN, 'One or more underlying requirements has no data yet to assess.');
        }

        return self::item(self::STATUS_READY, 'All underlying telemetry-derived requirements are ready.');
    }

    private static function fromProcessState(array $processState, string $key): array
    {
        $entry = $processState[$key] ?? null;

        if ($entry === null || !in_array($entry['status'] ?? null, self::VALID_STATUSES, true)) {
            return self::item(self::STATUS_UNKNOWN, "config/ai_credit_readiness.php is missing a valid entry for \"{$key}\".");
        }

        return self::item($entry['status'], $entry['notes'] ?? '');
    }

    private static function item(string $status, string $notes): array
    {
        return ['status' => $status, 'notes' => $notes];
    }

    private static function overallStatus(array $items): string
    {
        $statuses = array_map(fn ($item) => $item['status'], $items);

        if (in_array(self::STATUS_BLOCKED, $statuses, true)) {
            return self::STATUS_BLOCKED;
        }

        // G4C.3 requires every requirement to be Ready — anything short of
        // that (Not Ready or Unknown on even one item) means G4C.3 itself
        // is blocked, not "partially ready." There is no partial-credit
        // overall state.
        if (in_array(self::STATUS_NOT_READY, $statuses, true) || in_array(self::STATUS_UNKNOWN, $statuses, true)) {
            return self::STATUS_BLOCKED;
        }

        return self::STATUS_READY;
    }

    private static function overallReason(array $items): string
    {
        $notReady = collect($items)
            ->filter(fn ($item) => $item['status'] !== self::STATUS_READY)
            ->map(fn ($item, $key) => "{$key} ({$item['status']})")
            ->values()
            ->all();

        if ($notReady === []) {
            return 'Every requirement is Ready — G4C.3 is no longer blocked by this gate. (An unblocked gate does not itself authorise implementation — see the AI Credit Policy document\'s approval process.)';
        }

        return 'G4C.3 remains blocked. Not-yet-Ready requirement(s): ' . implode(', ', $notReady) . '.';
    }
}
