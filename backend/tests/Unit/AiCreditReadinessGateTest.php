<?php

namespace Tests\Unit;

use App\Support\AI\AiCreditReadinessGate;
use Tests\TestCase;

/**
 * Phase G4C.2E, Part 5 — a pure classifier test (no DB, no HTTP): confirms
 * the ten-requirement structure, the Blocked-on-duplicated-simulations
 * escalation, the Unknown-on-no-data behaviour, and that G4C.3 stays
 * "blocked" overall unless literally every requirement (including the four
 * process-state ones from config/ai_credit_readiness.php) reads Ready.
 */
class AiCreditReadinessGateTest extends TestCase
{
    private function emptySummary(): array
    {
        return [
            'total_analyses' => 0,
            'by_workflow' => [
                'contract_analysis' => ['count' => 0, 'completed' => 0, 'failed' => 0, 'total_estimated_cost' => 0],
                'trade_package_analysis' => ['count' => 0, 'completed' => 0, 'failed' => 0, 'total_estimated_cost' => 0],
            ],
            'calibration' => [
                'completed_executions' => 0,
                'organizations_using_ai' => 0,
                'normalized_input_size' => ['sample_size' => 0, 'average' => null, 'p50' => null, 'p90' => null, 'p99' => null],
            ],
        ];
    }

    private function emptyHealth(): array
    {
        return [
            'legacy_records' => 0, 'incomplete_telemetry' => 0, 'missing_provider_cost' => 0,
            'missing_normalized_input_or_simulation' => 0, 'impossible_values' => 0,
            'duplicated_simulations' => 0, 'simulation_errors' => 0, 'calibration_eligible_total' => 0,
        ];
    }

    private function processState(array $overrides = []): array
    {
        return array_merge([
            'founder_approval' => ['status' => 'not_ready', 'notes' => ''],
            'entitlement_migration_readiness' => ['status' => 'not_ready', 'notes' => ''],
            'documentation' => ['status' => 'ready', 'notes' => ''],
            'operational_readiness' => ['status' => 'not_ready', 'notes' => ''],
        ], $overrides);
    }

    public function test_returns_all_ten_requirements(): void
    {
        $result = AiCreditReadinessGate::evaluate($this->emptySummary(), $this->emptyHealth(), $this->processState());

        $this->assertCount(10, $result['items']);
        $this->assertArrayHasKey('representative_telemetry', $result['items']);
        $this->assertArrayHasKey('founder_approval', $result['items']);
    }

    public function test_no_data_yields_unknown_for_data_dependent_requirements(): void
    {
        $result = AiCreditReadinessGate::evaluate($this->emptySummary(), $this->emptyHealth(), $this->processState());

        $this->assertSame('unknown', $result['items']['representative_telemetry']['status']);
        $this->assertSame('unknown', $result['items']['simulation_coverage']['status']);
        $this->assertSame('unknown', $result['items']['trade_package_coverage']['status']);
        $this->assertSame('unknown', $result['items']['organization_diversity']['status']);
    }

    public function test_overall_status_is_blocked_when_any_requirement_is_not_ready(): void
    {
        $result = AiCreditReadinessGate::evaluate($this->emptySummary(), $this->emptyHealth(), $this->processState());

        $this->assertSame('blocked', $result['overall_status']);
        $this->assertStringContainsString('G4C.3 remains blocked', $result['overall_reason']);
    }

    public function test_duplicated_simulations_escalates_telemetry_health_to_blocked(): void
    {
        $health = $this->emptyHealth();
        $health['duplicated_simulations'] = 2;

        $result = AiCreditReadinessGate::evaluate($this->emptySummary(), $health, $this->processState());

        $this->assertSame('blocked', $result['items']['telemetry_health']['status']);
        $this->assertSame('blocked', $result['items']['commercial_confidence']['status']);
        $this->assertSame('blocked', $result['overall_status']);
    }

    public function test_fully_populated_representative_dataset_reads_ready_except_process_state(): void
    {
        $summary = [
            'total_analyses' => 20,
            'by_workflow' => [
                'contract_analysis' => ['count' => 15, 'completed' => 15, 'failed' => 0, 'total_estimated_cost' => 10],
                'trade_package_analysis' => ['count' => 5, 'completed' => 5, 'failed' => 0, 'total_estimated_cost' => 3],
            ],
            'calibration' => [
                'completed_executions' => 20,
                'organizations_using_ai' => 3,
                'normalized_input_size' => ['sample_size' => 20, 'average' => 50000, 'p50' => 40000, 'p90' => 90000, 'p99' => 120000],
            ],
        ];
        $health = $this->emptyHealth();
        $health['calibration_eligible_total'] = 20;

        $result = AiCreditReadinessGate::evaluate($summary, $health, $this->processState());

        $this->assertSame('ready', $result['items']['representative_telemetry']['status']);
        $this->assertSame('ready', $result['items']['telemetry_health']['status']);
        $this->assertSame('ready', $result['items']['simulation_coverage']['status']);
        $this->assertSame('ready', $result['items']['trade_package_coverage']['status']);
        $this->assertSame('ready', $result['items']['organization_diversity']['status']);
        $this->assertSame('ready', $result['items']['commercial_confidence']['status']);
        // Process-state items are still not_ready by default config — overall stays blocked.
        $this->assertSame('blocked', $result['overall_status']);
    }

    public function test_overall_is_ready_only_when_every_single_requirement_is_ready(): void
    {
        $summary = [
            'total_analyses' => 20,
            'by_workflow' => [
                'contract_analysis' => ['count' => 15, 'completed' => 15, 'failed' => 0, 'total_estimated_cost' => 10],
                'trade_package_analysis' => ['count' => 5, 'completed' => 5, 'failed' => 0, 'total_estimated_cost' => 3],
            ],
            'calibration' => [
                'completed_executions' => 20,
                'organizations_using_ai' => 3,
                'normalized_input_size' => ['sample_size' => 20, 'average' => 50000, 'p50' => 40000, 'p90' => 90000, 'p99' => 120000],
            ],
        ];
        $health = $this->emptyHealth();
        $health['calibration_eligible_total'] = 20;

        $allReadyProcessState = $this->processState([
            'founder_approval' => ['status' => 'ready', 'notes' => ''],
            'entitlement_migration_readiness' => ['status' => 'ready', 'notes' => ''],
            'operational_readiness' => ['status' => 'ready', 'notes' => ''],
        ]);

        $result = AiCreditReadinessGate::evaluate($summary, $health, $allReadyProcessState);

        $this->assertSame('ready', $result['overall_status']);
    }

    public function test_missing_process_state_entry_is_unknown_not_a_crash(): void
    {
        $result = AiCreditReadinessGate::evaluate($this->emptySummary(), $this->emptyHealth(), []);

        $this->assertSame('unknown', $result['items']['founder_approval']['status']);
        $this->assertStringContainsString('missing a valid entry', $result['items']['founder_approval']['notes']);
    }
}
