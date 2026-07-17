<?php

namespace Tests\Feature;

use App\Models\MeetingMinutes;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Batch 6: Meetings & Calendar — timed scheduling.
 *
 * meeting_date (DATE) remains authoritative for legacy/date-only meetings.
 * starts_at/ends_at (UTC DATETIME) + scheduled_timezone are additive and
 * only populated when a meeting is explicitly created/edited as "timed".
 */
class Batch6MeetingsTimedSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private static int $orgSeq = 0;

    private function makeOrgAndUser(string $timezone = 'Europe/London'): array
    {
        $n = ++static::$orgSeq;
        $org  = Organization::create(['name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => $timezone]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        return compact('org', 'user');
    }

    private function makeProject(Organization $org, User $user): Project
    {
        return Project::create([
            'organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'Project', 'status' => 'active',
        ]);
    }

    /**
     * meeting_date is a `date` cast — pre-existing, app-wide Laravel
     * behaviour (confirmed via other date-cast models too, not introduced
     * by this batch) serializes it as a full ISO instant
     * ("2026-07-21T00:00:00.000000Z"), not a plain "YYYY-MM-DD" string.
     * This only checks the calendar-day prefix, which is the actual thing
     * under test.
     */
    private function assertMeetingDate($response, string $expectedDate): void
    {
        $this->assertSame($expectedDate, substr($response->json('meeting_date'), 0, 10));
    }

    // ── 1/2. Legacy date-only creation & update still work ─────────────────

    public function test_date_only_meeting_creation_still_works_unchanged(): void
    {
        $a = $this->makeOrgAndUser();
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'Progress meeting', 'meeting_date' => '2026-07-21',
        ]);

        $response->assertStatus(201);
        $this->assertMeetingDate($response, '2026-07-21');
        $response->assertJsonPath('starts_at', null);
        $response->assertJsonPath('ends_at', null);
        $response->assertJsonPath('is_timed', false);
    }

    public function test_date_only_meeting_update_still_works_unchanged(): void
    {
        $a = $this->makeOrgAndUser();
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $id = $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'Progress meeting', 'meeting_date' => '2026-07-21',
        ])->json('id');

        $response = $this->putJson("/api/projects/{$project->id}/meetings/{$id}", [
            'meeting_date' => '2026-07-22',
        ]);

        $response->assertOk();
        $this->assertMeetingDate($response, '2026-07-22');
        $response->assertJsonPath('starts_at', null);
    }

    // ── 3/4. Timed meeting stores UTC correctly; meeting_date derived ───────

    public function test_timed_meeting_stores_utc_start_end_and_derives_meeting_date(): void
    {
        $a = $this->makeOrgAndUser('Asia/Manila'); // UTC+8, no DST
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        // Manila is UTC+8 — 23:00-23:30 local on the 21st is 15:00-15:30 UTC
        // the SAME calendar day (converting local-ahead-of-UTC back to UTC
        // subtracts hours, it doesn't cross to the previous day).
        $response = $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'Late call', 'is_timed' => true,
            'meeting_date' => '2026-07-21', 'start_time' => '23:00', 'end_time' => '23:30',
            'timezone' => 'Asia/Manila',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('is_timed', true);
        $response->assertJsonPath('scheduled_timezone', 'Asia/Manila');
        $this->assertMeetingDate($response, '2026-07-21'); // organisation-local date, not UTC's

        $meeting = MeetingMinutes::find($response->json('id'));
        $this->assertSame('2026-07-21 15:00:00', $meeting->starts_at->copy()->setTimezone('UTC')->toDateTimeString());
        $this->assertSame('2026-07-21 15:30:00', $meeting->ends_at->copy()->setTimezone('UTC')->toDateTimeString());
    }

    // ── 5/6. Invalid timezone / fixed offset rejected ───────────────────────

    public function test_invalid_timezone_rejected(): void
    {
        $a = $this->makeOrgAndUser();
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'X', 'is_timed' => true,
            'meeting_date' => '2026-07-21', 'start_time' => '09:00', 'end_time' => '10:00',
            'timezone' => 'Not/AZone',
        ])->assertStatus(422)->assertJsonValidationErrors('timezone');
    }

    public function test_fixed_offset_rejected(): void
    {
        $a = $this->makeOrgAndUser();
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'X', 'is_timed' => true,
            'meeting_date' => '2026-07-21', 'start_time' => '09:00', 'end_time' => '10:00',
            'timezone' => 'UTC+8',
        ])->assertStatus(422)->assertJsonValidationErrors('timezone');
    }

    // ── 7. End before start rejected ────────────────────────────────────────

    public function test_end_equal_to_start_is_rejected(): void
    {
        $a = $this->makeOrgAndUser();
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'X', 'is_timed' => true,
            'meeting_date' => '2026-07-21', 'start_time' => '09:00', 'end_time' => '09:00',
            'timezone' => 'Europe/London',
        ])->assertStatus(422);
    }

    // ── 8. Cross-tenant access blocked (already covered by Batch3MeetingsTest,
    //        re-asserted here specifically against timed meetings) ──────────

    public function test_cross_tenant_access_blocked_for_timed_meeting(): void
    {
        $a = $this->makeOrgAndUser();
        $b = $this->makeOrgAndUser();
        $projectB = $this->makeProject($b['org'], $b['user']);

        Sanctum::actingAs($b['user']);
        $id = $this->postJson("/api/projects/{$projectB->id}/meetings", [
            'title' => 'X', 'is_timed' => true,
            'meeting_date' => '2026-07-21', 'start_time' => '09:00', 'end_time' => '10:00',
            'timezone' => 'Europe/London',
        ])->json('id');

        Sanctum::actingAs($a['user']);
        $this->getJson("/api/projects/{$projectB->id}/meetings/{$id}")->assertStatus(403);
    }

    // ── 9. Date-only meeting remains null start/end (creation) ─────────────

    public function test_date_only_meeting_has_null_start_and_end(): void
    {
        $a = $this->makeOrgAndUser();
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $meeting = MeetingMinutes::find(
            $this->postJson("/api/projects/{$project->id}/meetings", [
                'title' => 'X', 'meeting_date' => '2026-07-21',
            ])->json('id')
        );

        $this->assertNull($meeting->starts_at);
        $this->assertNull($meeting->ends_at);
        $this->assertNull($meeting->scheduled_timezone);
    }

    // ── 10. Editing legacy meeting does not invent time ─────────────────────

    public function test_editing_legacy_meeting_title_does_not_invent_a_time(): void
    {
        $a = $this->makeOrgAndUser();
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $id = $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'Old title', 'meeting_date' => '2026-07-21',
        ])->json('id');

        $response = $this->putJson("/api/projects/{$project->id}/meetings/{$id}", ['title' => 'New title']);

        $response->assertOk();
        $response->assertJsonPath('starts_at', null);
        $this->assertMeetingDate($response, '2026-07-21');
    }

    // ── 11/12. Explicit conversion timed <-> date-only ──────────────────────

    public function test_explicit_conversion_to_timed_works(): void
    {
        $a = $this->makeOrgAndUser();
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $id = $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'X', 'meeting_date' => '2026-07-21',
        ])->json('id');

        $response = $this->putJson("/api/projects/{$project->id}/meetings/{$id}", [
            'is_timed' => true, 'meeting_date' => '2026-07-21',
            'start_time' => '14:00', 'end_time' => '15:00', 'timezone' => 'Europe/London',
        ]);

        $response->assertOk();
        $response->assertJsonPath('is_timed', true);
        $this->assertNotNull($response->json('starts_at'));
    }

    public function test_explicit_conversion_back_to_date_only_works(): void
    {
        $a = $this->makeOrgAndUser();
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $id = $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'X', 'is_timed' => true,
            'meeting_date' => '2026-07-21', 'start_time' => '14:00', 'end_time' => '15:00',
            'timezone' => 'Europe/London',
        ])->json('id');

        $response = $this->putJson("/api/projects/{$project->id}/meetings/{$id}", [
            'is_timed' => false, 'meeting_date' => '2026-07-25',
        ]);

        $response->assertOk();
        $response->assertJsonPath('is_timed', false);
        $response->assertJsonPath('starts_at', null);
        $response->assertJsonPath('ends_at', null);
        $this->assertMeetingDate($response, '2026-07-25');
    }

    // ── 13. DST nonexistent time rejected ───────────────────────────────────

    public function test_dst_nonexistent_local_time_rejected_america_new_york_spring_forward(): void
    {
        $a = $this->makeOrgAndUser();
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        // 2026-03-08 is the US spring-forward date; 02:30 does not exist.
        $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'X', 'is_timed' => true,
            'meeting_date' => '2026-03-08', 'start_time' => '02:30', 'end_time' => '03:30',
            'timezone' => 'America/New_York',
        ])->assertStatus(422);
    }

    // ── 14. Ambiguous DST time follows documented policy (first/earlier occurrence) ──

    public function test_dst_ambiguous_local_time_resolves_to_first_occurrence_america_new_york_fall_back(): void
    {
        $a = $this->makeOrgAndUser();
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        // 2026-11-01 is the US fall-back date; 01:30 occurs twice (EDT then EST).
        // Documented policy: resolves to the first/earlier occurrence (EDT, UTC-4).
        $response = $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'X', 'is_timed' => true,
            'meeting_date' => '2026-11-01', 'start_time' => '01:30', 'end_time' => '01:45',
            'timezone' => 'America/New_York',
        ]);

        $response->assertStatus(201);
        $meeting = MeetingMinutes::find($response->json('id'));
        $this->assertSame('2026-11-01 05:30:00', $meeting->starts_at->copy()->setTimezone('UTC')->toDateTimeString());
    }

    // ── Timed meeting crossing midnight locally ─────────────────────────────

    public function test_timed_meeting_crossing_midnight_locally(): void
    {
        $a = $this->makeOrgAndUser();
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'Overnight session', 'is_timed' => true,
            'meeting_date' => '2026-07-21', 'start_time' => '23:30', 'end_time' => '00:30',
            'timezone' => 'Europe/London',
        ]);

        $response->assertStatus(201);
        $meeting = MeetingMinutes::find($response->json('id'));
        $this->assertTrue($meeting->ends_at->gt($meeting->starts_at));
        $this->assertSame('2026-07-22', $meeting->ends_at->copy()->setTimezone('Europe/London')->toDateString());
        // meeting_date reflects the START day, not the end day.
        $this->assertSame('2026-07-21', $meeting->meeting_date->toDateString());
    }

    // ── Australia/Sydney DST ────────────────────────────────────────────────

    public function test_australia_sydney_dst_start_creates_correct_utc_instant(): void
    {
        $a = $this->makeOrgAndUser('Australia/Sydney');
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        // Sydney DST starts 2026-10-04 (clocks forward 2am->3am AEDT).
        $response = $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'X', 'is_timed' => true,
            'meeting_date' => '2026-10-04', 'start_time' => '10:00', 'end_time' => '11:00',
            'timezone' => 'Australia/Sydney',
        ]);

        $response->assertStatus(201);
        // 10am AEDT (UTC+11, already past the 2am transition) = 23:00 UTC the previous day.
        $meeting = MeetingMinutes::find($response->json('id'));
        $this->assertSame('2026-10-03 23:00:00', $meeting->starts_at->copy()->setTimezone('UTC')->toDateTimeString());
    }

    // ── Organisation timezone changed after meeting creation ───────────────

    public function test_meeting_date_recompute_on_edit_uses_scheduled_timezone_not_current_org_timezone(): void
    {
        $a = $this->makeOrgAndUser('Europe/London');
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $id = $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'X', 'is_timed' => true,
            'meeting_date' => '2026-07-21', 'start_time' => '09:00', 'end_time' => '10:00',
            'timezone' => 'Europe/London',
        ])->json('id');

        // Organisation timezone changes after the meeting was scheduled.
        $a['org']->update(['timezone' => 'America/New_York']);

        // Editing something unrelated (title) must not silently reinterpret
        // or shift the already-stored schedule.
        $response = $this->putJson("/api/projects/{$project->id}/meetings/{$id}", ['title' => 'Renamed']);
        $response->assertOk();

        $meeting = MeetingMinutes::find($id);
        $this->assertSame('Europe/London', $meeting->scheduled_timezone);
        $this->assertSame('2026-07-21', $meeting->meeting_date->toDateString());
        $this->assertSame('2026-07-21 08:00:00', $meeting->starts_at->copy()->setTimezone('UTC')->toDateTimeString());
    }

    // ── Viewer timezone different from scheduled timezone (API exposes raw UTC + scheduled_timezone; frontend converts) ──

    public function test_api_exposes_raw_utc_and_scheduled_timezone_for_viewer_side_conversion(): void
    {
        $a = $this->makeOrgAndUser();
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'X', 'is_timed' => true,
            'meeting_date' => '2026-07-21', 'start_time' => '09:00', 'end_time' => '10:00',
            'timezone' => 'Europe/London',
        ]);

        // starts_at/ends_at serialize as UTC ISO strings — the frontend is
        // responsible for converting to the viewer's own effective timezone;
        // the API never bakes in a specific viewer's rendering.
        $this->assertStringEndsWith('Z', $response->json('starts_at'));
        $this->assertSame('Europe/London', $response->json('scheduled_timezone'));
    }

    // ── 18/19. Reschedule notification triggers correctly ───────────────────

    public function test_cosmetic_edit_does_not_trigger_reschedule_notification(): void
    {
        $a = $this->makeOrgAndUser();
        $project = $this->makeProject($a['org'], $a['user']);
        // sendToOrganization() excludes the acting user by default and only
        // notifies Client-role recipients — a distinct recipient is needed
        // for these assertions to be meaningful (not trivially true because
        // there was nobody eligible to notify either way).
        $recipient = User::factory()->create(['organization_id' => $a['org']->id]);
        $recipient->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        Sanctum::actingAs($a['user']);

        $id = $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'X', 'is_timed' => true,
            'meeting_date' => '2026-07-21', 'start_time' => '09:00', 'end_time' => '10:00',
            'timezone' => 'Europe/London',
        ])->json('id');

        $before = \App\Models\SuresignNotification::count();

        // Resubmitting the SAME schedule alongside a location edit — not a reschedule.
        $this->putJson("/api/projects/{$project->id}/meetings/{$id}", [
            'location' => 'Site office',
            'is_timed' => true, 'meeting_date' => '2026-07-21',
            'start_time' => '09:00', 'end_time' => '10:00', 'timezone' => 'Europe/London',
        ])->assertOk();

        $this->assertSame($before, \App\Models\SuresignNotification::count());
    }

    public function test_time_change_triggers_reschedule_notification(): void
    {
        $a = $this->makeOrgAndUser();
        $project = $this->makeProject($a['org'], $a['user']);
        $recipient = User::factory()->create(['organization_id' => $a['org']->id]);
        $recipient->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        Sanctum::actingAs($a['user']);

        $id = $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'X', 'is_timed' => true,
            'meeting_date' => '2026-07-21', 'start_time' => '09:00', 'end_time' => '10:00',
            'timezone' => 'Europe/London',
        ])->json('id');

        $before = \App\Models\SuresignNotification::count();

        $this->putJson("/api/projects/{$project->id}/meetings/{$id}", [
            'is_timed' => true, 'meeting_date' => '2026-07-21',
            'start_time' => '11:00', 'end_time' => '12:00', 'timezone' => 'Europe/London',
        ])->assertOk();

        $this->assertGreaterThan($before, \App\Models\SuresignNotification::count());
    }

    // ── 17. Sorting is deterministic across mixed date-only/timed meetings ──

    public function test_list_sorts_deterministically_mixing_date_only_and_timed_meetings(): void
    {
        $a = $this->makeOrgAndUser();
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        // Same day: one date-only, two timed at different times, submitted
        // out of chronological order.
        $late = $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'Late', 'is_timed' => true,
            'meeting_date' => '2026-07-21', 'start_time' => '15:00', 'end_time' => '16:00',
            'timezone' => 'Europe/London',
        ])->json('id');
        $allDay = $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'All day', 'meeting_date' => '2026-07-21',
        ])->json('id');
        $early = $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'Early', 'is_timed' => true,
            'meeting_date' => '2026-07-21', 'start_time' => '09:00', 'end_time' => '10:00',
            'timezone' => 'Europe/London',
        ])->json('id');
        // A different, earlier day — must sort after the 21st (latest first).
        $olderDay = $this->postJson("/api/projects/{$project->id}/meetings", [
            'title' => 'Older day', 'meeting_date' => '2026-07-20',
        ])->json('id');

        $ids = collect($this->getJson("/api/projects/{$project->id}/meetings")->json('data'))->pluck('id')->all();

        $this->assertSame([$allDay, $early, $late, $olderDay], $ids);
    }

    // ── 20. Existing Meetings feature tests remain green — see Batch3MeetingsTest ──
}
