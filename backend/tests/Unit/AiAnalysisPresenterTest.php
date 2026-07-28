<?php

namespace Tests\Unit;

use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TradePackage;
use App\Models\TradePackageAiAnalysis;
use App\Models\User;
use App\Support\AI\AiAnalysisPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase G4C.2C-1 — confirms the customer-facing/internal presenter split
 * for AI analyses. Deliberately asserts on the exact key set, not just
 * "cost isn't present" — a durable boundary means *no* execution telemetry
 * key survives in the customer-facing shape, even ones that aren't
 * individually "sensitive" (workflow, document_hash, stop_reason, etc.).
 */
class AiAnalysisPresenterTest extends TestCase
{
    use RefreshDatabase;

    private const EXECUTION_TELEMETRY_KEYS = [
        'organization_id', 'file_upload_id', 'workflow', 'provider', 'model',
        'document_hash', 'document_char_count', 'document_file_type',
        'raw_response_text', 'stop_reason', 'provider_called',
        'failure_category', 'tokens_input', 'tokens_output', 'estimated_cost',
        'duration_ms', 'queue_attempt', 'is_final_attempt', 'created_by',
    ];

    public function test_customer_facing_contract_analysis_excludes_all_execution_telemetry(): void
    {
        $analysis = $this->makeContractAnalysis();

        $shape = AiAnalysisPresenter::customerFacingContractAnalysis($analysis);

        foreach (self::EXECUTION_TELEMETRY_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $shape, "customer-facing shape leaked '{$key}'");
        }

        // And confirms the legitimate business fields are still present.
        $this->assertArrayHasKey('id', $shape);
        $this->assertArrayHasKey('status', $shape);
        $this->assertArrayHasKey('summary', $shape);
        $this->assertArrayHasKey('raw_response_json', $shape);
        $this->assertArrayHasKey('creator', $shape);
        $this->assertArrayHasKey('contract', $shape);
    }

    public function test_internal_contract_analysis_includes_full_execution_telemetry(): void
    {
        $analysis = $this->makeContractAnalysis();

        $shape = AiAnalysisPresenter::internalContractAnalysis($analysis);

        foreach (self::EXECUTION_TELEMETRY_KEYS as $key) {
            $this->assertArrayHasKey($key, $shape, "internal shape is missing '{$key}'");
        }

        $this->assertSame('claude-sonnet-5', $shape['model']);
        $this->assertSame(0.42, (float) $shape['estimated_cost']);
    }

    public function test_customer_facing_trade_package_analysis_excludes_all_execution_telemetry(): void
    {
        $analysis = $this->makeTradePackageAnalysis();

        $shape = AiAnalysisPresenter::customerFacingTradePackageAnalysis($analysis);

        foreach (self::EXECUTION_TELEMETRY_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $shape, "customer-facing shape leaked '{$key}'");
        }

        $this->assertArrayHasKey('trade_package', $shape);
    }

    public function test_internal_trade_package_analysis_includes_full_execution_telemetry(): void
    {
        $analysis = $this->makeTradePackageAnalysis();

        $shape = AiAnalysisPresenter::internalTradePackageAnalysis($analysis);

        foreach (self::EXECUTION_TELEMETRY_KEYS as $key) {
            $this->assertArrayHasKey($key, $shape, "internal shape is missing '{$key}'");
        }
    }

    private function makeContractAnalysis(): ContractAiAnalysis
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P']);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id,
            'created_by' => $user->id, 'type' => 'main_contract', 'title' => 'C',
        ]);

        return ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed', 'model' => 'claude-sonnet-5',
            'provider' => 'anthropic', 'workflow' => 'contract_analysis',
            'tokens_input' => 100, 'tokens_output' => 50, 'estimated_cost' => 0.42,
        ]);
    }

    private function makeTradePackageAnalysis(): TradePackageAiAnalysis
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P']);
        $tradePackage = TradePackage::create([
            'organization_id' => $org->id, 'project_id' => $project->id,
            'name' => 'Package', 'slug' => 'package-' . uniqid(), 'created_by' => $user->id,
        ]);

        return TradePackageAiAnalysis::create([
            'trade_package_id' => $tradePackage->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed', 'model' => 'claude-sonnet-5',
            'provider' => 'anthropic', 'workflow' => 'trade_package_analysis',
            'tokens_input' => 100, 'tokens_output' => 50, 'estimated_cost' => 0.42,
        ]);
    }
}
