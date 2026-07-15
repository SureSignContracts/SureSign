<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Variation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 3: Variations.
 *
 * The full workflow (draft -> submitted -> instructed -> quoted -> assessed
 * -> approved|rejected -> resubmit) was already backend-safe for every
 * action endpoint (submit/instruct/quote/assess/approve/reject/resubmit all
 * call authorizeVariation()). Two real gaps were found and fixed here:
 * show() and destroy() had NO authorization check at all — any
 * authenticated user of any organisation could view or attempt to delete
 * another organisation's variation by ID. This is a missing-authorisation
 * bug, not a Client-vs-Admin restriction — it affected every non-admin role
 * equally before this fix.
 */
class Batch3VariationsTest extends TestCase
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

    private function makeVariation(Project $project, Contract $contract, User $user, array $overrides = []): Variation
    {
        static $n = 0;
        $n++;

        return Variation::create(array_merge([
            'project_id'       => $project->id,
            'contract_id'      => $contract->id,
            'organization_id'  => $project->organization_id,
            'created_by'       => $user->id,
            'variation_number' => $n,
            'title'            => 'Extra groundworks',
            'status'           => Variation::STATUS_DRAFT,
        ], $overrides));
    }

    // ── Positive: own organisation ───────────────────────────────────────

    public function test_client_can_run_the_full_variation_workflow_in_their_own_project(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/contracts/{$contract->id}/variations", ['title' => 'Extra groundworks', 'type' => 'instruction']);
        $store->assertStatus(201);
        $id = $store->json('id');

        $this->getJson("/api/variations/{$id}")->assertStatus(200);
        $this->postJson("/api/variations/{$id}/submit")->assertStatus(200);
        $this->postJson("/api/variations/{$id}/instruct")->assertStatus(200);
        $this->postJson("/api/variations/{$id}/quote", ['quoted_amount' => 1000])->assertStatus(200);
        $this->postJson("/api/variations/{$id}/assess", ['agreed_amount' => 900])->assertStatus(200);
        $this->postJson("/api/variations/{$id}/approve")->assertStatus(200);

        $this->assertDatabaseHas('variations', ['id' => $id, 'status' => Variation::STATUS_APPROVED]);
    }

    public function test_client_can_reject_and_resubmit_a_variation(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $variation = $this->makeVariation($project, $contract, $a['user'], ['status' => Variation::STATUS_ASSESSED]);
        Sanctum::actingAs($a['user']);

        $this->postJson("/api/variations/{$variation->id}/reject", ['rejection_reason' => 'Price too high'])->assertStatus(200);
        $this->assertDatabaseHas('variations', ['id' => $variation->id, 'status' => Variation::STATUS_REJECTED]);

        $this->postJson("/api/variations/{$variation->id}/resubmit")->assertStatus(200);
        $this->assertDatabaseHas('variations', ['id' => $variation->id, 'status' => Variation::STATUS_SUBMITTED]);
    }

    public function test_client_can_delete_a_draft_variation(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $variation = $this->makeVariation($project, $contract, $a['user']);
        Sanctum::actingAs($a['user']);

        $this->deleteJson("/api/variations/{$variation->id}")->assertStatus(204);
        $this->assertDatabaseMissing('variations', ['id' => $variation->id]);
    }

    // ── Workflow-state protection ─────────────────────────────────────────

    public function test_approved_variation_cannot_have_its_agreed_amount_changed_directly(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $variation = $this->makeVariation($project, $contract, $a['user'], ['status' => Variation::STATUS_APPROVED, 'agreed_amount' => 1000]);
        Sanctum::actingAs($a['user']);

        $response = $this->putJson("/api/variations/{$variation->id}", ['agreed_amount' => 5000]);
        $response->assertStatus(422);
        $this->assertDatabaseHas('variations', ['id' => $variation->id, 'agreed_amount' => 1000]);
    }

    public function test_variation_cannot_be_deleted_once_included_in_a_payment_application(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $variation = $this->makeVariation($project, $contract, $a['user']);

        \App\Models\PaymentApplicationVariation::create([
            'payment_application_id' => \App\Models\PaymentApplication::create([
                'project_id'          => $project->id,
                'contract_id'         => $contract->id,
                'organization_id'     => $project->organization_id,
                'created_by'          => $a['user']->id,
                'application_number'  => 1,
                'status'              => 'draft',
                'application_date'    => now()->toDateString(),
                'gross_valuation'     => 1000,
            ])->id,
            'variation_id'                  => $variation->id,
            'variation_number_at_inclusion' => $variation->variation_number,
            'title_at_inclusion'            => $variation->title,
            'amount_at_inclusion'           => 500,
        ]);

        Sanctum::actingAs($a['user']);

        $response = $this->deleteJson("/api/variations/{$variation->id}");
        $response->assertStatus(422);
        $this->assertDatabaseHas('variations', ['id' => $variation->id]);
    }

    public function test_variation_workflow_transitions_are_sequence_enforced(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        $variation = $this->makeVariation($project, $contract, $a['user']); // draft

        Sanctum::actingAs($a['user']);

        // Cannot instruct before submitting.
        $this->postJson("/api/variations/{$variation->id}/instruct")->assertStatus(422);
        // Cannot approve before assessment.
        $this->postJson("/api/variations/{$variation->id}/approve")->assertStatus(422);
    }

    // ── Negative: cross-tenant ────────────────────────────────────────────

    public function test_client_cannot_view_edit_or_delete_another_organisations_variation(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $contractB = $this->makeContract($projectB, $b['user']);
        $variationB = $this->makeVariation($projectB, $contractB, $b['user']);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/variations/{$variationB->id}")->assertStatus(403);
        $this->putJson("/api/variations/{$variationB->id}", ['title' => 'Hijacked'])->assertStatus(403);
        $this->deleteJson("/api/variations/{$variationB->id}")->assertStatus(403);
    }

    public function test_client_cannot_progress_another_organisations_variation_workflow(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $contractB = $this->makeContract($projectB, $b['user']);
        $variationB = $this->makeVariation($projectB, $contractB, $b['user']);

        Sanctum::actingAs($a['user']);

        $this->postJson("/api/variations/{$variationB->id}/submit")->assertStatus(403);
        $this->postJson("/api/variations/{$variationB->id}/approve")->assertStatus(403);
        $this->postJson("/api/variations/{$variationB->id}/reject", ['rejection_reason' => 'x'])->assertStatus(403);
    }

    public function test_client_cannot_create_a_variation_under_another_organisations_contract(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $contractB = $this->makeContract($projectB, $b['user']);

        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/contracts/{$contractB->id}/variations", ['title' => 'Injected', 'type' => 'instruction']);
        $response->assertStatus(403);
        $this->assertDatabaseMissing('variations', ['title' => 'Injected']);
    }
}
