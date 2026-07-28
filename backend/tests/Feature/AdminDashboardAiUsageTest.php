<?php

namespace Tests\Feature;

use App\Models\ContractAiAnalysis;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TradePackage;
use App\Models\TradePackageAiAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase G4C.1 — AdminController::dashboard()'s "monthly_ai_usage" stat
 * previously counted AiConversation rows: a non-functional AI chat feature
 * no working code path ever creates (see
 * internal-docs/super-admin/ai-credits-architecture.md §3.4), so this stat
 * was always 0 regardless of real AI usage. It now counts real analyses
 * (ContractAiAnalysis + TradePackageAiAnalysis) created this calendar month.
 */
class AdminDashboardAiUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_ai_usage_counts_real_analyses_not_the_non_functional_ai_chat_table(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $admin = User::factory()->create(['organization_id' => $org->id]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        $project = Project::create(['organization_id' => $org->id, 'created_by' => $admin->id, 'name' => 'P']);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id,
            'created_by' => $admin->id, 'type' => 'main_contract', 'title' => 'C',
        ]);
        $tradePackage = TradePackage::create([
            'organization_id' => $org->id, 'project_id' => $project->id,
            'name' => 'Package', 'slug' => 'package-' . uniqid(), 'created_by' => $admin->id,
        ]);

        ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $admin->id, 'status' => 'completed', 'workflow' => 'contract_analysis',
        ]);
        TradePackageAiAnalysis::create([
            'trade_package_id' => $tradePackage->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $admin->id, 'status' => 'completed', 'workflow' => 'trade_package_analysis',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertOk();
        $this->assertSame(2, $response->json('stats.monthly_ai_usage'));
    }
}
