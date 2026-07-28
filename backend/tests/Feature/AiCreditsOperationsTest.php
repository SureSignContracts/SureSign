<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\AI\AiCreditLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase G4C.3D-1 — AI Credits Operations Dashboard backend
 * (GET /admin/ai-credits/*). Read-only, role:Super Admin|Admin only.
 * Confirms: server-side authorisation, platform-wide vs. per-organisation
 * balance aggregation is correct and consistent, filters work, and the
 * "AI Workflow Usage" figures are derived from the existing ledger/analysis
 * data (never recomputed independently).
 */
class AiCreditsOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $org = Organization::create(['name' => 'Platform', 'slug' => 'platform-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        return $user;
    }

    private function makeAnalysis(Organization $org, User $user): ContractAiAnalysis
    {
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P' . uniqid()]);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id,
            'created_by' => $user->id, 'type' => 'main_contract', 'title' => 'C',
        ]);

        return ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed', 'workflow' => 'contract_analysis',
            'model' => 'claude-sonnet-5', 'provider' => 'anthropic', 'provider_called' => true,
            'document_hash' => hash('sha256', uniqid('doc-', true)),
            'shadow_enforcement_result' => 'sufficient', 'credit_reservation_amount' => 5,
            'completed_at' => now(),
        ]);
    }

    public function test_client_user_is_denied_on_every_route(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $client = User::factory()->create(['organization_id' => $org->id]);
        $client->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        Sanctum::actingAs($client);

        $this->getJson('/api/admin/ai-credits/summary')->assertForbidden();
        $this->getJson('/api/admin/ai-credits/organizations')->assertForbidden();
        $this->getJson("/api/admin/ai-credits/organizations/{$org->id}")->assertForbidden();
        $this->getJson('/api/admin/ai-credits/transactions')->assertForbidden();
        $this->getJson('/api/admin/ai-credits/shadow-activity')->assertForbidden();
    }

    public function test_unauthenticated_request_is_denied(): void
    {
        $this->getJson('/api/admin/ai-credits/summary')->assertUnauthorized();
    }

    public function test_summary_platform_balance_sums_across_organisations(): void
    {
        $orgA = Organization::create(['name' => 'OrgA', 'slug' => 'orga-' . uniqid()]);
        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $orgB = Organization::create(['name' => 'OrgB', 'slug' => 'orgb-' . uniqid()]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);

        $ledger = app(AiCreditLedgerService::class);
        $ledger->grant($orgA->id, 100, 'Grant A', 'grant-a-' . uniqid(), $userA->id);
        $ledger->grant($orgB->id, 40, 'Grant B', 'grant-b-' . uniqid(), $userB->id);

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/admin/ai-credits/summary')->assertOk();

        $response->assertJsonPath('issued', 140);
        $response->assertJsonPath('available', 140);
        $response->assertJsonPath('active_organizations', 2);
    }

    public function test_organizations_list_returns_per_organisation_balance(): void
    {
        $org = Organization::create(['name' => 'Searchable Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        app(AiCreditLedgerService::class)->grant($org->id, 60, 'Grant', 'grant-' . uniqid(), $user->id);

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/admin/ai-credits/organizations?search=Searchable')->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertEquals(60.0, $rows[0]['available']);
    }

    public function test_organization_detail_includes_workflow_usage_derived_from_ledger(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $ledger = app(AiCreditLedgerService::class);
        $ledger->grant($org->id, 100, 'Grant', 'grant-' . uniqid(), $user->id);

        $analysis = $this->makeAnalysis($org, $user);
        $ledger->reserve($org->id, 'contract_analysis', ContractAiAnalysis::class, $analysis->id, 5, 'Reserve', 'contract_analysis:reserve:' . $analysis->id);
        $ledger->settle(ContractAiAnalysis::class, $analysis->id, 'Settle', 'contract_analysis:settle:' . $analysis->id);

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson("/api/admin/ai-credits/organizations/{$org->id}")->assertOk();

        $response->assertJsonPath('balance.consumed', 5);
        $response->assertJsonPath('workflow_usage.contract_analysis.total_analyses', 1);
        $response->assertJsonPath('workflow_usage.contract_analysis.credits_consumed', 5);
        $response->assertJsonPath('workflow_usage.contract_analysis.average_credits_per_analysis', 5);
        $response->assertJsonPath('workflow_usage.contract_analysis.shadow_sufficient', 1);
        $response->assertJsonPath('workflow_usage.trade_package_analysis.total_analyses', 0);
        $response->assertJsonPath('workflow_usage.trade_package_analysis.average_credits_per_analysis', null);
    }

    public function test_transactions_endpoint_filters_by_organization_and_transaction_type(): void
    {
        $orgA = Organization::create(['name' => 'OrgA', 'slug' => 'orga-' . uniqid()]);
        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $orgB = Organization::create(['name' => 'OrgB', 'slug' => 'orgb-' . uniqid()]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);

        $ledger = app(AiCreditLedgerService::class);
        $ledger->grant($orgA->id, 50, 'Grant A', 'grant-a-' . uniqid(), $userA->id);
        $ledger->grant($orgB->id, 30, 'Grant B', 'grant-b-' . uniqid(), $userB->id);
        $ledger->adjustCredit($orgA->id, 10, 'Adjustment A', 'adj-a-' . uniqid(), $userA->id);

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson("/api/admin/ai-credits/transactions?organization_id={$orgA->id}&transaction_type=grant")->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertEquals(50.0, $rows[0]['amount']);
        $this->assertSame('OrgA', $rows[0]['organization_name']);
    }

    public function test_transactions_are_never_editable_via_this_controller(): void
    {
        Sanctum::actingAs($this->superAdmin());

        // No PUT/PATCH/DELETE route exists at all for this resource.
        $this->putJson('/api/admin/ai-credits/transactions/1', [])->assertStatus(404);
        $this->deleteJson('/api/admin/ai-credits/transactions/1')->assertStatus(404);
    }

    public function test_shadow_activity_filters_by_status_and_workflow(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->makeAnalysis($org, $user);

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/admin/ai-credits/shadow-activity?workflow=contract_analysis&shadow_status=sufficient')->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('sufficient', $rows[0]['shadow_enforcement_result']);

        $none = $this->getJson('/api/admin/ai-credits/shadow-activity?shadow_status=insufficient')->assertOk();
        $this->assertCount(0, $none->json('data'));
    }
}
