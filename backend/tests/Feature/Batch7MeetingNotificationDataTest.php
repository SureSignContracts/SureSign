<?php

namespace Tests\Feature;

use App\Models\MeetingMinutes;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SuresignNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Batch 7 Phase 11: meeting notifications carry raw UTC scheduling data
 * so the frontend can render each recipient's OWN effective-timezone time,
 * in addition to the existing shared (scheduling-timezone-labelled)
 * message text — without any per-recipient message text or schema change.
 */
class Batch7MeetingNotificationDataTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function makeOrgAndUsers(string $timezone = 'Europe/London'): array
    {
        $n = ++static::$seq;
        $org = Organization::create(['name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => $timezone]);
        $actor = User::factory()->create(['organization_id' => $org->id]);
        $recipient = User::factory()->create(['organization_id' => $org->id]);
        $recipient->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        return compact('org', 'actor', 'recipient');
    }

    private function makeProject(Organization $org, User $user): Project
    {
        return Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'Project', 'status' => 'active']);
    }

    public function test_timed_meeting_notification_carries_recipient_renderable_utc_data(): void
    {
        $a = $this->makeOrgAndUsers('Europe/London');
        $project = $this->makeProject($a['org'], $a['actor']);
        Sanctum::actingAs($a['actor']);

        $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'X', 'is_timed' => true,
            'meeting_date' => '2026-07-21', 'start_time' => '14:00', 'end_time' => '15:00',
            'timezone' => 'Europe/London',
        ])->assertStatus(201);

        $n = SuresignNotification::where('user_id', $a['recipient']->id)->where('type', 'meeting_created')->firstOrFail();

        $this->assertTrue($n->data['is_timed']);
        $this->assertStringEndsWith('Z', $n->data['starts_at']); // raw UTC instant, not a local string
        $this->assertSame('Europe/London', $n->data['scheduled_timezone']);
    }

    public function test_date_only_meeting_notification_data_has_no_time_fields(): void
    {
        $a = $this->makeOrgAndUsers();
        $project = $this->makeProject($a['org'], $a['actor']);
        Sanctum::actingAs($a['actor']);

        $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'X', 'meeting_date' => '2026-07-21',
        ])->assertStatus(201);

        $n = SuresignNotification::where('user_id', $a['recipient']->id)->where('type', 'meeting_created')->firstOrFail();

        $this->assertFalse($n->data['is_timed']);
        $this->assertArrayNotHasKey('starts_at', $n->data);
    }

    public function test_meeting_reschedule_notification_action_url_and_recipients_unchanged(): void
    {
        $a = $this->makeOrgAndUsers();
        $project = $this->makeProject($a['org'], $a['actor']);
        Sanctum::actingAs($a['actor']);

        $id = $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'X', 'meeting_date' => '2026-07-21',
        ])->json('id');

        $this->putJson("/api/projects/{$project->id}/meetings/{$id}", ['meeting_date' => '2026-07-22'])->assertOk();

        $n = SuresignNotification::where('user_id', $a['recipient']->id)->where('type', 'meeting_rescheduled')->firstOrFail();

        $this->assertStringContainsString("/projects/{$project->id}/meetings", $n->action_url ?? '');
        $this->assertSame($a['recipient']->id, $n->user_id);
        // Actor (who made the change) is excluded — unchanged channel policy.
        $this->assertSame(0, SuresignNotification::where('user_id', $a['actor']->id)->where('type', 'meeting_rescheduled')->count());
    }
}
