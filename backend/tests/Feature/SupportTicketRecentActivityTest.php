<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Services\ProjectActivityService;
use App\Services\RecentActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupportTicketRecentActivityTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role = 'Client'): User
    {
        $org  = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

        return $user;
    }

    private function makeProject(Organization $org, User $user): Project
    {
        return Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P-' . uniqid()]);
    }

    private function enableBrevo(): void
    {
        SuresignSetting::instance()->update(['brevo_api_key' => 'fake-brevo-key']);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'test'], 201)]);
    }

    private function recordActivity(Project $project, User $user, string $type, string $title): void
    {
        ProjectActivityService::record($project, $user, $type, $title);
    }

    public function test_activity_is_absent_when_the_user_does_not_opt_in(): void
    {
        $this->enableBrevo();
        $user = $this->makeUser();
        $project = $this->makeProject($user->organization, $user);
        $this->recordActivity($project, $user, 'document_uploaded', 'Uploaded a document');
        Sanctum::actingAs($user);

        $this->postJson('/api/support-tickets', ['subject' => 'Test', 'message' => 'msg'])->assertStatus(201);

        $this->assertNull(SupportTicket::first()->recent_activity);
    }

    public function test_activity_is_included_when_selected(): void
    {
        $this->enableBrevo();
        $user = $this->makeUser();
        $project = $this->makeProject($user->organization, $user);
        $this->recordActivity($project, $user, 'document_uploaded', 'Uploaded site-plan.pdf');
        Sanctum::actingAs($user);

        $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'msg', 'include_recent_activity' => true,
        ])->assertStatus(201);

        $activity = SupportTicket::first()->recent_activity;
        $this->assertNotNull($activity);
        $this->assertSame('Uploaded site-plan.pdf', $activity[0]['description']);
        $this->assertSame($project->name, $activity[0]['project']);
    }

    public function test_activity_is_resolved_server_side_and_a_frontend_supplied_array_is_ignored(): void
    {
        $this->enableBrevo();
        $user = $this->makeUser();
        $project = $this->makeProject($user->organization, $user);
        $this->recordActivity($project, $user, 'document_uploaded', 'The real activity entry');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'msg', 'include_recent_activity' => true,
            // Not a real field the controller reads at all — proves the
            // backend never accepts client-supplied activity data.
            'recent_activity' => [['description' => 'FAKE INJECTED ENTRY', 'project' => 'Fake Co']],
            'activity' => [['description' => 'ANOTHER FAKE ENTRY']],
        ]);

        $response->assertStatus(201);
        $stored = SupportTicket::first()->recent_activity;
        $this->assertSame('The real activity entry', $stored[0]['description']);
        $this->assertStringNotContainsString('FAKE', json_encode($stored));
    }

    public function test_activity_is_bounded_to_the_approved_maximum(): void
    {
        $this->enableBrevo();
        $user = $this->makeUser();
        $project = $this->makeProject($user->organization, $user);
        for ($i = 0; $i < 25; $i++) {
            $this->recordActivity($project, $user, 'document_uploaded', "Entry {$i}");
        }
        Sanctum::actingAs($user);

        $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'msg', 'include_recent_activity' => true,
        ])->assertStatus(201);

        $this->assertCount(RecentActivityService::MAX_ENTRIES, SupportTicket::first()->recent_activity);
        $this->assertLessThanOrEqual(20, RecentActivityService::MAX_ENTRIES);
    }

    public function test_cross_tenant_activity_is_excluded(): void
    {
        $this->enableBrevo();
        $user = $this->makeUser();
        $otherOrg = Organization::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);
        $otherProject = $this->makeProject($otherOrg, $otherUser);
        $this->recordActivity($otherProject, $otherUser, 'document_uploaded', 'Another organization activity');
        Sanctum::actingAs($user);

        $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'msg', 'include_recent_activity' => true,
        ])->assertStatus(201);

        $activity = SupportTicket::first()->recent_activity;
        $this->assertSame([], $activity ?? []);
    }

    public function test_unauthorized_project_activity_is_excluded(): void
    {
        // A project the requesting user's organization has no relationship
        // to at all — same guarantee as cross-tenant, exercised via the
        // preview endpoint instead of submission this time.
        $this->enableBrevo();
        $user = $this->makeUser();
        $otherOrg = Organization::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);
        $otherProject = $this->makeProject($otherOrg, $otherUser);
        $this->recordActivity($otherProject, $otherUser, 'document_uploaded', 'Not yours');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/support-tickets/recent-activity-preview')->assertStatus(200);

        $this->assertSame([], $response->json('data'));
    }

    public function test_another_users_support_ticket_activity_never_leaks_into_this_users_preview(): void
    {
        $this->enableBrevo();
        $owner = $this->makeUser();
        Sanctum::actingAs($owner);
        $this->postJson('/api/support-tickets', ['subject' => 'Secret subject', 'message' => 'Secret message'])->assertStatus(201);

        // Same organization, different user — submitting a ticket must never
        // itself become an activity entry another user's preview could surface.
        $otherUser = User::factory()->create(['organization_id' => $owner->organization_id, 'is_active' => true]);
        $otherUser->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        Sanctum::actingAs($otherUser);

        $response = $this->getJson('/api/support-tickets/recent-activity-preview')->assertStatus(200);

        $this->assertStringNotContainsString('Secret subject', json_encode($response->json('data')));
    }

    public function test_secrets_and_raw_metadata_are_never_included(): void
    {
        $this->enableBrevo();
        $user = $this->makeUser();
        $project = $this->makeProject($user->organization, $user);
        ProjectActivityService::record($project, $user, 'document_uploaded', 'Uploaded a file', null, null, [
            'api_token' => 'secret-token-value', 'sql' => 'SELECT * FROM users', 'raw_body' => '{"password":"hunter2"}',
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'msg', 'include_recent_activity' => true,
        ]);

        $response->assertStatus(201);
        $stored = json_encode(SupportTicket::first()->recent_activity);
        $this->assertStringNotContainsString('secret-token-value', $stored);
        $this->assertStringNotContainsString('SELECT * FROM', $stored);
        $this->assertStringNotContainsString('hunter2', $stored);
    }

    public function test_stored_snapshot_contains_only_the_allowed_fields(): void
    {
        $this->enableBrevo();
        $user = $this->makeUser();
        $project = $this->makeProject($user->organization, $user);
        $this->recordActivity($project, $user, 'document_uploaded', 'Uploaded a file');
        Sanctum::actingAs($user);

        $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'msg', 'include_recent_activity' => true,
        ])->assertStatus(201);

        $entry = SupportTicket::first()->recent_activity[0];
        $this->assertEqualsCanonicalizing(
            ['timestamp', 'module', 'action_type', 'project', 'route', 'description'],
            array_keys($entry)
        );
    }

    public function test_internal_email_contains_only_the_safe_activity_summary(): void
    {
        $this->enableBrevo();
        $user = $this->makeUser();
        SuresignSetting::instance()->update(['support_email' => 'support@suresign.example']);
        $project = $this->makeProject($user->organization, $user);
        ProjectActivityService::record($project, $user, 'document_uploaded', 'Uploaded a file', null, null, [
            'api_token' => 'secret-token-value',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'msg', 'include_recent_activity' => true,
        ])->assertStatus(201);

        Http::assertSent(function ($request) {
            $body = $request->data()['htmlContent'];
            return str_contains($body, 'Recent SureSign activity')
                && str_contains($body, 'Uploaded a file')
                && !str_contains($body, 'secret-token-value');
        });
    }

    public function test_admin_ticket_list_shows_the_safe_activity_timeline(): void
    {
        $this->enableBrevo();
        $user = $this->makeUser();
        $project = $this->makeProject($user->organization, $user);
        $this->recordActivity($project, $user, 'document_uploaded', 'Uploaded a file');
        Sanctum::actingAs($user);
        $ticketId = $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'msg', 'include_recent_activity' => true,
        ])->assertStatus(201)->json('data.id');

        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);
        // recent_activity lives on the admin detail endpoint (Batch 5's
        // lighter, paginated list omits it, same split as the Client side).
        $row = $this->getJson("/api/admin/support-tickets/{$ticketId}")->assertStatus(200)->json('data');
        $this->assertNotNull($row['recent_activity']);
        $this->assertSame('Uploaded a file', $row['recent_activity'][0]['description']);
    }

    public function test_owner_sees_their_own_activity_snapshot_via_show(): void
    {
        $this->enableBrevo();
        $user = $this->makeUser();
        $project = $this->makeProject($user->organization, $user);
        $this->recordActivity($project, $user, 'document_uploaded', 'Uploaded a file');
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'msg', 'include_recent_activity' => true,
        ])->json('data.id');

        $response = $this->getJson("/api/support-tickets/{$id}")->assertStatus(200);

        $this->assertNotNull($response->json('data.recent_activity'));
    }

    public function test_another_client_user_cannot_view_this_ticket_or_its_activity(): void
    {
        $this->enableBrevo();
        $owner = $this->makeUser();
        $project = $this->makeProject($owner->organization, $owner);
        $this->recordActivity($project, $owner, 'document_uploaded', 'Uploaded a file');
        Sanctum::actingAs($owner);
        $id = $this->postJson('/api/support-tickets', [
            'subject' => 'Test', 'message' => 'msg', 'include_recent_activity' => true,
        ])->json('data.id');

        $otherUser = User::factory()->create(['organization_id' => $owner->organization_id, 'is_active' => true]);
        $otherUser->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        Sanctum::actingAs($otherUser);

        $this->getJson("/api/support-tickets/{$id}")->assertStatus(403);
    }

    public function test_recent_activity_preview_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/support-tickets/recent-activity-preview')->assertStatus(401);
    }

    public function test_existing_rate_limiting_still_applies_with_activity_opt_in(): void
    {
        $this->enableBrevo();
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/support-tickets', [
                'subject' => "Test {$i}", 'message' => 'msg', 'include_recent_activity' => true,
            ])->assertStatus(201);
        }

        $this->postJson('/api/support-tickets', [
            'subject' => 'Test 6', 'message' => 'msg', 'include_recent_activity' => true,
        ])->assertStatus(429);
    }
}
