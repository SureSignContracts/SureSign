<?php

namespace Tests\Feature;

use App\Models\AppointmentAvailability;
use App\Models\AppointmentType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Appointments & Scheduling — Phase 1 (Foundation).
 *
 * Phase 2 layered real availability validation on top of these same
 * endpoints, so any test here that assigns a staff member to an
 * appointment and expects success now grants that staff member
 * open (all-week, all-day) availability first via grantOpenAvailability() —
 * otherwise Phase 2's "not available" check would reject it for a reason
 * unrelated to what each of these Phase 1 tests actually verifies.
 */
class AppointmentsPhase1Test extends TestCase
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

    private function grantOpenAvailability(User $staff): void
    {
        for ($weekday = 0; $weekday <= 6; $weekday++) {
            AppointmentAvailability::create([
                'user_id' => $staff->id, 'weekday' => $weekday,
                'start_time' => '00:00', 'end_time' => '23:59', 'is_active' => true,
            ]);
        }
    }

    private function makeType(array $overrides = []): AppointmentType
    {
        static $n = 0;
        $n++;

        return AppointmentType::create(array_merge([
            'name' => "Type {$n}", 'slug' => "type-{$n}",
            'duration_minutes' => 30, 'is_active' => true, 'assignment_mode' => 'manual',
            'meeting_method' => 'tbc', 'requires_confirmation' => false,
        ], $overrides));
    }

    private function bookingPayload(AppointmentType $type, array $overrides = []): array
    {
        return array_merge([
            'appointment_type_id' => $type->id,
            'attendee_name'  => 'Jane Doe',
            'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London',
            'date'       => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'timezone'   => 'Europe/London',
        ], $overrides);
    }

    // ── CRUD ──────────────────────────────────────────────────────────────

    public function test_super_admin_can_create_view_update_and_delete_an_appointment(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $type = $this->makeType();
        Sanctum::actingAs($superAdmin);

        $store = $this->postJson('/api/appointments', $this->bookingPayload($type));
        $store->assertStatus(201);
        $store->assertJsonPath('status', 'confirmed');
        $id = $store->json('id');

        $this->getJson("/api/appointments/{$id}")->assertStatus(200);

        $update = $this->putJson("/api/appointments/{$id}", ['attendee_name' => 'Jane Updated']);
        $update->assertStatus(200);
        $this->assertDatabaseHas('appointments', ['id' => $id, 'attendee_name' => 'Jane Updated']);

        $this->deleteJson("/api/appointments/{$id}")->assertStatus(204);
        $this->assertSoftDeleted('appointments', ['id' => $id]);
    }

    public function test_appointment_reference_is_generated_in_apt_format(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $type = $this->makeType();
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/appointments', $this->bookingPayload($type));
        $response->assertStatus(201);
        $this->assertMatchesRegularExpression('/^APT-\d{6}$/', $response->json('reference'));
    }

    public function test_appointment_type_requiring_confirmation_creates_a_pending_appointment(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $type = $this->makeType(['requires_confirmation' => true]);
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/appointments', $this->bookingPayload($type));
        $response->assertStatus(201);
        $response->assertJsonPath('status', 'pending_confirmation');
    }

    // ── Permissions ───────────────────────────────────────────────────────

    public function test_client_cannot_access_appointments_at_all(): void
    {
        $client = $this->makeUser('Client');
        $type = $this->makeType();
        Sanctum::actingAs($client);

        $this->getJson('/api/appointments')->assertStatus(403);
        $this->postJson('/api/appointments', $this->bookingPayload($type))->assertStatus(403);
    }

    public function test_admin_cannot_manage_appointment_types(): void
    {
        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/appointment-types')->assertStatus(200);
        $this->postJson('/api/appointment-types', [
            'name' => 'New Type', 'slug' => 'new-type', 'duration_minutes' => 30,
        ])->assertStatus(403);
    }

    public function test_super_admin_can_manage_appointment_types(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/appointment-types', [
            'name' => 'New Type', 'slug' => 'new-type', 'duration_minutes' => 30,
        ]);
        $response->assertStatus(201);
        $this->assertDatabaseHas('appointment_types', ['slug' => 'new-type']);
    }

    public function test_admin_can_only_manage_their_own_assigned_appointments(): void
    {
        $adminA = $this->makeUser('Admin');
        $adminB = $this->makeUser('Admin');
        $type = $this->makeType();
        $this->grantOpenAvailability($adminA);

        Sanctum::actingAs($adminA);
        $store = $this->postJson('/api/appointments', $this->bookingPayload($type, ['assigned_user_id' => $adminA->id]));
        $store->assertStatus(201);
        $id = $store->json('id');

        Sanctum::actingAs($adminB);
        $this->putJson("/api/appointments/{$id}", ['attendee_name' => 'Hijacked'])->assertStatus(403);
        $this->postJson("/api/appointments/{$id}/confirm")->assertStatus(403);
    }

    /**
     * Only a Super Admin can leave an appointment unassigned (approved
     * Phase 2 decision — Admin always self-assigns), so this uses a
     * Super-Admin-created unassigned appointment to verify the same
     * view-but-not-manage boundary for Admin.
     */
    public function test_admin_can_view_but_not_manage_unassigned_appointments(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType();

        Sanctum::actingAs($superAdmin);
        $store = $this->postJson('/api/appointments', $this->bookingPayload($type));
        $store->assertStatus(201);
        $this->assertNull($store->json('assigned_user_id'));
        $id = $store->json('id');

        Sanctum::actingAs($admin);
        $this->getJson("/api/appointments/{$id}")->assertStatus(200);
        $this->postJson("/api/appointments/{$id}/confirm")->assertStatus(403);
    }

    public function test_admin_cannot_create_an_appointment_assigned_to_someone_else(): void
    {
        $adminA = $this->makeUser('Admin');
        $adminB = $this->makeUser('Admin');
        $type = $this->makeType();
        Sanctum::actingAs($adminA);

        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, ['assigned_user_id' => $adminB->id]));
        $response->assertStatus(403);
    }

    public function test_only_super_admin_can_assign_an_appointment(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $targetAdmin = $this->makeUser('Admin');
        $type = $this->makeType();
        $this->grantOpenAvailability($targetAdmin);

        Sanctum::actingAs($superAdmin);
        $store = $this->postJson('/api/appointments', $this->bookingPayload($type));
        $id = $store->json('id');

        Sanctum::actingAs($admin);
        $this->postJson("/api/appointments/{$id}/assign", ['assigned_user_id' => $targetAdmin->id])->assertStatus(403);

        Sanctum::actingAs($superAdmin);
        $this->postJson("/api/appointments/{$id}/assign", ['assigned_user_id' => $targetAdmin->id])
            ->assertStatus(200)
            ->assertJsonPath('assigned_user_id', $targetAdmin->id);
    }

    // ── Status transitions ────────────────────────────────────────────────

    public function test_valid_status_transitions_succeed(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $type = $this->makeType(['requires_confirmation' => true]);
        Sanctum::actingAs($superAdmin);

        $store = $this->postJson('/api/appointments', $this->bookingPayload($type));
        $id = $store->json('id');
        $store->assertJsonPath('status', 'pending_confirmation');

        $this->postJson("/api/appointments/{$id}/confirm")->assertStatus(200)->assertJsonPath('status', 'confirmed');
        $this->postJson("/api/appointments/{$id}/complete")->assertStatus(200)->assertJsonPath('status', 'completed');

        $this->assertDatabaseHas('activity_logs', ['action' => 'appointment.confirmed']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'appointment.completed']);
    }

    public function test_invalid_status_transition_is_rejected(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $type = $this->makeType();
        Sanctum::actingAs($superAdmin);

        $store = $this->postJson('/api/appointments', $this->bookingPayload($type));
        $id = $store->json('id');
        $store->assertJsonPath('status', 'confirmed');

        // confirmed -> pending_confirmation is not an allowed transition
        $response = $this->postJson("/api/appointments/{$id}/decline");
        // confirmed can decline? No — TRANSITIONS['confirmed'] = cancelled/completed/no_show only.
        $response->assertStatus(422);
        $this->assertDatabaseHas('appointments', ['id' => $id, 'status' => 'confirmed']);
    }

    public function test_no_show_and_completed_are_terminal(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $type = $this->makeType();
        Sanctum::actingAs($superAdmin);

        $store = $this->postJson('/api/appointments', $this->bookingPayload($type));
        $id = $store->json('id');

        $this->postJson("/api/appointments/{$id}/no-show")->assertStatus(200)->assertJsonPath('status', 'no_show');
        $this->postJson("/api/appointments/{$id}/confirm")->assertStatus(422);
    }

    // ── Overlap detection ─────────────────────────────────────────────────

    public function test_double_booking_the_same_assigned_user_is_rejected(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType(['duration_minutes' => 60]);
        $this->grantOpenAvailability($admin);
        Sanctum::actingAs($superAdmin);

        $first = $this->postJson('/api/appointments', $this->bookingPayload($type, [
            'assigned_user_id' => $admin->id, 'start_time' => '10:00',
        ]));
        $first->assertStatus(201);

        // Overlaps 10:00-11:00
        $second = $this->postJson('/api/appointments', $this->bookingPayload($type, [
            'assigned_user_id' => $admin->id, 'start_time' => '10:30',
        ]));
        $second->assertStatus(409);
    }

    public function test_non_overlapping_appointments_for_the_same_user_succeed(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType(['duration_minutes' => 30]);
        $this->grantOpenAvailability($admin);
        Sanctum::actingAs($superAdmin);

        $this->postJson('/api/appointments', $this->bookingPayload($type, [
            'assigned_user_id' => $admin->id, 'start_time' => '10:00',
        ]))->assertStatus(201);

        $this->postJson('/api/appointments', $this->bookingPayload($type, [
            'assigned_user_id' => $admin->id, 'start_time' => '10:30',
        ]))->assertStatus(201);
    }

    public function test_rescheduling_into_a_conflicting_slot_is_rejected(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType(['duration_minutes' => 60]);
        $this->grantOpenAvailability($admin);
        Sanctum::actingAs($superAdmin);

        $blocker = $this->postJson('/api/appointments', $this->bookingPayload($type, [
            'assigned_user_id' => $admin->id, 'start_time' => '13:00',
        ]));
        $blocker->assertStatus(201);

        $movable = $this->postJson('/api/appointments', $this->bookingPayload($type, [
            'assigned_user_id' => $admin->id, 'start_time' => '09:00',
        ]));
        $movable->assertStatus(201);
        $movableId = $movable->json('id');

        // Moving into 13:00-14:00 conflicts with $blocker
        $reschedule = $this->postJson("/api/appointments/{$movableId}/reschedule", [
            'date' => now()->addDay()->toDateString(), 'start_time' => '13:30', 'timezone' => 'Europe/London',
        ]);
        $reschedule->assertStatus(409);
    }

    public function test_rescheduling_an_appointment_does_not_conflict_with_itself(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $type = $this->makeType(['duration_minutes' => 30]);
        $this->grantOpenAvailability($admin);
        Sanctum::actingAs($superAdmin);

        $store = $this->postJson('/api/appointments', $this->bookingPayload($type, [
            'assigned_user_id' => $admin->id, 'start_time' => '10:00',
        ]));
        $id = $store->json('id');

        // Reschedule to a slightly later time on the same day — should not
        // conflict with its own original (now superseded) slot.
        $response = $this->postJson("/api/appointments/{$id}/reschedule", [
            'date' => now()->addDay()->toDateString(), 'start_time' => '10:15', 'timezone' => 'Europe/London',
        ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('activity_logs', ['action' => 'appointment.rescheduled']);
    }

    public function test_reschedule_does_not_change_status(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $type = $this->makeType();
        Sanctum::actingAs($superAdmin);

        $store = $this->postJson('/api/appointments', $this->bookingPayload($type));
        $id = $store->json('id');
        $store->assertJsonPath('status', 'confirmed');

        $response = $this->postJson("/api/appointments/{$id}/reschedule", [
            'date' => now()->addDay()->toDateString(), 'start_time' => '14:00', 'timezone' => 'Europe/London',
        ]);
        $response->assertStatus(200);
        $response->assertJsonPath('status', 'confirmed');
    }

    // ── Cross-organisation / general safety ───────────────────────────────

    public function test_inactive_appointment_type_cannot_be_booked(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $type = $this->makeType(['is_active' => false]);
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/appointments', $this->bookingPayload($type));
        $response->assertStatus(422);
    }

    public function test_appointment_cannot_be_assigned_to_a_banned_user(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $bannedAdmin = $this->makeUser('Admin');
        $bannedAdmin->update(['banned_at' => now()]);
        $type = $this->makeType();
        Sanctum::actingAs($superAdmin);

        $store = $this->postJson('/api/appointments', $this->bookingPayload($type));
        $id = $store->json('id');

        $response = $this->postJson("/api/appointments/{$id}/assign", ['assigned_user_id' => $bannedAdmin->id]);
        $response->assertStatus(422);
    }
}
