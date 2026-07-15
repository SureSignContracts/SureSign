<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\SiteDiary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 3: Site Reports. This module is the "Site Reports" UI over the
 * backend's SiteDiaryController/site-diaries table (no separate
 * SiteReportController exists) — correcting a mislabel from the original
 * Phase 1/2 audit, which had recorded this as already fully open with no
 * frontend gate. In fact site-reports/page.tsx does use
 * useProjectPermissions()'s blanket canWrite, so it needed the same
 * frontend fix as the other Batch 3 modules.
 *
 * Also found and fixed the same parent-mismatch gap as Meetings:
 * show/update/destroy checked the diary's own organisation but never that
 * it belongs to the {project} in the URL.
 */
class Batch3SiteReportsTest extends TestCase
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

    private function makeDiary(Project $project, User $user, array $overrides = []): SiteDiary
    {
        return SiteDiary::create(array_merge([
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $user->id,
            'diary_date'      => now()->toDateString(),
            'status'          => 'draft',
        ], $overrides));
    }

    // ── Positive ──────────────────────────────────────────────────────────

    public function test_client_can_create_edit_and_delete_a_site_report_in_their_own_project(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/projects/{$project->id}/site-diaries", [
            'diary_date' => now()->toDateString(), 'weather' => 'Sunny', 'workers_on_site' => 12,
        ]);
        $store->assertStatus(201);
        $id = $store->json('id');

        $update = $this->putJson("/api/projects/{$project->id}/site-diaries/{$id}", ['status' => 'submitted']);
        $update->assertStatus(200);
        $this->assertDatabaseHas('site_diaries', ['id' => $id, 'status' => 'submitted']);

        $this->deleteJson("/api/projects/{$project->id}/site-diaries/{$id}")->assertStatus(204);
        $this->assertDatabaseMissing('site_diaries', ['id' => $id]);
    }

    // ── Negative: cross-tenant ────────────────────────────────────────────

    public function test_client_cannot_view_edit_or_delete_another_organisations_site_report(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $diaryB = $this->makeDiary($projectB, $b['user']);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$projectB->id}/site-diaries/{$diaryB->id}")->assertStatus(403);
        $this->putJson("/api/projects/{$projectB->id}/site-diaries/{$diaryB->id}", ['issues' => 'Hijacked'])->assertStatus(403);
        $this->deleteJson("/api/projects/{$projectB->id}/site-diaries/{$diaryB->id}")->assertStatus(403);
    }

    public function test_client_cannot_address_a_site_report_using_a_mismatched_same_organisation_project_id(): void
    {
        $a = $this->makeOrgAndUser('a');
        $projectOne = $this->makeProject($a['org'], $a['user']);
        $projectTwo = $this->makeProject($a['org'], $a['user']);
        $diary = $this->makeDiary($projectOne, $a['user']);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$projectTwo->id}/site-diaries/{$diary->id}")->assertStatus(404);
        $this->putJson("/api/projects/{$projectTwo->id}/site-diaries/{$diary->id}", ['issues' => 'x'])->assertStatus(404);
        $this->deleteJson("/api/projects/{$projectTwo->id}/site-diaries/{$diary->id}")->assertStatus(404);
    }
}
