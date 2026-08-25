<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\QaReport;
use App\Models\Snag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pre-Commit Nested Resource Integrity Check (P3 follow-up) — Laravel's
 * default nested apiResource() routing (no ->scoped()) binds {project} and
 * {snagging}/{qaReport} independently by primary key; it does NOT verify
 * the child's own project_id matches the URL's {project} on its own. This
 * is exactly the vulnerability class this codebase's own established
 * pattern already closes for sibling controllers (SiteDiaryController,
 * MeetingMinutesController, RiskController, etc.) — Snag/QaReport were the
 * outliers missing it. Fixed via authorizeProjectSnag()/
 * authorizeProjectQaReport().
 *
 * This is deliberately a SAME-ORGANISATION mismatch in every test here —
 * organisation-level authorize() alone would let the actor through, so it
 * cannot be the thing proving this invariant.
 */
class SnagQaProjectParentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgAndTwoProjects(string $suffix): array
    {
        $org = Organization::create(['name' => "Org {$suffix}", 'slug' => "org-{$suffix}"]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $projectA = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => "Project A {$suffix}"]);
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => "Project B {$suffix}"]);

        return [$org, $user, $projectA, $projectB];
    }

    // ── Snag ──────────────────────────────────────────────────────────────

    public function test_snag_update_with_correct_parent_project_succeeds(): void
    {
        [, $user, $projectA] = $this->makeOrgAndTwoProjects('s1');
        $snag = Snag::create([
            'organization_id' => $projectA->organization_id, 'project_id' => $projectA->id,
            'created_by' => $user->id, 'snag_number' => 1, 'title' => 'Original',
        ]);

        Sanctum::actingAs($user);
        $this->putJson("/api/projects/{$projectA->id}/snagging/{$snag->id}", ['title' => 'Updated'])
            ->assertStatus(200)->assertJsonPath('title', 'Updated');
    }

    public function test_snag_update_with_wrong_project_in_the_same_organisation_is_rejected(): void
    {
        [, $user, $projectA, $projectB] = $this->makeOrgAndTwoProjects('s2');
        $snagB = Snag::create([
            'organization_id' => $projectB->organization_id, 'project_id' => $projectB->id,
            'created_by' => $user->id, 'snag_number' => 1, 'title' => 'Belongs to B',
        ]);

        Sanctum::actingAs($user);
        $this->putJson("/api/projects/{$projectA->id}/snagging/{$snagB->id}", ['title' => 'Hijacked'])
            ->assertStatus(404);

        $this->assertSame('Belongs to B', $snagB->fresh()->title, 'A rejected mismatched-parent update must not mutate the record.');
    }

    public function test_snag_show_with_wrong_project_in_the_same_organisation_is_rejected(): void
    {
        [, $user, $projectA, $projectB] = $this->makeOrgAndTwoProjects('s3');
        $snagB = Snag::create([
            'organization_id' => $projectB->organization_id, 'project_id' => $projectB->id,
            'created_by' => $user->id, 'snag_number' => 1, 'title' => 'Belongs to B',
        ]);

        Sanctum::actingAs($user);
        $this->getJson("/api/projects/{$projectA->id}/snagging/{$snagB->id}")->assertStatus(404);
    }

    public function test_snag_destroy_with_wrong_project_in_the_same_organisation_is_rejected(): void
    {
        [, $user, $projectA, $projectB] = $this->makeOrgAndTwoProjects('s4');
        $snagB = Snag::create([
            'organization_id' => $projectB->organization_id, 'project_id' => $projectB->id,
            'created_by' => $user->id, 'snag_number' => 1, 'title' => 'Belongs to B',
        ]);

        Sanctum::actingAs($user);
        $this->deleteJson("/api/projects/{$projectA->id}/snagging/{$snagB->id}")->assertStatus(404);

        $this->assertNotNull(Snag::find($snagB->id), 'A rejected mismatched-parent delete must not remove the record.');
    }

    public function test_snag_wrong_organisation_remains_rejected(): void
    {
        [$org, $owner, $projectA] = $this->makeOrgAndTwoProjects('s5');
        $ownSnag = Snag::create([
            'organization_id' => $org->id, 'project_id' => $projectA->id,
            'created_by' => $owner->id, 'snag_number' => 1, 'title' => 'Belongs to Org',
        ]);
        $foreignOrg = Organization::create(['name' => 'Foreign Org s5', 'slug' => 'foreign-org-s5']);
        $foreignUser = User::factory()->create(['organization_id' => $foreignOrg->id, 'is_active' => true]);

        Sanctum::actingAs($foreignUser);
        // A foreign-org actor is blocked by authorize() itself (403),
        // before parent-child integrity is even reached.
        $this->putJson("/api/projects/{$projectA->id}/snagging/{$ownSnag->id}", ['title' => 'Hijacked'])
            ->assertStatus(403);
    }

    public function test_admin_does_not_bypass_snag_parent_child_integrity(): void
    {
        [, , $projectA, $projectB] = $this->makeOrgAndTwoProjects('s6');
        $creator = User::factory()->create(['organization_id' => $projectB->organization_id, 'is_active' => true]);
        $snagB = Snag::create([
            'organization_id' => $projectB->organization_id, 'project_id' => $projectB->id,
            'created_by' => $creator->id, 'snag_number' => 1, 'title' => 'Belongs to B',
        ]);
        $admin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));

        Sanctum::actingAs($admin);
        // Admin's platform-wide EDIT authority lets them past authorize()'s
        // organisation check, but must not let them past the parent-child
        // integrity check — that represents the URL's own claim, not a
        // permission.
        $this->putJson("/api/projects/{$projectA->id}/snagging/{$snagB->id}", ['title' => 'Hijacked'])
            ->assertStatus(404);

        $this->assertSame('Belongs to B', $snagB->fresh()->title);
    }

    // ── QA Report ─────────────────────────────────────────────────────────

    public function test_qa_report_update_with_correct_parent_project_succeeds(): void
    {
        [, $user, $projectA] = $this->makeOrgAndTwoProjects('q1');
        $report = QaReport::create([
            'organization_id' => $projectA->organization_id, 'project_id' => $projectA->id,
            'created_by' => $user->id, 'report_number' => 1, 'title' => 'Original',
        ]);

        Sanctum::actingAs($user);
        $this->putJson("/api/projects/{$projectA->id}/qa-reports/{$report->id}", ['title' => 'Updated'])
            ->assertStatus(200)->assertJsonPath('title', 'Updated');
    }

    public function test_qa_report_update_with_wrong_project_in_the_same_organisation_is_rejected(): void
    {
        [, $user, $projectA, $projectB] = $this->makeOrgAndTwoProjects('q2');
        $reportB = QaReport::create([
            'organization_id' => $projectB->organization_id, 'project_id' => $projectB->id,
            'created_by' => $user->id, 'report_number' => 1, 'title' => 'Belongs to B',
        ]);

        Sanctum::actingAs($user);
        $this->putJson("/api/projects/{$projectA->id}/qa-reports/{$reportB->id}", ['title' => 'Hijacked'])
            ->assertStatus(404);

        $this->assertSame('Belongs to B', $reportB->fresh()->title);
    }

    public function test_qa_report_show_with_wrong_project_in_the_same_organisation_is_rejected(): void
    {
        [, $user, $projectA, $projectB] = $this->makeOrgAndTwoProjects('q3');
        $reportB = QaReport::create([
            'organization_id' => $projectB->organization_id, 'project_id' => $projectB->id,
            'created_by' => $user->id, 'report_number' => 1, 'title' => 'Belongs to B',
        ]);

        Sanctum::actingAs($user);
        $this->getJson("/api/projects/{$projectA->id}/qa-reports/{$reportB->id}")->assertStatus(404);
    }

    public function test_qa_report_destroy_with_wrong_project_in_the_same_organisation_is_rejected(): void
    {
        [, $user, $projectA, $projectB] = $this->makeOrgAndTwoProjects('q4');
        $reportB = QaReport::create([
            'organization_id' => $projectB->organization_id, 'project_id' => $projectB->id,
            'created_by' => $user->id, 'report_number' => 1, 'title' => 'Belongs to B',
        ]);

        Sanctum::actingAs($user);
        $this->deleteJson("/api/projects/{$projectA->id}/qa-reports/{$reportB->id}")->assertStatus(404);

        $this->assertNotNull(QaReport::find($reportB->id));
    }

    public function test_admin_does_not_bypass_qa_report_parent_child_integrity(): void
    {
        [, , $projectA, $projectB] = $this->makeOrgAndTwoProjects('q5');
        $creator = User::factory()->create(['organization_id' => $projectB->organization_id, 'is_active' => true]);
        $reportB = QaReport::create([
            'organization_id' => $projectB->organization_id, 'project_id' => $projectB->id,
            'created_by' => $creator->id, 'report_number' => 1, 'title' => 'Belongs to B',
        ]);
        $admin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));

        Sanctum::actingAs($admin);
        $this->putJson("/api/projects/{$projectA->id}/qa-reports/{$reportB->id}", ['title' => 'Hijacked'])
            ->assertStatus(404);

        $this->assertSame('Belongs to B', $reportB->fresh()->title);
    }
}
