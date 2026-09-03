<?php

namespace Tests\Feature;

use App\Models\FeatureAvailability;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SuresignNotification;
use App\Models\ToolboxTalk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Toolbox Talks V1A — mirrors QaReportInspectorTenantScopingTest/
 * SnagQaProjectParentIntegrityTest/SnagQaAttachmentParentIntegrityTest's
 * exact conventions for the equivalent, most-recently-built sibling
 * modules. See ToolboxTalkController/ToolboxTalk model docblocks for the
 * architectural decisions this test proves.
 */
class ToolboxTalkTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgProjectAndEditor(string $suffix, string $role = 'Client'): array
    {
        $org = Organization::create(['name' => "Org {$suffix}", 'slug' => "org-{$suffix}"]);
        $editor = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $editor->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        $project = Project::create([
            'organization_id' => $org->id,
            'created_by'      => $editor->id,
            'name'            => "Project {$suffix}",
        ]);

        return [$org, $editor, $project];
    }

    private function makeTalk(Project $project, User $creator, array $attrs = []): ToolboxTalk
    {
        return ToolboxTalk::create(array_merge([
            'organization_id' => $project->organization_id,
            'project_id'      => $project->id,
            'created_by'      => $creator->id,
            'title'           => 'Working at Height',
            'talk_date'       => '2026-08-21',
            'attendee_count'  => 8,
        ], $attrs));
    }

    private function fakePng(string $name = 'evidence.png'): UploadedFile
    {
        $file = UploadedFile::fake()->create($name, 10, 'image/png');
        file_put_contents($file->getPathname(), "\x89PNG\r\n\x1a\n" . str_repeat('x', 200));
        return $file;
    }

    // ── CRUD / list scoping ───────────────────────────────────────────────

    public function test_client_can_list_toolbox_talks_scoped_to_their_project(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('l1');
        $this->makeTalk($project, $editor);
        $otherOrgSetup = $this->makeOrgProjectAndEditor('l1b');
        $this->makeTalk($otherOrgSetup[2], $otherOrgSetup[1]);

        Sanctum::actingAs($editor);
        $response = $this->getJson("/api/projects/{$project->id}/toolbox-talks");

        $response->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_create_toolbox_talk(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('c1');

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/toolbox-talks", [
            'title' => 'Manual Handling', 'talk_date' => '2026-08-24', 'attendee_count' => 5,
        ]);

        $response->assertStatus(201)->assertJsonPath('title', 'Manual Handling')->assertJsonPath('status', 'draft');
        $this->assertSame(1, ToolboxTalk::where('project_id', $project->id)->count());
    }

    public function test_view_toolbox_talk(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('v1');
        $talk = $this->makeTalk($project, $editor);

        Sanctum::actingAs($editor);
        $this->getJson("/api/projects/{$project->id}/toolbox-talks/{$talk->id}")
            ->assertStatus(200)->assertJsonPath('id', $talk->id);
    }

    public function test_update_toolbox_talk(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('u1');
        $talk = $this->makeTalk($project, $editor);

        Sanctum::actingAs($editor);
        $this->putJson("/api/projects/{$project->id}/toolbox-talks/{$talk->id}", ['attendee_count' => 12])
            ->assertStatus(200)->assertJsonPath('attendee_count', 12);
    }

    public function test_delete_toolbox_talk(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d1');
        $talk = $this->makeTalk($project, $editor);

        Sanctum::actingAs($editor);
        $this->deleteJson("/api/projects/{$project->id}/toolbox-talks/{$talk->id}")->assertStatus(204);
        $this->assertNull(ToolboxTalk::find($talk->id));
    }

    // ── Tenant isolation ──────────────────────────────────────────────────

    public function test_wrong_organisation_is_rejected(): void
    {
        [, , $project] = $this->makeOrgProjectAndEditor('w1');
        $foreignOrg = Organization::create(['name' => 'Foreign Org w1', 'slug' => 'foreign-org-w1']);
        $foreignUser = User::factory()->create(['organization_id' => $foreignOrg->id, 'is_active' => true]);
        $foreignUser->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        Sanctum::actingAs($foreignUser);
        $this->postJson("/api/projects/{$project->id}/toolbox-talks", ['title' => 'x', 'talk_date' => '2026-08-24', 'attendee_count' => 1])
            ->assertStatus(403);
    }

    public function test_wrong_project_in_the_same_organisation_is_rejected(): void
    {
        [$org, $user, $projectA] = $this->makeOrgProjectAndEditor('w2');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'Project B w2']);
        $talkB = $this->makeTalk($projectB, $user);

        Sanctum::actingAs($user);
        $this->putJson("/api/projects/{$projectA->id}/toolbox-talks/{$talkB->id}", ['title' => 'Hijacked'])
            ->assertStatus(404);
        $this->getJson("/api/projects/{$projectA->id}/toolbox-talks/{$talkB->id}")->assertStatus(404);
        $this->deleteJson("/api/projects/{$projectA->id}/toolbox-talks/{$talkB->id}")->assertStatus(404);

        $this->assertSame('Working at Height', $talkB->fresh()->title);
    }

    public function test_admin_platform_wide_access_is_unaffected_but_does_not_bypass_project_parent_integrity(): void
    {
        [$org, $user, $projectA] = $this->makeOrgProjectAndEditor('w3');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'Project B w3']);
        $talkB = $this->makeTalk($projectB, $user);
        $admin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));

        Sanctum::actingAs($admin);
        $this->getJson("/api/projects/{$projectB->id}/toolbox-talks/{$talkB->id}")->assertStatus(200);
        $this->putJson("/api/projects/{$projectA->id}/toolbox-talks/{$talkB->id}", ['title' => 'Hijacked'])->assertStatus(404);
    }

    // ── Server-derived fields ────────────────────────────────────────────

    public function test_created_by_is_derived_server_side_not_client_supplied(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('s1');
        $otherUser = User::factory()->create(['organization_id' => $project->organization_id, 'is_active' => true]);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/toolbox-talks", [
            'title' => 'x', 'talk_date' => '2026-08-24', 'attendee_count' => 1, 'created_by' => $otherUser->id,
        ]);

        $response->assertStatus(201);
        $this->assertSame($editor->id, ToolboxTalk::first()->created_by);
    }

    public function test_organization_id_is_derived_server_side_not_client_supplied(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('s2');
        $foreignOrg = Organization::create(['name' => 'Foreign Org s2', 'slug' => 'foreign-org-s2']);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/toolbox-talks", [
            'title' => 'x', 'talk_date' => '2026-08-24', 'attendee_count' => 1, 'organization_id' => $foreignOrg->id,
        ]);

        $response->assertStatus(201);
        $this->assertSame($org->id, ToolboxTalk::first()->organization_id);
    }

    // ── Validation ────────────────────────────────────────────────────────

    public function test_invalid_status_is_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('val1');

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/toolbox-talks", [
            'title' => 'x', 'talk_date' => '2026-08-24', 'attendee_count' => 1, 'status' => 'not_a_real_status',
        ])->assertStatus(422);
    }

    public function test_negative_attendee_count_is_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('val2');

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/toolbox-talks", [
            'title' => 'x', 'talk_date' => '2026-08-24', 'attendee_count' => -1,
        ])->assertStatus(422);
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('val3');

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/toolbox-talks", [])->assertStatus(422);
    }

    // ── Deliverer scoping (mirrors QaReportInspectorTenantScopingTest) ──

    public function test_same_organisation_eligible_deliverer_is_accepted(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('del1');
        $deliverer = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/toolbox-talks", [
            'title' => 'x', 'talk_date' => '2026-08-24', 'attendee_count' => 1, 'delivered_by_user_id' => $deliverer->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('delivered_by_user.id', $deliverer->id);
    }

    public function test_cross_organisation_deliverer_is_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('del2');
        $foreignOrg = Organization::create(['name' => 'Foreign Org del2', 'slug' => 'foreign-org-del2']);
        $foreignUser = User::factory()->create(['organization_id' => $foreignOrg->id, 'is_active' => true]);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/toolbox-talks", [
            'title' => 'x', 'talk_date' => '2026-08-24', 'attendee_count' => 1, 'delivered_by_user_id' => $foreignUser->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, ToolboxTalk::where('project_id', $project->id)->count());
    }

    public function test_deleted_deliverer_is_rejected(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('del3');
        $deleted = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $deleted->delete();

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/toolbox-talks", [
            'title' => 'x', 'talk_date' => '2026-08-24', 'attendee_count' => 1, 'delivered_by_user_id' => $deleted->id,
        ])->assertStatus(422);
    }

    public function test_banned_deliverer_is_rejected(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('del4');
        $banned = User::factory()->create(['organization_id' => $org->id, 'is_active' => true, 'banned_at' => now()]);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/toolbox-talks", [
            'title' => 'x', 'talk_date' => '2026-08-24', 'attendee_count' => 1, 'delivered_by_user_id' => $banned->id,
        ])->assertStatus(422);
    }

    public function test_inactive_deliverer_is_rejected(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('del5');
        $inactive = User::factory()->create(['organization_id' => $org->id, 'is_active' => false]);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/toolbox-talks", [
            'title' => 'x', 'talk_date' => '2026-08-24', 'attendee_count' => 1, 'delivered_by_user_id' => $inactive->id,
        ])->assertStatus(422);
    }

    public function test_platform_operator_with_null_organization_is_rejected_as_deliverer(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('del6');
        $platformOperator = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $platformOperator->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/toolbox-talks", [
            'title' => 'x', 'talk_date' => '2026-08-24', 'attendee_count' => 1, 'delivered_by_user_id' => $platformOperator->id,
        ])->assertStatus(422);
    }

    public function test_external_delivered_by_name_is_accepted_without_a_user_id(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('del7');

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/toolbox-talks", [
            'title' => 'x', 'talk_date' => '2026-08-24', 'attendee_count' => 1, 'delivered_by_name' => 'External H&S Adviser',
        ]);

        $response->assertStatus(201)->assertJsonPath('delivered_by_name', 'External H&S Adviser');
    }

    // ── Attachments (mirrors SnagQaAttachmentParentIntegrityTest) ───────

    public function test_attachment_upload_and_list_succeeds_for_correct_parent(): void
    {
        Storage::fake('local');
        [, $editor, $project] = $this->makeOrgProjectAndEditor('att1');
        $talk = $this->makeTalk($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/toolbox-talks/{$talk->id}/attachments", ['file' => $this->fakePng()])
            ->assertStatus(201);

        $this->getJson("/api/projects/{$project->id}/toolbox-talks/{$talk->id}/attachments")
            ->assertStatus(200)->assertJsonCount(1);
    }

    /**
     * R1G.2 — ToolboxTalkController::attachments()/uploadAttachment()
     * previously returned the raw FileUpload model directly, exposing
     * internal `disk`/`file_path` storage details to the client. Fixed by
     * redacting locally via presentAttachment(), the same pattern every
     * sibling H&S module controller already uses (see
     * SiteInductionTest::test_raw_storage_path_not_exposed()'s identical
     * proof).
     */
    public function test_raw_storage_path_not_exposed(): void
    {
        Storage::fake('local');
        [, $editor, $project] = $this->makeOrgProjectAndEditor('att1b');
        $talk = $this->makeTalk($project, $editor);

        Sanctum::actingAs($editor);
        $uploadResponse = $this->postJson("/api/projects/{$project->id}/toolbox-talks/{$talk->id}/attachments", ['file' => $this->fakePng()]);
        $uploadResponse->assertStatus(201);
        $uploadResponse->assertJsonMissingPath('disk');
        $uploadResponse->assertJsonMissingPath('file_path');

        $listResponse = $this->getJson("/api/projects/{$project->id}/toolbox-talks/{$talk->id}/attachments");
        $listResponse->assertStatus(200);
        $listResponse->assertJsonMissingPath('0.disk');
        $listResponse->assertJsonMissingPath('0.file_path');
    }

    public function test_attachment_access_with_wrong_parent_project_is_rejected(): void
    {
        Storage::fake('local');
        [$org, $editor, $projectA] = $this->makeOrgProjectAndEditor('att2');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => 'Project B att2']);
        $talkB = $this->makeTalk($projectB, $editor);

        Sanctum::actingAs($editor);
        $this->getJson("/api/projects/{$projectA->id}/toolbox-talks/{$talkB->id}/attachments")->assertStatus(404);
        $this->postJson("/api/projects/{$projectA->id}/toolbox-talks/{$talkB->id}/attachments", ['file' => $this->fakePng()])->assertStatus(404);
    }

    public function test_attachment_delete_with_mismatched_tenant_is_rejected(): void
    {
        Storage::fake('local');
        [, $editor, $project] = $this->makeOrgProjectAndEditor('att3');
        $talk = $this->makeTalk($project, $editor);
        $otherTalk = $this->makeTalk($project, $editor, ['title' => 'Other']);

        Sanctum::actingAs($editor);
        $uploadResponse = $this->postJson("/api/projects/{$project->id}/toolbox-talks/{$talk->id}/attachments", ['file' => $this->fakePng()]);
        $fileId = $uploadResponse->json('id');

        // Attempting to delete talk A's attachment through talk B's route must fail.
        $this->deleteJson("/api/projects/{$project->id}/toolbox-talks/{$otherTalk->id}/attachments/{$fileId}")
            ->assertStatus(404);
    }

    // ── Activity trail ────────────────────────────────────────────────────

    public function test_activity_is_recorded_on_create(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('act1');

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/toolbox-talks", [
            'title' => 'PPE', 'talk_date' => '2026-08-24', 'attendee_count' => 3,
        ])->assertStatus(201);

        $this->assertDatabaseHas('project_activities', [
            'project_id' => $project->id, 'activity_type' => 'toolbox_talk_added',
        ]);
    }

    public function test_no_outbound_notification_is_sent_on_create(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('act2');
        $clientRecipient = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $clientRecipient->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/toolbox-talks", [
            'title' => 'PPE', 'talk_date' => '2026-08-24', 'attendee_count' => 3,
        ])->assertStatus(201);

        // Deliberate V1 decision — see ToolboxTalkController's own docblock.
        $this->assertSame(0, SuresignNotification::where('organization_id', $org->id)->count());
    }

    // ── Feature Availability ──────────────────────────────────────────────

    public function test_maintenance_blocks_mutation_but_not_reads(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fa1');
        $talk = $this->makeTalk($project, $editor);
        FeatureAvailability::create(['feature_key' => 'project.toolbox_talks', 'status' => 'maintenance']);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/toolbox-talks", ['title' => 'x', 'talk_date' => '2026-08-24', 'attendee_count' => 1])
            ->assertStatus(503);
        $this->getJson("/api/projects/{$project->id}/toolbox-talks")->assertStatus(200);
        $this->getJson("/api/projects/{$project->id}/toolbox-talks/{$talk->id}")->assertStatus(200);
    }

    // ── Friday Pack readiness: weekly date-range query ────────────────────

    public function test_talks_within_a_reporting_period_are_queryable_by_date_range(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wk1');
        $this->makeTalk($project, $editor, ['title' => 'In range', 'talk_date' => '2026-08-21']);
        $this->makeTalk($project, $editor, ['title' => 'Before range', 'talk_date' => '2026-08-14']);
        $this->makeTalk($project, $editor, ['title' => 'After range', 'talk_date' => '2026-08-29']);

        Sanctum::actingAs($editor);
        $response = $this->getJson("/api/projects/{$project->id}/toolbox-talks?from=2026-08-15&to=2026-08-21");

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('In range', $response->json('data.0.title'));
    }
}
