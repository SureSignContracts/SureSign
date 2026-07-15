<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractProgrammeMilestone;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TradePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 3: Programme.
 *
 * ProgrammeMilestoneController had NO authorization checks at all on ANY
 * method before this fix — index, indexByProject, indexByTradePackage,
 * store, storeForTradePackage, update, destroy, and seedFromAnalysis were
 * all completely open to any authenticated user regardless of
 * organisation. This was a genuine cross-tenant vulnerability affecting
 * every role (not just Client), fixed here for everyone.
 */
class Batch3ProgrammeTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgAndUser(string $label): array
    {
        static $n = 0;
        $n++;

        $org = Organization::create(['name' => "{$label} Org {$n}", 'slug' => "org-{$label}-{$n}"]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        return compact('org', 'user');
    }

    private function makeProject(Organization $org, User $user): Project
    {
        return Project::create([
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'name'            => "Project for {$org->name}",
            'status'          => 'active',
        ]);
    }

    private function makeContract(Project $project, User $user): Contract
    {
        return Contract::create([
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $user->id,
            'title'           => 'Main Contract',
            'type'            => 'main_contract',
            'status'          => 'active',
        ]);
    }

    private function makeTradePackage(Project $project, User $user): TradePackage
    {
        static $n = 0;
        $n++;

        return TradePackage::create([
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $user->id,
            'name'            => 'Groundworks',
            'slug'            => "groundworks-{$n}",
            'status'          => 'active',
        ]);
    }

    private function makeMilestone(Project $project, Contract $contract, array $overrides = []): ContractProgrammeMilestone
    {
        return ContractProgrammeMilestone::create(array_merge([
            'project_id'  => $project->id,
            'contract_id' => $contract->id,
            'name'        => 'Practical Completion',
            'status'      => 'not_started',
        ], $overrides));
    }

    // ── Positive ──────────────────────────────────────────────────────────

    public function test_client_can_create_edit_and_delete_a_milestone_in_their_own_contract(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/contracts/{$contract->id}/programme", ['name' => 'Practical Completion']);
        $store->assertStatus(201);
        $id = $store->json('id');

        $update = $this->putJson("/api/programme/{$id}", ['status' => 'in_progress', 'progress_pct' => 40]);
        $update->assertStatus(200);
        $this->assertDatabaseHas('contract_programme_milestones', ['id' => $id, 'status' => 'in_progress']);

        $this->deleteJson("/api/programme/{$id}")->assertStatus(204);
        $this->assertSoftDeleted('contract_programme_milestones', ['id' => $id]);
    }

    public function test_client_can_view_project_and_trade_package_scoped_programme(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $this->makeMilestone($project, $contract);
        $tp = $this->makeTradePackage($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$project->id}/programme")->assertStatus(200);
        $this->getJson("/api/projects/{$project->id}/trade-packages/{$tp->id}/programme")->assertStatus(200);
    }

    public function test_client_can_create_a_trade_package_scoped_milestone(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $tp = $this->makeTradePackage($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$project->id}/trade-packages/{$tp->id}/programme", ['name' => 'Steel Frame Erected']);
        $response->assertStatus(201);
        $this->assertDatabaseHas('contract_programme_milestones', ['trade_package_id' => $tp->id, 'name' => 'Steel Frame Erected']);
    }

    // ── Negative: cross-tenant ────────────────────────────────────────────

    public function test_client_cannot_view_or_mutate_another_organisations_programme(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $contractB = $this->makeContract($projectB, $b['user']);
        $milestoneB = $this->makeMilestone($projectB, $contractB);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/contracts/{$contractB->id}/programme")->assertStatus(403);
        $this->getJson("/api/projects/{$projectB->id}/programme")->assertStatus(403);
        $this->postJson("/api/contracts/{$contractB->id}/programme", ['name' => 'Injected'])->assertStatus(403);
        $this->putJson("/api/programme/{$milestoneB->id}", ['name' => 'Hijacked'])->assertStatus(403);
        $this->deleteJson("/api/programme/{$milestoneB->id}")->assertStatus(403);
        $this->postJson("/api/contracts/{$contractB->id}/programme/seed-from-analysis")->assertStatus(403);
        $this->assertDatabaseMissing('contract_programme_milestones', ['name' => 'Injected']);
    }

    public function test_client_cannot_access_another_organisations_trade_package_programme_or_spoof_parent(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectA = $this->makeProject($a['org'], $a['user']);
        $projectB = $this->makeProject($b['org'], $b['user']);
        $tpB = $this->makeTradePackage($projectB, $b['user']);

        Sanctum::actingAs($a['user']);

        // Cross-org: blocked with 403.
        $this->getJson("/api/projects/{$projectB->id}/trade-packages/{$tpB->id}/programme")->assertStatus(403);
        $this->postJson("/api/projects/{$projectB->id}/trade-packages/{$tpB->id}/programme", ['name' => 'x'])->assertStatus(403);

        // Same-org but mismatched project/trade-package pairing: blocked with 404.
        $this->getJson("/api/projects/{$projectA->id}/trade-packages/{$tpB->id}/programme")->assertStatus(404);
    }
}
