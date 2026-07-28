<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TradePackage;
use App\Models\TradePackageAiAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase G4C.2C-1 — confirms the real customer-facing API endpoints (not
 * just the presenter in isolation) never return execution telemetry.
 * These hit the actual routes/controllers, so a future controller change
 * that bypasses AiAnalysisPresenter would be caught here even if the
 * presenter itself stayed correct.
 */
class AiAnalysisApiTelemetryProtectionTest extends TestCase
{
    use RefreshDatabase;

    private const LEAKED_KEYS = ['model', 'provider', 'estimated_cost', 'tokens_input', 'tokens_output', 'workflow', 'document_hash', 'stop_reason'];

    private function makeContractFixtures(): array
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P']);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id,
            'created_by' => $user->id, 'type' => 'main_contract', 'title' => 'C',
        ]);
        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed', 'model' => 'claude-sonnet-5',
            'provider' => 'anthropic', 'workflow' => 'contract_analysis',
            'tokens_input' => 100, 'tokens_output' => 50, 'estimated_cost' => 0.42,
        ]);

        return compact('org', 'user', 'project', 'contract', 'analysis');
    }

    public function test_show_analysis_endpoint_does_not_leak_execution_telemetry(): void
    {
        ['user' => $user, 'analysis' => $analysis] = $this->makeContractFixtures();
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/ai/analyses/{$analysis->id}");

        $response->assertOk();
        foreach (self::LEAKED_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $response->json('data'), "GET /ai/analyses/{id} leaked '{$key}'");
        }
    }

    public function test_list_analyses_endpoint_does_not_leak_execution_telemetry(): void
    {
        ['user' => $user, 'contract' => $contract] = $this->makeContractFixtures();
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/contracts/{$contract->id}/ai-analyses");

        $response->assertOk();
        foreach (self::LEAKED_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $response->json('data.0'), "GET .../ai-analyses leaked '{$key}'");
        }
    }

    public function test_list_for_project_endpoint_does_not_leak_execution_telemetry(): void
    {
        ['user' => $user, 'project' => $project] = $this->makeContractFixtures();
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/projects/{$project->id}/ai-analyses");

        $response->assertOk();
        foreach (self::LEAKED_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $response->json('data.0'), "GET /projects/{id}/ai-analyses leaked '{$key}'");
        }
    }

    public function test_get_latest_analysis_endpoint_does_not_leak_execution_telemetry(): void
    {
        ['user' => $user, 'contract' => $contract] = $this->makeContractFixtures();
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/contracts/{$contract->id}/ai-analysis");

        $response->assertOk();
        foreach (self::LEAKED_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $response->json('data'), "GET .../ai-analysis leaked '{$key}'");
        }
    }

    public function test_trade_package_show_analysis_endpoint_does_not_leak_execution_telemetry(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P']);
        $tradePackage = TradePackage::create([
            'organization_id' => $org->id, 'project_id' => $project->id,
            'name' => 'Package', 'slug' => 'package-' . uniqid(), 'created_by' => $user->id,
        ]);
        $analysis = TradePackageAiAnalysis::create([
            'trade_package_id' => $tradePackage->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed', 'model' => 'claude-sonnet-5',
            'provider' => 'anthropic', 'workflow' => 'trade_package_analysis',
            'tokens_input' => 100, 'tokens_output' => 50, 'estimated_cost' => 0.42,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/trade-package-ai-analyses/{$analysis->id}");

        $response->assertOk();
        foreach (self::LEAKED_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $response->json('data'), "GET /trade-package-ai-analyses/{id} leaked '{$key}'");
        }
    }

    public function test_another_organizations_user_cannot_access_the_analysis(): void
    {
        ['analysis' => $analysis] = $this->makeContractFixtures();

        $otherOrg = Organization::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);
        Sanctum::actingAs($otherUser);

        $response = $this->getJson("/api/ai/analyses/{$analysis->id}");

        $response->assertForbidden();
    }
}
