<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractRisk;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cleanup regression: ProjectController::dashboardIntelligence() used
 * MySQL-only orderByRaw("FIELD(...))") in two places (main-contract status
 * ordering, risk severity ordering), 500ing under sqlite. Replaced with
 * portable CASE expressions — same pattern already applied to
 * NotificationController and RiskController in earlier batches.
 */
class CleanupDashboardIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgAndUser(): array
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org']);
        $user = User::factory()->create(['organization_id' => $org->id]);

        return compact('org', 'user');
    }

    private function makeProject(Organization $org, User $user): Project
    {
        return Project::create([
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'name'            => 'Project',
            'status'          => 'active',
        ]);
    }

    public function test_dashboard_intelligence_returns_200_and_orders_contracts_and_risks_correctly(): void
    {
        $a = $this->makeOrgAndUser();
        $project = $this->makeProject($a['org'], $a['user']);

        // Multiple main contracts in non-priority insertion order — the
        // fixed CASE ordering must still prefer 'active' over 'terminated'.
        Contract::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'created_by' => $a['user']->id, 'title' => 'Old terminated contract',
            'type' => 'main_contract', 'status' => 'terminated',
        ]);
        $activeContract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'created_by' => $a['user']->id, 'title' => 'Active contract',
            'type' => 'main_contract', 'status' => 'active',
        ]);

        ContractRisk::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'contract_id' => $activeContract->id, 'title' => 'Low risk',
            'severity' => 'low', 'category' => 'other', 'status' => 'open',
        ]);
        ContractRisk::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'contract_id' => $activeContract->id, 'title' => 'Critical risk',
            'severity' => 'critical', 'category' => 'other', 'status' => 'open',
        ]);

        Sanctum::actingAs($a['user']);

        $response = $this->getJson("/api/projects/{$project->id}/dashboard-intelligence");

        $response->assertStatus(200);
        $response->assertJsonPath('main_contract.title', 'Active contract');
        $response->assertJsonPath('risk_summary.critical', 1);
        $topRiskTitles = collect($response->json('risk_summary.top_risks'))->pluck('title');
        $this->assertEquals('Critical risk', $topRiskTitles->first());
    }
}
