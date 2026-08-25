<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\QaReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * P3 Security Remediation — QaReport.inspected_by mirrors Snag.assigned_to's
 * identical fix. See SnagAssigneeTenantScopingTest and
 * QaReportController::eligibleInspectorRule()'s docblock for the full
 * evidence-based rationale.
 */
class QaReportInspectorTenantScopingTest extends TestCase
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

    private function makeQaReport(Project $project, User $creator, ?int $inspectedBy = null): QaReport
    {
        return QaReport::create([
            'organization_id' => $project->organization_id,
            'project_id'      => $project->id,
            'created_by'      => $creator->id,
            'inspected_by'    => $inspectedBy,
            'report_number'   => 1,
            'title'           => 'Existing QA report',
        ]);
    }

    // ── Eligible cases ────────────────────────────────────────────────────

    public function test_same_organisation_user_is_accepted_on_create(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('a1');
        $eligible = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/qa-reports", [
            'title' => 'Fire door check', 'inspected_by' => $eligible->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('inspector.id', $eligible->id);
        $this->assertSame(1, QaReport::where('project_id', $project->id)->count());
    }

    public function test_same_organisation_user_is_accepted_on_update(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('a2');
        $eligible = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $report = $this->makeQaReport($project, $editor);

        Sanctum::actingAs($editor);
        $response = $this->putJson("/api/projects/{$project->id}/qa-reports/{$report->id}", ['inspected_by' => $eligible->id]);

        $response->assertStatus(200)->assertJsonPath('inspector.id', $eligible->id);
    }

    public function test_null_assignment_remains_valid(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a3');

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/qa-reports", [
            'title' => 'Unassigned QA report',
        ]);

        $response->assertStatus(201)->assertJsonPath('inspector', null);
    }

    // ── Foreign-organisation rejection ───────────────────────────────────

    public function test_foreign_organisation_user_is_rejected_on_create(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('b1');
        $foreignOrg = Organization::create(['name' => 'Foreign Org qb1', 'slug' => 'foreign-org-qb1']);
        $foreignUser = User::factory()->create(['organization_id' => $foreignOrg->id, 'is_active' => true]);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/qa-reports", [
            'title' => 'Fire door check', 'inspected_by' => $foreignUser->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, QaReport::where('project_id', $project->id)->count());
    }

    public function test_foreign_organisation_user_is_rejected_on_update(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('b2');
        $foreignOrg = Organization::create(['name' => 'Foreign Org qb2', 'slug' => 'foreign-org-qb2']);
        $foreignUser = User::factory()->create(['organization_id' => $foreignOrg->id, 'is_active' => true]);
        $originalInspector = User::factory()->create(['organization_id' => $project->organization_id, 'is_active' => true]);
        $report = $this->makeQaReport($project, $editor, $originalInspector->id);

        Sanctum::actingAs($editor);
        $response = $this->putJson("/api/projects/{$project->id}/qa-reports/{$report->id}", ['inspected_by' => $foreignUser->id]);

        $response->assertStatus(422);
        $this->assertSame($originalInspector->id, $report->fresh()->inspected_by, 'A rejected update must leave the existing relation unchanged.');
    }

    public function test_nonexistent_user_is_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('c1');

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/qa-reports", [
            'title' => 'Fire door check', 'inspected_by' => 999999,
        ]);

        $response->assertStatus(422);
    }

    public function test_foreign_and_nonexistent_rejection_semantics_match(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('c2');
        $foreignOrg = Organization::create(['name' => 'Foreign Org qc2', 'slug' => 'foreign-org-qc2']);
        $foreignUser = User::factory()->create(['organization_id' => $foreignOrg->id, 'is_active' => true]);

        Sanctum::actingAs($editor);
        $foreignResponse = $this->postJson("/api/projects/{$project->id}/qa-reports", [
            'title' => 'QA', 'inspected_by' => $foreignUser->id,
        ]);
        $nonexistentResponse = $this->postJson("/api/projects/{$project->id}/qa-reports", [
            'title' => 'QA', 'inspected_by' => 999999,
        ]);

        $this->assertSame($foreignResponse->getStatusCode(), $nonexistentResponse->getStatusCode());
        $this->assertSame(
            $foreignResponse->json('errors.inspected_by'),
            $nonexistentResponse->json('errors.inspected_by'),
            'Foreign and nonexistent user IDs must produce identical validation messages.'
        );
        $this->assertStringNotContainsStringIgnoringCase('organisation', $foreignResponse->json('errors.inspected_by.0'));
        $this->assertStringNotContainsStringIgnoringCase('organization', $foreignResponse->json('errors.inspected_by.0'));
    }

    public function test_platform_operator_with_null_organization_is_rejected_as_inspector(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d1');
        $platformOperator = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $platformOperator->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/qa-reports", [
            'title' => 'Fire door check', 'inspected_by' => $platformOperator->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_no_foreign_user_metadata_appears_in_a_rejected_response(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('e1');
        $foreignOrg = Organization::create(['name' => 'Secret Org qe1', 'slug' => 'secret-org-qe1']);
        $foreignUser = User::factory()->create([
            'organization_id' => $foreignOrg->id, 'is_active' => true, 'name' => 'Foreign Secret Inspector',
        ]);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/qa-reports", [
            'title' => 'Fire door check', 'inspected_by' => $foreignUser->id,
        ]);

        $response->assertStatus(422);
        $this->assertStringNotContainsString('Foreign Secret Inspector', $response->getContent());
    }

    // ── Side effects: rejected request must trigger nothing ─────────────

    public function test_rejected_create_sends_no_organisation_notification(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('n1');
        $foreignOrg = Organization::create(['name' => 'Foreign Org qn1', 'slug' => 'foreign-org-qn1']);
        $foreignUser = User::factory()->create(['organization_id' => $foreignOrg->id, 'is_active' => true]);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/qa-reports", [
            'title' => 'Fire door check', 'inspected_by' => $foreignUser->id,
        ])->assertStatus(422);

        $this->assertSame(
            0,
            \App\Models\SuresignNotification::where('organization_id', $org->id)->count(),
            'A rejected create must not trigger the organisation notification QaReport::store() normally sends.'
        );
    }

    public function test_valid_creation_still_sends_the_existing_organisation_notification(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('n2');
        $eligible = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        // sendToOrganization() only notifies users with the 'Client' role
        // (see NotificationService::sendToOrganization()) — a real
        // recipient is needed to prove the notification path actually ran.
        $clientRecipient = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $clientRecipient->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/qa-reports", [
            'title' => 'Fire door check', 'inspected_by' => $eligible->id,
        ])->assertStatus(201);

        $this->assertGreaterThan(
            0,
            \App\Models\SuresignNotification::where('organization_id', $org->id)->count(),
            'A valid create must still trigger the existing organisation notification, unchanged by this fix.'
        );
    }

    // ── Authorization unaffected ─────────────────────────────────────────

    public function test_client_from_a_different_organisation_cannot_edit_the_record_at_all(): void
    {
        [, , $project] = $this->makeOrgProjectAndEditor('f1');
        $otherOrg = Organization::create(['name' => 'Other Org qf1', 'slug' => 'other-org-qf1']);
        $otherOrgClient = User::factory()->create(['organization_id' => $otherOrg->id, 'is_active' => true]);

        Sanctum::actingAs($otherOrgClient);
        $this->postJson("/api/projects/{$project->id}/qa-reports", ['title' => 'Fire door check'])
            ->assertStatus(403);
    }

    public function test_admin_editing_authority_is_unchanged_but_referenced_user_eligibility_still_applies(): void
    {
        [$org, , $project] = $this->makeOrgProjectAndEditor('g1');
        $admin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        $eligibleUser = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/projects/{$project->id}/qa-reports", [
            'title' => 'Fire door check', 'inspected_by' => $eligibleUser->id,
        ])->assertStatus(201);

        $this->postJson("/api/projects/{$project->id}/qa-reports", [
            'title' => 'Fire door check', 'inspected_by' => $admin->id,
        ])->assertStatus(422);
    }
}
