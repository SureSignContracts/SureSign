<?php

namespace Tests\Unit;

use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TradePackage;
use App\Models\TradePackageAiAnalysis;
use App\Models\User;
use App\Services\Entitlements\FeatureGate;
use App\Services\Intelligence\UsageMetricsService;
use App\Support\Entitlements\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase G4C.2C-2, Objective A — UsageMetricsService::aiAnalysesThisMonth()
 * previously only summed ContractAiAnalysis, silently omitting
 * TradePackageAiAnalysis (a real defect found during G4C.1A). Fixed as its
 * own isolated change — this stays a plain analysis-count metric,
 * unrelated to the AI Credit simulation work elsewhere in this phase.
 */
class UsageMetricsAiCountingTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(): array
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P']);

        return compact('org', 'user', 'project');
    }

    private function usageFor(Organization $org): float
    {
        $service = app(UsageMetricsService::class);
        $rows = $service->usageForOrganization($org);
        $row = collect($rows)->firstWhere('feature_key', Feature::AI_ANALYSES_PER_MONTH);

        return $row['used'];
    }

    public function test_contract_ai_analysis_is_counted(): void
    {
        ['org' => $org, 'user' => $user, 'project' => $project] = $this->makeOrg();
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id,
            'created_by' => $user->id, 'type' => 'main_contract', 'title' => 'C',
        ]);
        ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed',
        ]);

        $this->assertSame(1.0, $this->usageFor($org));
    }

    public function test_trade_package_ai_analysis_is_counted(): void
    {
        ['org' => $org, 'user' => $user, 'project' => $project] = $this->makeOrg();
        $tradePackage = TradePackage::create([
            'organization_id' => $org->id, 'project_id' => $project->id,
            'name' => 'Package', 'slug' => 'package-' . uniqid(), 'created_by' => $user->id,
        ]);
        TradePackageAiAnalysis::create([
            'trade_package_id' => $tradePackage->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed',
        ]);

        $this->assertSame(1.0, $this->usageFor($org));
    }

    public function test_mixed_workflow_usage_is_aggregated_correctly(): void
    {
        ['org' => $org, 'user' => $user, 'project' => $project] = $this->makeOrg();
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id,
            'created_by' => $user->id, 'type' => 'main_contract', 'title' => 'C',
        ]);
        $tradePackage = TradePackage::create([
            'organization_id' => $org->id, 'project_id' => $project->id,
            'name' => 'Package', 'slug' => 'package-' . uniqid(), 'created_by' => $user->id,
        ]);

        ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed',
        ]);
        ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'failed',
        ]);
        TradePackageAiAnalysis::create([
            'trade_package_id' => $tradePackage->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'confirmed',
        ]);

        $this->assertSame(3.0, $this->usageFor($org));
    }

    public function test_organisation_isolation(): void
    {
        ['org' => $org, 'user' => $user, 'project' => $project] = $this->makeOrg();
        ['org' => $otherOrg, 'user' => $otherUser, 'project' => $otherProject] = $this->makeOrg();

        $tradePackage = TradePackage::create([
            'organization_id' => $otherOrg->id, 'project_id' => $otherProject->id,
            'name' => 'Package', 'slug' => 'package-' . uniqid(), 'created_by' => $otherUser->id,
        ]);
        TradePackageAiAnalysis::create([
            'trade_package_id' => $tradePackage->id, 'organization_id' => $otherOrg->id, 'project_id' => $otherProject->id,
            'created_by' => $otherUser->id, 'status' => 'completed',
        ]);

        $this->assertSame(0.0, $this->usageFor($org));
        $this->assertSame(1.0, $this->usageFor($otherOrg));
    }

    public function test_pending_and_cancelled_are_excluded_for_both_workflows(): void
    {
        ['org' => $org, 'user' => $user, 'project' => $project] = $this->makeOrg();
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id,
            'created_by' => $user->id, 'type' => 'main_contract', 'title' => 'C',
        ]);
        $tradePackage = TradePackage::create([
            'organization_id' => $org->id, 'project_id' => $project->id,
            'name' => 'Package', 'slug' => 'package-' . uniqid(), 'created_by' => $user->id,
        ]);

        ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending',
        ]);
        TradePackageAiAnalysis::create([
            'trade_package_id' => $tradePackage->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'cancelled',
        ]);

        $this->assertSame(0.0, $this->usageFor($org));
    }

    public function test_only_current_utc_calendar_month_is_counted(): void
    {
        ['org' => $org, 'user' => $user, 'project' => $project] = $this->makeOrg();
        $tradePackage = TradePackage::create([
            'organization_id' => $org->id, 'project_id' => $project->id,
            'name' => 'Package', 'slug' => 'package-' . uniqid(), 'created_by' => $user->id,
        ]);

        $analysis = TradePackageAiAnalysis::create([
            'trade_package_id' => $tradePackage->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed',
        ]);
        // Force created_at into last month, bypassing the timestamps() auto-set.
        $analysis->timestamps = false;
        $analysis->created_at = now('UTC')->subMonthNoOverflow()->startOfMonth();
        $analysis->save();

        $this->assertSame(0.0, $this->usageFor($org));
    }
}
