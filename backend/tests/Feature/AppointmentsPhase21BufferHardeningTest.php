<?php

namespace Tests\Feature;

use App\Models\AppointmentType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Appointments & Scheduling — Phase 2.1 (Scheduling Integrity Hardening).
 *
 * Root cause under test: Phase 2's buffer-conflict check expanded only the
 * PROPOSED appointment's interval by its own type's buffers, then compared
 * it against existing appointments' RAW stored start/end — ignoring each
 * existing appointment's own type's buffers entirely. This let a new
 * booking land inside another appointment's required pre/post buffer.
 *
 * The fix (AppointmentSchedulingService::hasBufferedConflict()) computes an
 * effective interval for BOTH sides using each appointment's own type, and
 * moved the check out of the overridable availability path — buffer
 * conflicts are now, like direct overlap, never overridable.
 */
class AppointmentsPhase21BufferHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role, ?Organization $org = null): User
    {
        static $n = 0;
        $n++;

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

    private function nextDateForWeekday(int $weekday): string
    {
        $date = now()->addDays(3);
        while ($date->dayOfWeek !== $weekday) {
            $date = $date->addDay();
        }
        return $date->toDateString();
    }

    private function setWeekly(User $staff, int $weekday, string $start, string $end): void
    {
        Sanctum::actingAs($staff);
        $this->putJson('/api/appointment-availability/me', [
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

    // ── Asymmetric buffer scenarios (the actual regression) ───────────────

    public function test_existing_appointments_buffer_after_blocks_a_later_proposal(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $existingType = $this->makeType(['duration_minutes' => 60, 'buffer_after_minutes' => 30]);
        $newType = $this->makeType(['duration_minutes' => 50, 'buffer_before_minutes' => 0]);
        $date = $this->nextDateForWeekday(1);
        $this->setWeekly($admin, 1, '00:00', '23:59');

        Sanctum::actingAs($superAdmin);
        // Existing 10:00-11:00, buffer_after 30 -> effective end 11:30.
        $this->postJson('/api/appointments', $this->bookingPayload($existingType, $admin, $date, '10:00'))->assertStatus(201);

        // Proposed 11:10-12:00, buffer_before 0 -> effective start 11:10, which is < 11:30.
        $response = $this->postJson('/api/appointments', $this->bookingPayload($newType, $admin, $date, '11:10'));
        $response->assertStatus(409);
    }

    public function test_existing_appointments_buffer_before_blocks_an_earlier_proposal(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $existingType = $this->makeType(['duration_minutes' => 60, 'buffer_before_minutes' => 30]);
        $newType = $this->makeType(['duration_minutes' => 30, 'buffer_after_minutes' => 0]);
        $date = $this->nextDateForWeekday(2);
        $this->setWeekly($admin, 2, '00:00', '23:59');

        Sanctum::actingAs($superAdmin);
        // Existing 10:00-11:00, buffer_before 30 -> effective start 09:30.
        $this->postJson('/api/appointments', $this->bookingPayload($existingType, $admin, $date, '10:00'))->assertStatus(201);

        // Proposed 09:15-09:45, buffer_after 0 -> effective end 09:45, which is > 09:30.
        $response = $this->postJson('/api/appointments', $this->bookingPayload($newType, $admin, $date, '09:15'));
        $response->assertStatus(409);
    }

    public function test_proposed_appointments_buffer_before_creates_a_conflict(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $existingType = $this->makeType(['duration_minutes' => 60, 'buffer_after_minutes' => 0]);
        $newType = $this->makeType(['duration_minutes' => 40, 'buffer_before_minutes' => 30]);
        $date = $this->nextDateForWeekday(3);
        $this->setWeekly($admin, 3, '00:00', '23:59');

        Sanctum::actingAs($superAdmin);
        // Existing 10:00-11:00, buffer_after 0 -> effective end 11:00.
        $this->postJson('/api/appointments', $this->bookingPayload($existingType, $admin, $date, '10:00'))->assertStatus(201);

        // Proposed 11:20-12:00, buffer_before 30 -> effective start 10:50, which is < 11:00.
        $response = $this->postJson('/api/appointments', $this->bookingPayload($newType, $admin, $date, '11:20'));
        $response->assertStatus(409);
    }

    public function test_proposed_appointments_buffer_after_creates_a_conflict(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $existingType = $this->makeType(['duration_minutes' => 60, 'buffer_before_minutes' => 0]);
        $newType = $this->makeType(['duration_minutes' => 40, 'buffer_after_minutes' => 30]);
        $date = $this->nextDateForWeekday(4);
        $this->setWeekly($admin, 4, '00:00', '23:59');

        Sanctum::actingAs($superAdmin);
        // Existing 12:00-13:00, buffer_before 0 -> effective start 12:00.
        $this->postJson('/api/appointments', $this->bookingPayload($existingType, $admin, $date, '12:00'))->assertStatus(201);

        // Proposed 11:00-11:40, buffer_after 30 -> effective end 12:10, which is > 12:00.
        $response = $this->postJson('/api/appointments', $this->bookingPayload($newType, $admin, $date, '11:00'));
        $response->assertStatus(409);
    }

    // ── Boundary semantics ─────────────────────────────────────────────────

    public function test_exact_effective_boundary_contact_is_allowed(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $existingType = $this->makeType(['duration_minutes' => 60, 'buffer_after_minutes' => 30]);
        $newType = $this->makeType(['duration_minutes' => 30, 'buffer_before_minutes' => 0]);
        $date = $this->nextDateForWeekday(5);
        $this->setWeekly($admin, 5, '00:00', '23:59');

        Sanctum::actingAs($superAdmin);
        // Existing 10:00-11:00, buffer_after 30 -> effective end 11:30.
        $this->postJson('/api/appointments', $this->bookingPayload($existingType, $admin, $date, '10:00'))->assertStatus(201);

        // Proposed starts exactly at 11:30 -> touches the boundary, not an overlap.
        $response = $this->postJson('/api/appointments', $this->bookingPayload($newType, $admin, $date, '11:30'));
        $response->assertStatus(201);
    }

    public function test_one_minute_effective_overlap_is_rejected(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $existingType = $this->makeType(['duration_minutes' => 60, 'buffer_after_minutes' => 30]);
        $newType = $this->makeType(['duration_minutes' => 30, 'buffer_before_minutes' => 0]);
        $date = $this->nextDateForWeekday(6);
        $this->setWeekly($admin, 6, '00:00', '23:59');

        Sanctum::actingAs($superAdmin);
        // Existing 10:00-11:00, buffer_after 30 -> effective end 11:30.
        $this->postJson('/api/appointments', $this->bookingPayload($existingType, $admin, $date, '10:00'))->assertStatus(201);

        // Proposed starts one minute early, at 11:29 -> 1 minute effective overlap.
        $response = $this->postJson('/api/appointments', $this->bookingPayload($newType, $admin, $date, '11:29'));
        $response->assertStatus(409);
    }

    // ── Reschedule / assignment ────────────────────────────────────────────

    public function test_reschedule_excludes_the_appointment_itself(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType(['duration_minutes' => 30, 'buffer_before_minutes' => 15, 'buffer_after_minutes' => 15]);
        $date = $this->nextDateForWeekday(0);
        $this->setWeekly($admin, 0, '00:00', '23:59');

        Sanctum::actingAs($superAdmin);
        $store = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '10:00'));
        $id = $store->json('id');

        // Reschedule to a slightly different time — must not conflict with
        // its own original (buffered) slot.
        $response = $this->postJson("/api/appointments/{$id}/reschedule", [
            'date' => $date, 'start_time' => '10:05', 'timezone' => 'Europe/London',
        ]);
        $response->assertStatus(200);
    }

    public function test_reschedule_checks_buffered_conflict_against_another_appointment(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $blockerType = $this->makeType(['duration_minutes' => 60, 'buffer_before_minutes' => 20]);
        $movableType = $this->makeType(['duration_minutes' => 30]);
        $date = $this->nextDateForWeekday(1);
        $this->setWeekly($admin, 1, '00:00', '23:59');

        Sanctum::actingAs($superAdmin);
        // Blocker 13:00-14:00, buffer_before 20 -> effective start 12:40.
        $this->postJson('/api/appointments', $this->bookingPayload($blockerType, $admin, $date, '13:00'))->assertStatus(201);

        $movable = $this->postJson('/api/appointments', $this->bookingPayload($movableType, $admin, $date, '09:00'));
        $movableId = $movable->json('id');

        // Move into 12:45-13:15, which overlaps the blocker's effective start (12:40).
        $response = $this->postJson("/api/appointments/{$movableId}/reschedule", [
            'date' => $date, 'start_time' => '12:45', 'timezone' => 'Europe/London',
        ]);
        $response->assertStatus(409);
    }

    public function test_assigning_a_previously_unassigned_appointment_checks_both_sides_buffers(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $existingType = $this->makeType(['duration_minutes' => 60, 'buffer_after_minutes' => 30]);
        $unassignedType = $this->makeType(['duration_minutes' => 30]);
        $date = $this->nextDateForWeekday(2);
        $this->setWeekly($admin, 2, '00:00', '23:59');

        Sanctum::actingAs($superAdmin);
        // Admin already has 10:00-11:00 with a 30-minute buffer_after -> effective end 11:30.
        $this->postJson('/api/appointments', $this->bookingPayload($existingType, $admin, $date, '10:00'))->assertStatus(201);

        // An unassigned appointment at 11:10 (within the effective buffer).
        $unassigned = $this->postJson('/api/appointments', [
            'appointment_type_id' => $unassignedType->id,
            'attendee_name' => 'Jane Doe', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London',
            'date' => $date, 'start_time' => '11:10', 'timezone' => 'Europe/London',
        ]);
        $unassigned->assertStatus(201);
        $this->assertNull($unassigned->json('assigned_user_id'));

        // Assigning it to $admin must run the full (buffered) conflict check.
        $assign = $this->postJson("/api/appointments/{$unassigned->json('id')}/assign", ['assigned_user_id' => $admin->id]);
        $assign->assertStatus(409);
    }

    // ── Override cannot bypass overlap or buffer ──────────────────────────

    public function test_super_admin_override_cannot_bypass_direct_overlap(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType(['duration_minutes' => 60]);
        $date = $this->nextDateForWeekday(3);
        $this->setWeekly($admin, 3, '00:00', '23:59');

        Sanctum::actingAs($superAdmin);
        $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '10:00'))->assertStatus(201);

        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '10:30', [
            'override' => true, 'override_reason' => 'Trying to double book',
        ]));
        $response->assertStatus(409);
    }

    public function test_super_admin_override_cannot_bypass_buffered_conflict(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $existingType = $this->makeType(['duration_minutes' => 60, 'buffer_after_minutes' => 30]);
        $newType = $this->makeType(['duration_minutes' => 30]);
        $date = $this->nextDateForWeekday(4);
        $this->setWeekly($admin, 4, '00:00', '23:59');

        Sanctum::actingAs($superAdmin);
        // Existing 10:00-11:00, buffer_after 30 -> effective end 11:30.
        $this->postJson('/api/appointments', $this->bookingPayload($existingType, $admin, $date, '10:00'))->assertStatus(201);

        // Proposed at 11:10 (within the buffer) WITH an override + reason.
        $response = $this->postJson('/api/appointments', $this->bookingPayload($newType, $admin, $date, '11:10', [
            'override' => true, 'override_reason' => 'Trying to bypass the buffer',
        ]));
        $response->assertStatus(409);
    }

    public function test_super_admin_override_still_bypasses_availability_restrictions(): void
    {
        // Sanity check the override still works for the things it's meant
        // to bypass — no weekly availability configured at all.
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType();
        $date = $this->nextDateForWeekday(5);

        Sanctum::actingAs($superAdmin);
        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '10:00', [
            'override' => true, 'override_reason' => 'Customer requested an exception',
        ]));
        $response->assertStatus(201);
    }

    // ── Different staff members, no conflict ──────────────────────────────

    public function test_different_assigned_staff_members_do_not_conflict(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $adminA = $this->makeUser('Admin');
        $adminB = $this->makeUser('Admin');
        $type = $this->makeType(['duration_minutes' => 60, 'buffer_before_minutes' => 30, 'buffer_after_minutes' => 30]);
        $date = $this->nextDateForWeekday(6);
        $this->setWeekly($adminA, 6, '00:00', '23:59');
        $this->setWeekly($adminB, 6, '00:00', '23:59');

        Sanctum::actingAs($superAdmin);
        $this->postJson('/api/appointments', $this->bookingPayload($type, $adminA, $date, '10:00'))->assertStatus(201);
        $this->postJson('/api/appointments', $this->bookingPayload($type, $adminB, $date, '10:00'))->assertStatus(201);
    }

    // ── Non-blocking terminal statuses ─────────────────────────────────────

    public function test_cancelled_appointment_does_not_block_a_buffered_slot(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType(['duration_minutes' => 60, 'buffer_after_minutes' => 30]);
        $date = $this->nextDateForWeekday(0);
        $this->setWeekly($admin, 0, '00:00', '23:59');

        Sanctum::actingAs($superAdmin);
        $store = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '10:00'));
        $id = $store->json('id');
        $this->postJson("/api/appointments/{$id}/cancel")->assertStatus(200);

        // Would have conflicted with the (now cancelled) appointment's buffer.
        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '11:10'));
        $response->assertStatus(201);
    }

    public function test_declined_completed_and_no_show_appointments_do_not_block(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType(['duration_minutes' => 30, 'requires_confirmation' => true]);
        $date = $this->nextDateForWeekday(1);
        $this->setWeekly($admin, 1, '00:00', '23:59');

        Sanctum::actingAs($superAdmin);

        $declined = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '09:00'));
        $this->postJson("/api/appointments/{$declined->json('id')}/decline")->assertStatus(200);
        $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '09:00'))
            ->assertStatus(201)->json('id');

        $noShowType = $this->makeType(['duration_minutes' => 30]);
        $noShow = $this->postJson('/api/appointments', $this->bookingPayload($noShowType, $admin, $date, '10:00'));
        $this->postJson("/api/appointments/{$noShow->json('id')}/no-show")->assertStatus(200);
        $this->postJson('/api/appointments', $this->bookingPayload($noShowType, $admin, $date, '10:00'))->assertStatus(201);

        $completedType = $this->makeType(['duration_minutes' => 30]);
        $completed = $this->postJson('/api/appointments', $this->bookingPayload($completedType, $admin, $date, '11:00'));
        $this->postJson("/api/appointments/{$completed->json('id')}/complete")->assertStatus(200);
        $this->postJson('/api/appointments', $this->bookingPayload($completedType, $admin, $date, '11:00'))->assertStatus(201);
    }

    // ── check-availability consistency ────────────────────────────────────

    public function test_check_availability_and_final_creation_are_consistent(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $existingType = $this->makeType(['duration_minutes' => 60, 'buffer_after_minutes' => 30]);
        $newType = $this->makeType(['duration_minutes' => 30]);
        $date = $this->nextDateForWeekday(2);
        $this->setWeekly($admin, 2, '00:00', '23:59');

        Sanctum::actingAs($superAdmin);
        $this->postJson('/api/appointments', $this->bookingPayload($existingType, $admin, $date, '10:00'))->assertStatus(201);

        // Preview says unavailable...
        $preview = $this->postJson('/api/appointments/check-availability', [
            'appointment_type_id' => $newType->id, 'assigned_user_id' => $admin->id,
            'date' => $date, 'start_time' => '11:10', 'timezone' => 'Europe/London',
        ]);
        $preview->assertStatus(200)->assertJsonPath('available', false);

        // ...and the real create agrees.
        $this->postJson('/api/appointments', $this->bookingPayload($newType, $admin, $date, '11:10'))->assertStatus(409);

        // A genuinely free slot: preview says available, and creation succeeds.
        $freePreview = $this->postJson('/api/appointments/check-availability', [
            'appointment_type_id' => $newType->id, 'assigned_user_id' => $admin->id,
            'date' => $date, 'start_time' => '14:00', 'timezone' => 'Europe/London',
        ]);
        $freePreview->assertStatus(200)->assertJsonPath('available', true);
        $this->postJson('/api/appointments', $this->bookingPayload($newType, $admin, $date, '14:00'))->assertStatus(201);
    }

    // ── Admin self-assignment / eligibility (secondary integrity checks) ──

    public function test_admin_cannot_bypass_forced_self_assignment_with_a_null_assignee(): void
    {
        $admin = $this->makeUser('Admin');
        $type = $this->makeType();
        $date = $this->nextDateForWeekday(3);
        $this->setWeekly($admin, 3, '00:00', '23:59');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, $admin, $date, '10:00', ['assigned_user_id' => null]));
        $response->assertStatus(201);
        $this->assertSame($admin->id, $response->json('assigned_user_id'));
    }

    public function test_admin_cannot_assign_an_appointment_to_another_user(): void
    {
        $adminA = $this->makeUser('Admin');
        $adminB = $this->makeUser('Admin');
        $type = $this->makeType();
        $date = $this->nextDateForWeekday(4);
        Sanctum::actingAs($adminA);

        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, $adminB, $date, '10:00'));
        $response->assertStatus(403);
    }

    public function test_inactive_banned_and_client_role_assignees_remain_rejected(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $type = $this->makeType();
        $date = $this->nextDateForWeekday(5);
        Sanctum::actingAs($superAdmin);

        $inactive = $this->makeUser('Admin');
        $inactive->update(['is_active' => false]);
        $this->postJson('/api/appointments', $this->bookingPayload($type, $inactive, $date, '10:00'))->assertStatus(422);

        $banned = $this->makeUser('Admin');
        $banned->update(['banned_at' => now()]);
        $this->postJson('/api/appointments', $this->bookingPayload($type, $banned, $date, '11:00'))->assertStatus(422);

        $client = $this->makeUser('Client');
        $this->postJson('/api/appointments', $this->bookingPayload($type, $client, $date, '12:00'))->assertStatus(422);
    }
}
