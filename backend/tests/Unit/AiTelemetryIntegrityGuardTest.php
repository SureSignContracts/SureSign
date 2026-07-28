<?php

namespace Tests\Unit;

use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TradePackage;
use App\Models\TradePackageAiAnalysis;
use App\Models\User;
use App\Support\AI\AiTelemetryImmutableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase G4C.2C-2 — confirms terminal-state AI execution telemetry cannot
 * be silently mutated through the normal Eloquent ->update() path, while
 * legitimate business/workflow transitions (status changes, confirmation,
 * reparse) that happen after completion remain unaffected.
 */
class AiTelemetryIntegrityGuardTest extends TestCase
{
    use RefreshDatabase;

    private function makeContractAnalysis(string $status = 'completed'): ContractAiAnalysis
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
            'created_by' => $user->id, 'status' => $status, 'model' => 'claude-sonnet-5',
            'provider' => 'anthropic', 'tokens_input' => 100, 'tokens_output' => 50, 'estimated_cost' => 0.42,
        ]);
    }

    public function test_protected_field_cannot_be_changed_once_status_is_terminal(): void
    {
        $analysis = $this->makeContractAnalysis('completed');

        $this->expectException(AiTelemetryImmutableException::class);

        $analysis->update(['estimated_cost' => 99.99]);
    }

    public function test_model_field_cannot_be_changed_once_failed(): void
    {
        $analysis = $this->makeContractAnalysis('failed');

        $this->expectException(AiTelemetryImmutableException::class);

        $analysis->update(['model' => 'claude-opus-5']);
    }

    public function test_setting_the_same_value_is_not_blocked(): void
    {
        $analysis = $this->makeContractAnalysis('completed');

        // Not dirty — no actual change — must not throw.
        $analysis->update(['estimated_cost' => 0.42]);

        $this->assertSame(0.42, (float) $analysis->fresh()->estimated_cost);
    }

    public function test_business_fields_remain_mutable_after_completion(): void
    {
        $analysis = $this->makeContractAnalysis('completed');

        // confirmAnalysis()'s real update shape — status + confirmed_data_json only.
        $analysis->update(['status' => 'confirmed', 'confirmed_data_json' => ['foo' => 'bar']]);

        $this->assertSame('confirmed', $analysis->fresh()->status);
    }

    public function test_reparse_style_update_after_failure_remains_allowed(): void
    {
        $analysis = $this->makeContractAnalysis('failed');

        // reparseAnalysis()'s real update shape — never touches a protected field.
        $analysis->update([
            'status' => 'completed',
            'raw_response_json' => ['contract_summary' => 'ok'],
            'summary' => 'ok',
            'error_message' => null,
            'completed_at' => now(),
        ]);

        $this->assertSame('completed', $analysis->fresh()->status);
    }

    public function test_a_fresh_transition_into_a_terminal_status_is_never_blocked(): void
    {
        $analysis = $this->makeContractAnalysis('processing');

        // Exactly the real job's success-path update shape — status transitions
        // to completed AND telemetry is written in the same call. Must be allowed
        // since the row was NOT already terminal beforehand.
        $analysis->update([
            'status' => 'completed',
            'tokens_input' => 200,
            'tokens_output' => 80,
            'estimated_cost' => 0.5,
            'completed_at' => now(),
        ]);

        $fresh = $analysis->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertSame(200, $fresh->tokens_input);
    }

    public function test_trade_package_analysis_is_protected_identically(): void
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
            'created_by' => $user->id, 'status' => 'completed', 'estimated_cost' => 0.42,
        ]);

        $this->expectException(AiTelemetryImmutableException::class);

        $analysis->update(['estimated_cost' => 12.0]);
    }
}
