<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Snag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * P3 Security Remediation — Snag.assigned_to previously accepted any
 * platform-wide user ID (plain `exists:users,id`), letting a crafted
 * request reference a foreign-organisation user, a platform operator
 * (organization_id = null), or a soft-deleted/inactive/banned user. Fixed
 * via SnagController::eligibleAssigneeRule() — see that method's own
 * docblock for the full evidence-based eligibility rationale.
 */
class SnagAssigneeTenantScopingTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgProjectAndEditor(string $suffix): array
    {
        $org = Organization::create(['name' => "Org {$suffix}", 'slug' => "org-{$suffix}"]);
        $editor = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $project = Project::create([
            'organization_id' => $org->id,
            'created_by'      => $editor->id,
            'name'            => "Project {$suffix}",
        ]);

        return [$org, $editor, $project];
    }

    private function makeSnag(Project $project, User $creator, ?int $assignedTo = null): Snag
    {
        return Snag::create([
            'organization_id' => $project->organization_id,
            'project_id'      => $project->id,
            'created_by'      => $creator->id,
            'assigned_to'     => $assignedTo,
            'snag_number'     => 1,
            'title'           => 'Existing snag',
        ]);
    }

    // ── Eligible cases ────────────────────────────────────────────────────

    public function test_same_organisation_user_is_accepted_on_create(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('a1');
        $eligible = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/snagging", [
            'title' => 'Cracked tile', 'assigned_to' => $eligible->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('assignee.id', $eligible->id);
        $this->assertSame(1, Snag::where('project_id', $project->id)->count());
    }

    public function test_same_organisation_user_is_accepted_on_update(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('a2');
        $eligible = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $snag = $this->makeSnag($project, $editor);

        Sanctum::actingAs($editor);
        $response = $this->putJson("/api/projects/{$project->id}/snagging/{$snag->id}", ['assigned_to' => $eligible->id]);

        $response->assertStatus(200)->assertJsonPath('assignee.id', $eligible->id);
    }

    public function test_null_assignment_remains_valid(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a3');

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/snagging", [
            'title' => 'Unassigned snag',
        ]);

        $response->assertStatus(201)->assertJsonPath('assignee', null);
    }

    // ── Foreign-organisation rejection ───────────────────────────────────

    public function test_foreign_organisation_user_is_rejected_on_create(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('b1');
        $foreignOrg = Organization::create(['name' => 'Foreign Org b1', 'slug' => 'foreign-org-b1']);
        $foreignUser = User::factory()->create(['organization_id' => $foreignOrg->id, 'is_active' => true]);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/snagging", [
            'title' => 'Cracked tile', 'assigned_to' => $foreignUser->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Snag::where('project_id', $project->id)->count());
    }

    public function test_foreign_organisation_user_is_rejected_on_update(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('b2');
        $foreignOrg = Organization::create(['name' => 'Foreign Org b2', 'slug' => 'foreign-org-b2']);
        $foreignUser = User::factory()->create(['organization_id' => $foreignOrg->id, 'is_active' => true]);
        $originalAssignee = User::factory()->create(['organization_id' => $project->organization_id, 'is_active' => true]);
        $snag = $this->makeSnag($project, $editor, $originalAssignee->id);

        Sanctum::actingAs($editor);
        $response = $this->putJson("/api/projects/{$project->id}/snagging/{$snag->id}", ['assigned_to' => $foreignUser->id]);

        $response->assertStatus(422);
        $this->assertSame($originalAssignee->id, $snag->fresh()->assigned_to, 'A rejected update must leave the existing assignment unchanged.');
    }

    public function test_nonexistent_user_is_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('c1');

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/snagging", [
            'title' => 'Cracked tile', 'assigned_to' => 999999,
        ]);

        $response->assertStatus(422);
    }

    public function test_foreign_and_nonexistent_rejection_semantics_match(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('c2');
        $foreignOrg = Organization::create(['name' => 'Foreign Org c2', 'slug' => 'foreign-org-c2']);
        $foreignUser = User::factory()->create(['organization_id' => $foreignOrg->id, 'is_active' => true]);

        Sanctum::actingAs($editor);
        $foreignResponse = $this->postJson("/api/projects/{$project->id}/snagging", [
            'title' => 'Snag', 'assigned_to' => $foreignUser->id,
        ]);
        $nonexistentResponse = $this->postJson("/api/projects/{$project->id}/snagging", [
            'title' => 'Snag', 'assigned_to' => 999999,
        ]);

        $this->assertSame($foreignResponse->getStatusCode(), $nonexistentResponse->getStatusCode());
        $this->assertSame(
            $foreignResponse->json('errors.assigned_to'),
            $nonexistentResponse->json('errors.assigned_to'),
            'Foreign and nonexistent user IDs must produce identical validation messages.'
        );
        $this->assertStringNotContainsStringIgnoringCase('organisation', $foreignResponse->json('errors.assigned_to.0'));
        $this->assertStringNotContainsStringIgnoringCase('organization', $foreignResponse->json('errors.assigned_to.0'));
    }

    public function test_platform_operator_with_null_organization_is_rejected_as_assignee(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d1');
        $platformOperator = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $platformOperator->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/snagging", [
            'title' => 'Cracked tile', 'assigned_to' => $platformOperator->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_no_foreign_user_metadata_appears_in_a_rejected_response(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('e1');
        $foreignOrg = Organization::create(['name' => 'Secret Org e1', 'slug' => 'secret-org-e1']);
        $foreignUser = User::factory()->create([
            'organization_id' => $foreignOrg->id, 'is_active' => true, 'name' => 'Foreign Secret Name',
        ]);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/snagging", [
            'title' => 'Cracked tile', 'assigned_to' => $foreignUser->id,
        ]);

        $response->assertStatus(422);
        $this->assertStringNotContainsString('Foreign Secret Name', $response->getContent());
    }

    // ── Authorization unaffected ─────────────────────────────────────────

    public function test_client_from_a_different_organisation_cannot_edit_the_record_at_all(): void
    {
        [, , $project] = $this->makeOrgProjectAndEditor('f1');
        $otherOrg = Organization::create(['name' => 'Other Org f1', 'slug' => 'other-org-f1']);
        $otherOrgClient = User::factory()->create(['organization_id' => $otherOrg->id, 'is_active' => true]);

        Sanctum::actingAs($otherOrgClient);
        $this->postJson("/api/projects/{$project->id}/snagging", ['title' => 'Cracked tile'])
            ->assertStatus(403);
    }

    public function test_admin_editing_authority_is_unchanged_but_referenced_user_eligibility_still_applies(): void
    {
        [$org, , $project] = $this->makeOrgProjectAndEditor('g1');
        $admin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        $eligibleUser = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        Sanctum::actingAs($admin);

        // Admin may edit this foreign-org project (platform-wide edit authority)...
        $this->postJson("/api/projects/{$project->id}/snagging", [
            'title' => 'Cracked tile', 'assigned_to' => $eligibleUser->id,
        ])->assertStatus(201);

        // ...but cannot reference the admin's own (organization_id = null) id.
        $this->postJson("/api/projects/{$project->id}/snagging", [
            'title' => 'Cracked tile', 'assigned_to' => $admin->id,
        ])->assertStatus(422);
    }
}
