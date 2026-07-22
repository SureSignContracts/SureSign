<?php

namespace Tests\Feature;

use App\Models\AppointmentType;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Appointments & Scheduling — Phase 2 (Staff Availability & Internal Scheduling).
 */
class AppointmentsPhase2AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role, ?Organization $org = null): User
    {
        static $n = 0;
        $n++;

        // Explicit 'timezone' here matters for Phase 2 (unlike Phase 1): the
        // organizations.timezone column defaults to 'Europe/London' at the
        // DB level, but Eloquent doesn't read column defaults back into the
        // in-memory model after create() — so TimezoneResolver would
        // otherwise silently fall back to UTC for every staff member's
        // effective timezone in these tests (see Batch6MeetingsTimedSchedulingTest
        // for the same explicit-timezone convention).
        $org ??= Organization::create(['name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

        return $user;
    }

    private function makeType(array $overrides = []): AppointmentType
    {
        static $n = 0;
        $n++;

        return AppointmentType::create(array_merge([
            'name' => "Type {$n}", 'slug' => "type-{$n}",
            'duration_minutes' => 30, 'is_active' => true, 'assignment_mode' => 'manual',
            'meeting_method' => 'tbc', 'requires_confirmation' => false,
            'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'min_notice_hours' => 0, 'max_advance_days' => 60,
        ], $overrides));
    }

    /** A future date guaranteed to fall on $weekday (0=Sun..6=Sat), at least 3 days out. */
    private function nextDateForWeekday(int $weekday): string
    {
        $date = now()->addDays(3);
        while ($date->dayOfWeek !== $weekday) {
            $date = $date->addDay();
        }
        return $date->toDateString();
    }

    private function setWeekly(User $staff, int $weekday, string $start, string $end, ?User $actor = null): void
    {
        Sanctum::actingAs($actor ?? $staff);
        $this->putJson("/api/appointment-availability/" . ($actor && $actor->id !== $staff->id ? $staff->id : 'me'), [
            'windows' => [['weekday' => $weekday, 'start_time' => $start, 'end_time' => $end]],
        ])->assertStatus(200);
    }

    private function bookingPayload(AppointmentType $type, User $staff, string $date, string $startTime, array $overrides = []): array
    {
        return array_merge([
            'appointment_type_id' => $type->id,
            'assigned_user_id' => $staff->id,
            'attendee_name'  => 'Jane Doe',
            'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London',
            'date'       => $date,
            'start_time' => $startTime,
            'timezone'   => 'Europe/London',
        ], $overrides);
    }

    // ── Weekly availability CRUD ─────────────────────────────────────────

    public function test_weekly_availability_crud(): void
    {
        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $put = $this->putJson('/api/appointment-availability/me', [
            'windows' => [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00']],
        ]);
        $put->assertStatus(200);

        $get = $this->getJson('/api/appointment-availability/me');
        $get->assertStatus(200);
        $this->assertCount(1, $get->json('windows'));
    }

    public function test_multiple_windows_same_day(): void
    {
        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $response = $this->putJson('/api/appointment-availability/me', [
            'windows' => [
                ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '12:00'],
                ['weekday' => 1, 'start_time' => '13:00', 'end_time' => '17:00'],
            ],
        ]);
        $response->assertStatus(200);
        $this->assertCount(2, $this->getJson('/api/appointment-availability/me')->json('windows'));
    }

    public function test_overlapping_windows_rejected(): void
    {
        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $response = $this->putJson('/api/appointment-availability/me', [
            'windows' => [
                ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00'],
                ['weekday' => 1, 'start_time' => '12:00', 'end_time' => '17:00'],
            ],
        ]);
        $response->assertStatus(422);
    }

    public function test_invalid_time_range_rejected(): void
    {
        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $response = $this->putJson('/api/appointment-availability/me', [
            'windows' => [['weekday' => 1, 'start_time' => '12:00', 'end_time' => '12:00']],
        ]);
        $response->assertStatus(422);
    }

    // ── Permissions ───────────────────────────────────────────────────────

    public function test_admin_cannot_manage_another_users_availability(): void
    {
        $adminA = $this->makeUser('Admin');
        $adminB = $this->makeUser('Admin');
        Sanctum::actingAs($adminA);

        $this->putJson("/api/appointment-availability/{$adminB->id}", [
            'windows' => [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00']],
        ])->assertStatus(403);
        $this->getJson("/api/appointment-availability/{$adminB->id}")->assertStatus(403);
    }

    public function test_super_admin_can_manage_another_eligible_staff_members_availability(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($superAdmin);

        $put = $this->putJson("/api/appointment-availability/{$admin->id}", [
            'windows' => [['weekday' => 2, 'start_time' => '09:00', 'end_time' => '17:00']],
        ]);
        $put->assertStatus(200);

        $get = $this->getJson("/api/appointment-availability/{$admin->id}");
        $get->assertStatus(200);
        $this->assertCount(1, $get->json('windows'));
    }

    public function test_client_is_denied_access_to_availability_routes(): void
    {
        $client = $this->makeUser('Client');
        Sanctum::actingAs($client);

        $this->getJson('/api/appointment-availability/me')->assertStatus(403);
    }

    public function test_inactive_or_banned_staff_cannot_have_availability_set(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $bannedAdmin = $this->makeUser('Admin');
        $bannedAdmin->update(['banned_at' => now()]);
        Sanctum::actingAs($superAdmin);

        $response = $this->putJson("/api/appointment-availability/{$bannedAdmin->id}", [
            'windows' => [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00']],
        ]);
        $response->assertStatus(422);
    }

    // ── Date overrides ────────────────────────────────────────────────────

    public function test_date_override_takes_precedence_over_weekly_schedule(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType();
        $date = $this->nextDateForWeekday(1); // a Monday
        $this->setWeekly($admin, 1, '09:00', '17:00');

        Sanctum::actingAs($superAdmin);
        // Narrow the date down to 10:00-11:00 only via an override.
        $this->postJson("/api/appointment-availability/{$admin->id}/overrides", [
            'local_date' => $date, 'start_time' => '10:00', 'end_time' => '11:00',
        ])->assertStatus(201);

        // 09:30 was within the weekly window but is now outside the override window.
        $rejected = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '09:30'));
        $rejected->assertStatus(409);

        // 10:15 is within the override window.
        $accepted = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '10:15'));
        $accepted->assertStatus(201);
    }

    public function test_whole_day_unavailable_override_blocks_booking(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType();
        $date = $this->nextDateForWeekday(2);
        $this->setWeekly($admin, 2, '09:00', '17:00');

        Sanctum::actingAs($superAdmin);
        $this->postJson("/api/appointment-availability/{$admin->id}/overrides", [
            'local_date' => $date, 'is_unavailable' => true,
        ])->assertStatus(201);

        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '10:00'));
        $response->assertStatus(409);
    }

    public function test_custom_date_availability_allows_booking_outside_weekly_schedule(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType();
        $date = $this->nextDateForWeekday(0); // a Sunday — no weekly availability set at all

        Sanctum::actingAs($superAdmin);
        $this->postJson("/api/appointment-availability/{$admin->id}/overrides", [
            'local_date' => $date, 'start_time' => '11:00', 'end_time' => '13:00',
        ])->assertStatus(201);

        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '11:30'));
        $response->assertStatus(201);
    }

    // ── Blocked periods ───────────────────────────────────────────────────

    public function test_blocked_period_prevents_booking(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType();
        $date = $this->nextDateForWeekday(3);
        $this->setWeekly($admin, 3, '09:00', '17:00');

        Sanctum::actingAs($superAdmin);
        $this->postJson("/api/appointment-availability/{$admin->id}/blocked-periods", [
            'start_date' => $date, 'start_time' => '10:00',
            'end_date' => $date, 'end_time' => '12:00',
            'timezone' => 'Europe/London', 'reason' => 'Annual leave',
        ])->assertStatus(201);

        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '10:30'));
        $response->assertStatus(409);
    }

    // ── Conflicts, buffers, notice, advance ───────────────────────────────

    public function test_normal_appointment_overlap_still_rejected(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType(['duration_minutes' => 60]);
        $date = $this->nextDateForWeekday(4);
        $this->setWeekly($admin, 4, '09:00', '17:00');

        Sanctum::actingAs($superAdmin);
        $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '10:00'))->assertStatus(201);
        $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '10:30'))->assertStatus(409);
    }

    public function test_buffer_before_conflict_is_rejected(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType(['duration_minutes' => 30, 'buffer_before_minutes' => 15]);
        $date = $this->nextDateForWeekday(5);
        $this->setWeekly($admin, 5, '09:00', '17:00');

        Sanctum::actingAs($superAdmin);
        // Existing appointment 11:00-11:30.
        $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '11:00'))->assertStatus(201);

        // New appointment 11:35-12:05 needs 15 min BEFORE it clear — 11:35 buffer
        // window (11:20-11:35) overlaps the existing appointment (ends 11:30).
        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '11:35'));
        $response->assertStatus(409);
    }

    public function test_buffer_after_conflict_is_rejected(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType(['duration_minutes' => 30, 'buffer_after_minutes' => 15]);
        $date = $this->nextDateForWeekday(6);
        $this->setWeekly($admin, 6, '09:00', '17:00');

        Sanctum::actingAs($superAdmin);
        // Existing appointment 11:00-11:30.
        $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '11:00'))->assertStatus(201);

        // New appointment 10:20-10:50 needs 15 min AFTER it clear — its buffer
        // window (10:20-11:05) overlaps the existing appointment (starts 11:00).
        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '10:20'));
        $response->assertStatus(409);
    }

    public function test_minimum_notice_is_enforced(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType(['min_notice_hours' => 72]);
        $date = now()->addDay()->toDateString();
        $this->setWeekly($admin, now()->addDay()->dayOfWeek, '00:00', '23:59');

        Sanctum::actingAs($superAdmin);
        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '10:00'));
        $response->assertStatus(409);
    }

    public function test_maximum_advance_is_enforced(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType(['max_advance_days' => 5]);
        $date = now()->addDays(30)->toDateString();
        $this->setWeekly($admin, now()->addDays(30)->dayOfWeek, '00:00', '23:59');

        Sanctum::actingAs($superAdmin);
        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '10:00'));
        $response->assertStatus(409);
    }

    public function test_valid_appointment_inside_availability_succeeds(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType();
        $date = $this->nextDateForWeekday(1);
        $this->setWeekly($admin, 1, '09:00', '17:00');

        Sanctum::actingAs($superAdmin);
        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '14:00'));
        $response->assertStatus(201);
    }

    public function test_reschedule_does_not_conflict_with_its_own_original_slot(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType();
        $date = $this->nextDateForWeekday(2);
        $this->setWeekly($admin, 2, '09:00', '17:00');

        Sanctum::actingAs($superAdmin);
        $store = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '10:00'));
        $store->assertStatus(201);
        $id = $store->json('id');

        $response = $this->postJson("/api/appointments/{$id}/reschedule", [
            'date' => $date, 'start_time' => '10:15', 'timezone' => 'Europe/London',
        ]);
        $response->assertStatus(200);
    }

    // ── DST ───────────────────────────────────────────────────────────────

    public function test_dst_spring_forward_invalid_local_time_is_rejected(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType(['max_advance_days' => 3650]);
        Sanctum::actingAs($superAdmin);

        // 2026-03-08 02:30 America/New_York does not exist (spring forward).
        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, '2026-03-08', '02:30', [
            'timezone' => 'America/New_York',
        ]));
        $response->assertStatus(422);
    }

    public function test_dst_fall_back_ambiguous_local_time_does_not_error(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        // max_advance_days raised — the test's fixed 2026-11-01 fall-back
        // date is a fact about the US DST calendar, independent of whenever
        // "now" happens to be when the suite runs.
        $type = $this->makeType(['max_advance_days' => 3650]);
        $this->setWeekly($admin, Carbon::parse('2026-11-01')->dayOfWeek, '00:00', '23:59');

        Sanctum::actingAs($superAdmin);

        // 2026-11-01 01:30 America/New_York occurs twice (fall back) — must
        // resolve without throwing, per TimezoneResolver's documented
        // first-occurrence behaviour.
        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, '2026-11-01', '01:30', [
            'timezone' => 'America/New_York',
        ]));
        $response->assertStatus(201);
    }

    // ── Super Admin override ──────────────────────────────────────────────

    public function test_super_admin_override_requires_a_reason(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType();
        $date = $this->nextDateForWeekday(1);
        // No weekly availability set at all — booking would normally fail.

        Sanctum::actingAs($superAdmin);
        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '10:00', ['override' => true]));
        $response->assertStatus(422);
    }

    public function test_super_admin_override_bypasses_availability_but_not_overlap(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType();
        $date = $this->nextDateForWeekday(1);
        // No weekly availability set — normally this would be rejected.

        Sanctum::actingAs($superAdmin);
        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '10:00', [
            'override' => true, 'override_reason' => 'Customer requested an exception',
        ]));
        $response->assertStatus(201);

        $this->assertDatabaseHas('activity_logs', ['action' => 'appointment.availability_override_used']);

        // But same-staff overlap is still enforced even with an override.
        $conflict = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '10:15', [
            'override' => true, 'override_reason' => 'Trying to double book',
        ]));
        $conflict->assertStatus(409);
    }

    public function test_admin_cannot_use_the_override_flag(): void
    {
        $admin = $this->makeUser('Admin');
        $type = $this->makeType();
        $date = $this->nextDateForWeekday(1);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '10:00', [
            'override' => true, 'override_reason' => 'Trying to self-override',
        ]));
        $response->assertStatus(403);
    }

    // ── Admin always self-assigned (Phase 2 approved decision) ────────────

    public function test_admin_created_appointment_is_always_assigned_to_self(): void
    {
        $adminA = $this->makeUser('Admin');
        $adminB = $this->makeUser('Admin');
        $type = $this->makeType();
        $date = $this->nextDateForWeekday(1);
        $this->setWeekly($adminA, 1, '09:00', '17:00');
        Sanctum::actingAs($adminA);

        // Cannot leave unassigned.
        $unassigned = $this->postJson('/api/appointments', $this->bookingPayload($type, $adminA, $date, '10:00', ['assigned_user_id' => null]));
        $unassigned->assertStatus(201);
        $this->assertSame($adminA->id, $unassigned->json('assigned_user_id'));

        // Cannot assign to someone else.
        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, $adminB, $date, '11:00'));
        $response->assertStatus(403);
    }

    public function test_super_admin_can_still_create_unassigned_appointments_skipping_availability(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $type = $this->makeType();
        $date = $this->nextDateForWeekday(1);
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/appointments', [
            'appointment_type_id' => $type->id,
            'attendee_name' => 'Jane Doe', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London',
            'date' => $date, 'start_time' => '10:00', 'timezone' => 'Europe/London',
        ]);
        $response->assertStatus(201);
        $this->assertNull($response->json('assigned_user_id'));
    }

    public function test_assigning_an_unassigned_appointment_validates_full_availability(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType();
        $date = $this->nextDateForWeekday(1);
        // No availability set for $admin at all.
        Sanctum::actingAs($superAdmin);

        $store = $this->postJson('/api/appointments', [
            'appointment_type_id' => $type->id,
            'attendee_name' => 'Jane Doe', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London',
            'date' => $date, 'start_time' => '10:00', 'timezone' => 'Europe/London',
        ]);
        $id = $store->json('id');

        $assign = $this->postJson("/api/appointments/{$id}/assign", ['assigned_user_id' => $admin->id]);
        $assign->assertStatus(409);
    }

    // ── Activity log ──────────────────────────────────────────────────────

    public function test_activity_log_records_availability_changes(): void
    {
        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $this->putJson('/api/appointment-availability/me', [
            'windows' => [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00']],
        ])->assertStatus(200);
        $this->assertDatabaseHas('activity_logs', ['action' => 'appointment_availability.updated']);

        $override = $this->postJson('/api/appointment-availability/me/overrides', [
            'local_date' => now()->addDays(5)->toDateString(), 'is_unavailable' => true,
        ]);
        $override->assertStatus(201);
        $this->assertDatabaseHas('activity_logs', ['action' => 'appointment_availability_override.created']);

        $blocked = $this->postJson('/api/appointment-availability/me/blocked-periods', [
            'start_date' => now()->addDays(6)->toDateString(), 'start_time' => '09:00',
            'end_date' => now()->addDays(6)->toDateString(), 'end_time' => '10:00',
            'timezone' => 'Europe/London',
        ]);
        $blocked->assertStatus(201);
        $this->assertDatabaseHas('activity_logs', ['action' => 'appointment_blocked_period.created']);
    }
}
