<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Rfi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 3: RFIs. RfiController was already fully backend-safe — every
 * action (index/store/show/update/destroy) calls the shared authorize()
 * check. There is no dedicated issue/respond/close/reopen endpoint; all of
 * those are just `status` transitions through update(), which the frontend
 * already exposes unconditionally (r.status !== 'closed' gates the Respond
 * button, not a role). Delete has no status guard for any role today — not
 * a Client-specific gap, left as-is per "don't invent delete behaviour."
 */
class Batch3RfiTest extends TestCase
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

    private function makeRfi(Project $project, User $user, array $overrides = []): Rfi
    {
        static $n = 0;
        $n++;

        return Rfi::create(array_merge([
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $user->id,
            'rfi_number'      => $n,
            'subject'         => 'Clarify drainage detail',
            'status'          => 'open',
            'raised_date'     => now()->toDateString(),
        ], $overrides));
    }

    // ── Positive ──────────────────────────────────────────────────────────

    public function test_client_can_create_respond_close_and_reopen_an_rfi_in_their_own_project(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/projects/{$project->id}/rfis", ['subject' => 'Clarify drainage detail']);
        $store->assertStatus(201);
        $id = $store->json('id');

        $respond = $this->putJson("/api/rfis/{$id}", ['status' => 'responded', 'response' => 'Use detail D-04', 'responded_at' => now()->toDateString()]);
        $respond->assertStatus(200);
        $this->assertDatabaseHas('rfis', ['id' => $id, 'status' => 'responded']);

        $close = $this->putJson("/api/rfis/{$id}", ['status' => 'closed']);
        $close->assertStatus(200);
        $this->assertDatabaseHas('rfis', ['id' => $id, 'status' => 'closed']);

        $reopen = $this->putJson("/api/rfis/{$id}", ['status' => 'open']);
        $reopen->assertStatus(200);
        $this->assertDatabaseHas('rfis', ['id' => $id, 'status' => 'open']);
    }

    public function test_client_can_delete_an_rfi_in_their_own_project(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $rfi = $this->makeRfi($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $this->deleteJson("/api/rfis/{$rfi->id}")->assertStatus(204);
        $this->assertDatabaseMissing('rfis', ['id' => $rfi->id]);
    }

    // ── Negative: cross-tenant ────────────────────────────────────────────

    public function test_client_cannot_view_edit_or_delete_another_organisations_rfi(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $rfiB = $this->makeRfi($projectB, $b['user']);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/rfis/{$rfiB->id}")->assertStatus(403);
        $this->putJson("/api/rfis/{$rfiB->id}", ['subject' => 'Hijacked'])->assertStatus(403);
        $this->deleteJson("/api/rfis/{$rfiB->id}")->assertStatus(403);
    }

    public function test_client_cannot_create_an_rfi_under_another_organisations_project(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);

        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$projectB->id}/rfis", ['subject' => 'Injected']);
        $response->assertStatus(403);
        $this->assertDatabaseMissing('rfis', ['subject' => 'Injected']);
    }

    public function test_client_cannot_list_another_organisations_rfis(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $this->makeRfi($projectB, $b['user']);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$projectB->id}/rfis")->assertStatus(403);
    }
}
