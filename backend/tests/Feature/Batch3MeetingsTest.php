<?php

namespace Tests\Feature;

use App\Models\MeetingMinutes;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 3: Meetings.
 *
 * Found and fixed a real gap: show/update/destroy take both {project} and
 * {meeting} in the URL, but only ever checked the meeting's own
 * organisation — never that the meeting actually belongs to the {project}
 * in the URL. A same-organisation but mismatched project ID would have
 * succeeded. Fixed via authorizeProjectMeeting(), mirroring the pattern
 * already used by TradePackageController in Batch 2.
 */
class Batch3MeetingsTest extends TestCase
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

    private function makeMeeting(Project $project, User $user, array $overrides = []): MeetingMinutes
    {
        static $n = 0;
        $n++;

        return MeetingMinutes::create(array_merge([
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $user->id,
            'meeting_number'  => $n,
            'title'           => 'Weekly progress meeting',
            'meeting_date'    => now()->toDateString(),
            'status'          => 'draft',
        ], $overrides));
    }

    // ── Positive ──────────────────────────────────────────────────────────

    public function test_client_can_create_edit_and_delete_a_meeting_in_their_own_project(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'Weekly progress meeting', 'meeting_date' => now()->toDateString(),
            'attendees' => ['Jane Doe', 'John Smith'],
        ]);
        $store->assertStatus(201);
        $id = $store->json('id');

        $update = $this->putJson("/api/projects/{$project->id}/meetings/{$id}", [
            'minutes' => 'Discussed programme slippage.', 'status' => 'issued',
        ]);
        $update->assertStatus(200);
        $this->assertDatabaseHas('meeting_minutes', ['id' => $id, 'status' => 'issued']);

        $this->deleteJson("/api/projects/{$project->id}/meetings/{$id}")->assertStatus(204);
        $this->assertDatabaseMissing('meeting_minutes', ['id' => $id]);
    }

    // ── Negative: cross-tenant ────────────────────────────────────────────

    public function test_client_cannot_view_edit_or_delete_another_organisations_meeting(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $meetingB = $this->makeMeeting($projectB, $b['user']);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$projectB->id}/meetings/{$meetingB->id}")->assertStatus(403);
        $this->putJson("/api/projects/{$projectB->id}/meetings/{$meetingB->id}", ['title' => 'Hijacked'])->assertStatus(403);
        $this->deleteJson("/api/projects/{$projectB->id}/meetings/{$meetingB->id}")->assertStatus(403);
    }

    public function test_client_cannot_address_a_meeting_using_a_mismatched_same_organisation_project_id(): void
    {
        $a = $this->makeOrgAndUser('a');
        $projectOne = $this->makeProject($a['org'], $a['user']);
        $projectTwo = $this->makeProject($a['org'], $a['user']);
        $meeting = $this->makeMeeting($projectOne, $a['user']);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$projectTwo->id}/meetings/{$meeting->id}")->assertStatus(404);
        $this->putJson("/api/projects/{$projectTwo->id}/meetings/{$meeting->id}", ['title' => 'Hijacked'])->assertStatus(404);
        $this->deleteJson("/api/projects/{$projectTwo->id}/meetings/{$meeting->id}")->assertStatus(404);
        $this->assertDatabaseHas('meeting_minutes', ['id' => $meeting->id, 'title' => 'Weekly progress meeting']);
    }

    public function test_client_cannot_create_a_meeting_under_another_organisations_project(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);

        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$projectB->id}/meetings", [
            'title' => 'Injected', 'meeting_date' => now()->toDateString(),
        ]);
        $response->assertStatus(403);
        $this->assertDatabaseMissing('meeting_minutes', ['title' => 'Injected']);
    }
}
