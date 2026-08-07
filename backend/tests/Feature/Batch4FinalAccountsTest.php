<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\FinalAccount;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 4: Final Accounts.
 *
 * FinalAccountController was already fully backend-safe and well-built:
 * every action already checked org membership, isLocked()/canTransition()
 * guards were already correctly enforced for every role, and item
 * parent-checks (final_account_id match) were already present. No backend
 * authorisation changes were needed — only the frontend blanket canWrite
 * gate was replaced. This confirms the full lifecycle
 * (draft -> submitted -> under_review -> agreed -> signed ->
 * final_certificate_issued -> commercially_closed) and its guards remain
 * intact for Client.
 */
class Batch4FinalAccountsTest extends TestCase
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
            'contract_sum'    => 100000,
        ]);
    }

    private function makeFinalAccount(Project $project, Contract $contract, array $overrides = []): FinalAccount
    {
        static $n = 0;
        $n++;

        return FinalAccount::create(array_merge([
            'organization_id'       => $project->organization_id,
            'project_id'            => $project->id,
            'contract_id'           => $contract->id,
            'is_trade_package'      => false,
            'reference'             => "FA-{$n}",
            'status'                => FinalAccount::STATUS_DRAFT,
            'original_contract_sum' => 100000,
        ], $overrides));
    }

    // ── Positive: Client has full commercial authority in their own org ──

    public function test_client_can_create_a_final_account_and_progress_it_through_the_full_lifecycle(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/contracts/{$contract->id}/final-account");
        $store->assertStatus(201);
        $id = $store->json('id');

        $this->postJson("/api/final-accounts/{$id}/submit")->assertStatus(200);
        $this->postJson("/api/final-accounts/{$id}/start-review")->assertStatus(200);

        $agree = $this->postJson("/api/final-accounts/{$id}/agree");
        $agree->assertStatus(200);
        $this->assertDatabaseHas('final_accounts', ['id' => $id, 'status' => 'agreed']);

        $this->postJson("/api/final-accounts/{$id}/sign")->assertStatus(200);

        $issueCert = $this->postJson("/api/final-accounts/{$id}/issue-certificate");
        $issueCert->assertStatus(200);
        $this->assertDatabaseHas('final_accounts', ['id' => $id, 'status' => 'final_certificate_issued']);
        $this->assertNotNull(FinalAccount::find($id)->dispute_window_expires_at);

        $close = $this->postJson("/api/final-accounts/{$id}/close");
        $close->assertStatus(200);
        $this->assertDatabaseHas('final_accounts', ['id' => $id, 'status' => 'commercially_closed']);
    }

    public function test_client_can_revise_a_submitted_final_account_back_to_draft(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $fa = $this->makeFinalAccount($project, $contract, ['status' => FinalAccount::STATUS_SUBMITTED]);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/final-accounts/{$fa->id}/revise");
        $response->assertStatus(200);
        $this->assertDatabaseHas('final_accounts', ['id' => $fa->id, 'status' => 'draft']);
    }

    public function test_client_can_add_edit_and_remove_items_on_an_unlocked_final_account(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $fa = $this->makeFinalAccount($project, $contract);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/final-accounts/{$fa->id}/items", [
            'category' => 'loss_and_expense', 'description' => 'Prolongation costs', 'amount' => 2500,
        ]);
        $store->assertStatus(201);
        $itemId = $store->json('id');

        $this->putJson("/api/final-accounts/{$fa->id}/items/{$itemId}", ['amount' => 3000])->assertStatus(200);
        $this->deleteJson("/api/final-accounts/{$fa->id}/items/{$itemId}")->assertStatus(200);
    }

    // ── Workflow-state protection ─────────────────────────────────────────

    public function test_final_account_lifecycle_transitions_are_sequence_enforced(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $fa = $this->makeFinalAccount($project, $contract); // draft
        Sanctum::actingAs($a['user']);

        // Cannot agree before submit/review.
        $this->postJson("/api/final-accounts/{$fa->id}/agree")->assertStatus(422);
        // Cannot sign before agreement.
        $this->postJson("/api/final-accounts/{$fa->id}/sign")->assertStatus(422);
        // Cannot issue certificate before signing.
        $this->postJson("/api/final-accounts/{$fa->id}/issue-certificate")->assertStatus(422);
    }

    public function test_a_locked_final_account_cannot_be_edited_or_have_items_changed(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $fa = $this->makeFinalAccount($project, $contract, ['status' => FinalAccount::STATUS_AGREED]);
        Sanctum::actingAs($a['user']);

        $this->putJson("/api/final-accounts/{$fa->id}", ['notes' => 'Hijacked'])->assertStatus(422);
        $this->postJson("/api/final-accounts/{$fa->id}/items", ['category' => 'other', 'description' => 'x', 'amount' => 1])->assertStatus(422);
    }

    public function test_a_final_certificate_can_only_be_generated_once_issued(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $fa = $this->makeFinalAccount($project, $contract, ['status' => FinalAccount::STATUS_AGREED]);
        Sanctum::actingAs($a['user']);

        $this->postJson("/api/final-accounts/{$fa->id}/generate-certificate")->assertStatus(422);
    }

    public function test_cannot_create_a_second_final_account_for_the_same_contract(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $this->makeFinalAccount($project, $contract);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/contracts/{$contract->id}/final-account");
        $response->assertStatus(422);
    }

    // ── Negative: cross-tenant ────────────────────────────────────────────

    public function test_client_cannot_view_edit_progress_or_add_items_to_another_organisations_final_account(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $contractB = $this->makeContract($projectB, $b['user']);
        $faB = $this->makeFinalAccount($projectB, $contractB);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/final-accounts/{$faB->id}")->assertStatus(403);
        $this->putJson("/api/final-accounts/{$faB->id}", ['notes' => 'Hijacked'])->assertStatus(403);
        $this->postJson("/api/final-accounts/{$faB->id}/submit")->assertStatus(403);
        $this->postJson("/api/final-accounts/{$faB->id}/items", ['category' => 'other', 'description' => 'x', 'amount' => 1])->assertStatus(403);
    }

    public function test_client_cannot_create_a_final_account_for_another_organisations_contract(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $contractB = $this->makeContract($projectB, $b['user']);

        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/contracts/{$contractB->id}/final-account");
        $response->assertStatus(403);
        $this->assertDatabaseMissing('final_accounts', ['contract_id' => $contractB->id]);
    }

    public function test_client_cannot_address_an_item_using_a_mismatched_final_account_id_within_their_own_organisation(): void
    {
        // IDOR check: an item genuinely belonging to Final Account 1 must not
        // be editable/removable through Final Account 2's ID, even though
        // both belong to the same (authorised) organisation.
        $a = $this->makeOrgAndUser('a');
        $projectOne = $this->makeProject($a['org'], $a['user']);
        $contractOne = $this->makeContract($projectOne, $a['user']);
        $faOne = $this->makeFinalAccount($projectOne, $contractOne);

        $projectTwo = $this->makeProject($a['org'], $a['user']);
        $contractTwo = $this->makeContract($projectTwo, $a['user']);
        $faTwo = $this->makeFinalAccount($projectTwo, $contractTwo);

        Sanctum::actingAs($a['user']);
        $item = $this->postJson("/api/final-accounts/{$faOne->id}/items", [
            'category' => 'other', 'description' => 'FA1 item', 'amount' => 100,
        ])->json();

        $this->putJson("/api/final-accounts/{$faTwo->id}/items/{$item['id']}", ['amount' => 999])->assertStatus(422);
        $this->deleteJson("/api/final-accounts/{$faTwo->id}/items/{$item['id']}")->assertStatus(422);
    }

    // ── Trade-package Final Account routes — real production bug, zero prior
    //    coverage (Error Messaging & Recovery UX initiative, live-discovered) ──
    //
    // showForTradePackage()/storeForTradePackage() previously omitted
    // `Project $project` from their method signature despite the route being
    // `projects/{project}/trade-packages/{tradePackage}/final-account`.
    // Laravel's controller-dependency-splicing (ResolvesRouteDependencies::
    // resolveMethodDependencies(), which uses array_splice on the route's
    // string-keyed parameter array) re-indexes that array numerically as
    // soon as it splices in the container-resolved Request instance,
    // silently misaligning the remaining positional arguments — the
    // controller was actually invoked as
    // storeForTradePackage($request, '1') with the route's {project} value
    // ('1', a string) landing in the $tradePackage slot, and the real
    // TradePackage instance silently dropped as an unused extra argument.
    // Confirmed against a real, unmodified sibling controller
    // (ProgrammeMilestoneController::indexByTradePackage(), which DOES
    // declare Project $project and works correctly) before diagnosing this
    // as the root cause, not merely reproducing the symptom.

    private function makeTradePackage(Project $project, array $overrides = []): \App\Models\TradePackage
    {
        return \App\Models\TradePackage::create(array_merge([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'name' => 'Concrete Frame', 'slug' => 'concrete-frame-' . uniqid(), 'status' => 'active',
        ], $overrides));
    }

    public function test_client_can_create_a_final_account_for_their_own_trade_package(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $tradePackage = $this->makeTradePackage($project);

        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$project->id}/trade-packages/{$tradePackage->id}/final-account");

        $response->assertStatus(201);
        $this->assertDatabaseHas('final_accounts', [
            'trade_package_id' => $tradePackage->id,
            'project_id'       => $project->id,
            'is_trade_package' => true,
        ]);
    }

    public function test_client_can_view_a_trade_packages_final_account_status(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $tradePackage = $this->makeTradePackage($project);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$project->id}/trade-packages/{$tradePackage->id}/final-account")
            ->assertStatus(200)->assertJson(['exists' => false]);

        $this->postJson("/api/projects/{$project->id}/trade-packages/{$tradePackage->id}/final-account")
            ->assertStatus(201);

        $this->getJson("/api/projects/{$project->id}/trade-packages/{$tradePackage->id}/final-account")
            ->assertStatus(200)->assertJson(['exists' => true]);
    }

    public function test_a_trade_package_id_belonging_to_a_different_project_returns_not_found(): void
    {
        // The other half of the fix: authorizeProjectPackage() (matching the
        // platform-wide convention every sibling trade-package-scoped
        // controller already uses) also verifies the trade package
        // genuinely belongs to the project named in the URL — the previous
        // authorizeTradePackage() only checked organisation membership.
        $a = $this->makeOrgAndUser('a');
        $projectOne = $this->makeProject($a['org'], $a['user']);
        $projectTwo = $this->makeProject($a['org'], $a['user']);
        $tradePackage = $this->makeTradePackage($projectOne);

        Sanctum::actingAs($a['user']);

        $this->postJson("/api/projects/{$projectTwo->id}/trade-packages/{$tradePackage->id}/final-account")
            ->assertStatus(404);
        $this->getJson("/api/projects/{$projectTwo->id}/trade-packages/{$tradePackage->id}/final-account")
            ->assertStatus(404);
    }

    public function test_client_cannot_create_a_final_account_for_another_organisations_trade_package(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $tradePackageB = $this->makeTradePackage($projectB);

        Sanctum::actingAs($a['user']);

        $this->postJson("/api/projects/{$projectB->id}/trade-packages/{$tradePackageB->id}/final-account")
            ->assertStatus(403);
        $this->assertDatabaseMissing('final_accounts', ['trade_package_id' => $tradePackageB->id]);
    }
}
