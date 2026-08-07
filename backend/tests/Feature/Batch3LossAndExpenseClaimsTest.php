<?php

namespace Tests\Feature;

use App\Models\LossAndExpenseClaim;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 3: Loss & Expense Claims.
 *
 * Product decision: Client has decision authority (agree/reject) within
 * their own organisation. Found and fixed the same parent-mismatch pattern
 * as the other Batch 3 modules. decide()'s Final Account auto-seeding
 * side-effect (only when an unlocked Final Account already exists) is
 * left untouched — it's an existing cross-module behaviour, not something
 * this batch changes, and Final Account itself remains Batch 4 scope.
 */
class Batch3LossAndExpenseClaimsTest extends TestCase
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

    private function makeClaim(Project $project, User $user, array $overrides = []): LossAndExpenseClaim
    {
        static $n = 0;
        $n++;

        return LossAndExpenseClaim::create(array_merge([
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $user->id,
            'claim_number'    => $n,
            'title'           => 'Prolongation costs',
            'status'          => 'submitted',
        ], $overrides));
    }

    // ── Positive: Client has decision authority in their own org ─────────

    public function test_client_can_create_edit_and_agree_a_claim_in_their_own_project(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/projects/{$project->id}/loss-and-expense-claims", [
            'title' => 'Prolongation costs', 'amount_claimed' => 5000,
        ]);
        $store->assertStatus(201);
        $id = $store->json('id');

        $this->putJson("/api/projects/{$project->id}/loss-and-expense-claims/{$id}", ['status' => 'under_assessment'])
            ->assertStatus(200);

        $decide = $this->postJson("/api/projects/{$project->id}/loss-and-expense-claims/{$id}/decide", [
            'status' => 'agreed', 'amount_agreed' => 4200,
        ]);
        $decide->assertStatus(200);
        $this->assertDatabaseHas('loss_and_expense_claims', ['id' => $id, 'status' => 'agreed', 'amount_agreed' => 4200]);
    }

    public function test_client_can_reject_a_claim(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $claim = $this->makeClaim($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$project->id}/loss-and-expense-claims/{$claim->id}/decide", ['status' => 'rejected']);
        $response->assertStatus(200);
        $this->assertDatabaseHas('loss_and_expense_claims', ['id' => $claim->id, 'status' => 'rejected']);
    }

    public function test_decide_requires_amount_agreed_when_agreeing(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $claim = $this->makeClaim($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$project->id}/loss-and-expense-claims/{$claim->id}/decide", ['status' => 'agreed']);
        $response->assertStatus(422);
    }

    public function test_client_can_delete_a_claim(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $claim = $this->makeClaim($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $this->deleteJson("/api/projects/{$project->id}/loss-and-expense-claims/{$claim->id}")->assertStatus(204);
        $this->assertSoftDeleted('loss_and_expense_claims', ['id' => $claim->id]);
    }

    // ── Negative: cross-tenant ────────────────────────────────────────────

    public function test_client_cannot_view_edit_decide_or_delete_another_organisations_claim(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $claimB = $this->makeClaim($projectB, $b['user']);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$projectB->id}/loss-and-expense-claims/{$claimB->id}")->assertStatus(403);
        $this->putJson("/api/projects/{$projectB->id}/loss-and-expense-claims/{$claimB->id}", ['title' => 'Hijacked'])->assertStatus(403);
        $this->postJson("/api/projects/{$projectB->id}/loss-and-expense-claims/{$claimB->id}/decide", ['status' => 'agreed', 'amount_agreed' => 999])->assertStatus(403);
        $this->deleteJson("/api/projects/{$projectB->id}/loss-and-expense-claims/{$claimB->id}")->assertStatus(403);

        $this->assertDatabaseHas('loss_and_expense_claims', ['id' => $claimB->id, 'status' => 'submitted']);
    }

    public function test_client_cannot_decide_a_claim_using_a_mismatched_same_organisation_project_id(): void
    {
        $a = $this->makeOrgAndUser('a');
        $projectOne = $this->makeProject($a['org'], $a['user']);
        $projectTwo = $this->makeProject($a['org'], $a['user']);
        $claim = $this->makeClaim($projectOne, $a['user']);

        Sanctum::actingAs($a['user']);

        $this->postJson("/api/projects/{$projectTwo->id}/loss-and-expense-claims/{$claim->id}/decide", ['status' => 'agreed', 'amount_agreed' => 100])
            ->assertStatus(404);
    }

    // ── Error Messaging & Recovery UX, Batch 5 ──────────────────────────────
    //
    // createFinalAccountItemIfPossible() was previously unguarded — any
    // failure seeding the Final Account item would have surfaced as a
    // failure of decide() itself, even though the claim's own agreed/
    // rejected decision had already been saved. Now wrapped in try/catch
    // (mirrors NotificationServiceResilienceTest's already-proven pattern).
    // This test is the happy-path regression guard: proving the try/catch
    // refactor didn't accidentally also swallow the successful case.

    public function test_agreeing_a_claim_still_seeds_the_linked_final_accounts_item(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = \App\Models\Contract::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'created_by' => $a['user']->id, 'title' => 'Main Contract', 'type' => 'main_contract', 'status' => 'active',
        ]);
        $finalAccount = \App\Models\FinalAccount::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'contract_id' => $contract->id, 'created_by' => $a['user']->id,
            'title' => 'Final Account', 'status' => 'draft',
        ]);
        $claim = $this->makeClaim($project, $a['user'], ['contract_id' => $contract->id, 'status' => 'submitted']);

        Sanctum::actingAs($a['user']);

        $this->postJson("/api/projects/{$project->id}/loss-and-expense-claims/{$claim->id}/decide", [
            'status' => 'agreed', 'amount_agreed' => 4500,
        ])->assertStatus(200);

        $this->assertDatabaseHas('final_account_items', [
            'final_account_id' => $finalAccount->id,
            'source_type'      => 'loss_and_expense_claim',
            'source_id'        => $claim->id,
            'amount'           => 4500,
        ]);
        $this->assertNotNull($claim->fresh()->final_account_item_id);
    }
}
