<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\EotRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 3: EOT Requests.
 *
 * Product decision: Client has decision authority (grant/refuse) on EOT
 * requests within their own organisation — this is intentional, not a gap.
 * Found and fixed the same parent-mismatch pattern as Delay Events/Meetings.
 * The decide() action's revised-completion-date calculation and required
 * granted-days validation are untouched.
 */
class Batch3EotRequestsTest extends TestCase
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

    private function makeContract(Project $project, User $user, array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $user->id,
            'title'           => 'Main Contract',
            'type'            => 'main_contract',
            'status'          => 'active',
            'completion_date' => now()->addMonths(6)->toDateString(),
        ], $overrides));
    }

    private function makeEot(Project $project, User $user, array $overrides = []): EotRequest
    {
        static $n = 0;
        $n++;

        return EotRequest::create(array_merge([
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $user->id,
            'eot_number'      => $n,
            'title'           => 'Adverse weather',
            'notice_date'     => now()->toDateString(),
            'status'          => 'submitted',
        ], $overrides));
    }

    // ── Positive: Client has decision authority in their own org ─────────

    public function test_client_can_create_and_decide_an_eot_request_in_their_own_project(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/projects/{$project->id}/eot-requests", [
            'title' => 'Adverse weather', 'notice_date' => now()->toDateString(), 'contract_id' => $contract->id,
        ]);
        $store->assertStatus(201);
        $id = $store->json('id');

        $review = $this->putJson("/api/projects/{$project->id}/eot-requests/{$id}", ['status' => 'under_assessment']);
        $review->assertStatus(200);

        $decide = $this->postJson("/api/projects/{$project->id}/eot-requests/{$id}/decide", [
            'status' => 'granted', 'days_granted' => 10,
        ]);
        $decide->assertStatus(200);
        $this->assertDatabaseHas('eot_requests', [
            'id' => $id, 'status' => 'granted', 'days_granted' => 10, 'decided_by' => $a['user']->id,
        ]);
    }

    public function test_client_can_refuse_an_eot_request(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $eot = $this->makeEot($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$project->id}/eot-requests/{$eot->id}/decide", ['status' => 'refused']);
        $response->assertStatus(200);
        $this->assertDatabaseHas('eot_requests', ['id' => $eot->id, 'status' => 'refused', 'days_granted' => 0]);
    }

    public function test_decide_requires_days_granted_when_granting(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $eot = $this->makeEot($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$project->id}/eot-requests/{$eot->id}/decide", ['status' => 'granted']);
        $response->assertStatus(422);
    }

    public function test_client_can_delete_an_eot_request(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $eot = $this->makeEot($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $this->deleteJson("/api/projects/{$project->id}/eot-requests/{$eot->id}")->assertStatus(204);
        $this->assertDatabaseMissing('eot_requests', ['id' => $eot->id]);
    }

    // ── Negative: cross-tenant ────────────────────────────────────────────

    public function test_client_cannot_view_edit_decide_or_delete_another_organisations_eot_request(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $eotB = $this->makeEot($projectB, $b['user']);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$projectB->id}/eot-requests/{$eotB->id}")->assertStatus(403);
        $this->putJson("/api/projects/{$projectB->id}/eot-requests/{$eotB->id}", ['title' => 'Hijacked'])->assertStatus(403);
        $this->postJson("/api/projects/{$projectB->id}/eot-requests/{$eotB->id}/decide", ['status' => 'granted', 'days_granted' => 999])->assertStatus(403);
        $this->deleteJson("/api/projects/{$projectB->id}/eot-requests/{$eotB->id}")->assertStatus(403);

        $this->assertDatabaseHas('eot_requests', ['id' => $eotB->id, 'status' => 'submitted']);
    }

    public function test_client_cannot_decide_an_eot_request_using_a_mismatched_same_organisation_project_id(): void
    {
        $a = $this->makeOrgAndUser('a');
        $projectOne = $this->makeProject($a['org'], $a['user']);
        $projectTwo = $this->makeProject($a['org'], $a['user']);
        $eot = $this->makeEot($projectOne, $a['user']);

        Sanctum::actingAs($a['user']);

        $this->postJson("/api/projects/{$projectTwo->id}/eot-requests/{$eot->id}/decide", ['status' => 'granted', 'days_granted' => 5])
            ->assertStatus(404);
        $this->assertDatabaseHas('eot_requests', ['id' => $eot->id, 'status' => 'submitted']);
    }
}
