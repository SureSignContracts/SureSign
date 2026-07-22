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
 * Appointments & Scheduling — Production Readiness Fixes.
 *
 * Finding #1: AppointmentAvailabilityService::assertBookable() only ran
 * for staff-assigned appointments, so minimum notice / maximum advance
 * (Appointment Type business rules, not staff-availability rules) were
 * silently skipped for every unassigned booking — the default
 * configuration for every seeded Appointment Type, including the public
 * "Book a Demo" flow. Fixed by extracting those two checks into
 * AppointmentAvailabilityService::assertTypeBookable(), now called
 * unconditionally by AppointmentSchedulingService::withConflictCheck()
 * regardless of whether a staff member is assigned.
 *
 * Finding #2: AppointmentController::checkAvailability() accepted an
 * arbitrary assigned_user_id with no ownership check, letting any Admin
 * probe another staff member's availability (including their name and
 * blocked-period existence in the rejection reason) — a permission leak
 * relative to the rest of the module, which correctly restricts Admin to
 * their own availability everywhere else. Fixed with the same ownership
 * rule used throughout AppointmentAvailabilityController.
 *
 * Explicitly NOT covered here (out of scope for this pass, per Finding #3's
 * independent verification): scheduling concurrency/locking behaviour,
 * buffered conflict detection, slot generation.
 */
class AppointmentsProductionFixesTest extends TestCase
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

    private function grantOpenAvailability(User $staff): void
    {
        for ($weekday = 0; $weekday <= 6; $weekday++) {
            AppointmentAvailability::create([
                'user_id' => $staff->id, 'weekday' => $weekday,
                'start_time' => '00:00', 'end_time' => '23:59', 'is_active' => true,
            ]);
        }
    }

    private function bookingPayload(AppointmentType $type, string $date, string $startTime, array $overrides = []): array
    {
        return array_merge([
            'appointment_type_id' => $type->id,
            'attendee_name'  => 'Jane Doe',
            'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London',
            'date'       => $date,
            'start_time' => $startTime,
            'timezone'   => 'Europe/London',
        ], $overrides);
    }

    // ── Finding #1: manual/unassigned bookings ─────────────────────────────

    public function test_manual_unassigned_booking_in_the_past_is_rejected(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $type = $this->makeType();
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, '2020-01-01', '10:00'));
        $response->assertStatus(409);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_manual_unassigned_booking_inside_minimum_notice_is_rejected(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $type = $this->makeType(['min_notice_hours' => 72]);
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, now()->addDay()->toDateString(), '10:00'));
        $response->assertStatus(409);
    }

    public function test_manual_unassigned_booking_beyond_maximum_advance_is_rejected(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $type = $this->makeType(['max_advance_days' => 5]);
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, now()->addDays(30)->toDateString(), '10:00'));
        $response->assertStatus(409);
    }

    public function test_manual_unassigned_booking_within_valid_range_succeeds(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $type = $this->makeType(['min_notice_hours' => 1, 'max_advance_days' => 30]);
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, now()->addDays(3)->toDateString(), '10:00'));
        $response->assertStatus(201);
        $this->assertNull($response->json('assigned_user_id'));
    }

    public function test_fixed_staff_assigned_booking_still_enforces_notice_and_advance_identically(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $this->grantOpenAvailability($admin);

        $tooSoonType = $this->makeType(['min_notice_hours' => 72]);
        $tooFarType = $this->makeType(['max_advance_days' => 5]);
        Sanctum::actingAs($superAdmin);

        $tooSoon = $this->postJson('/api/appointments', $this->bookingPayload($tooSoonType, now()->addDay()->toDateString(), '10:00', ['assigned_user_id' => $admin->id]));
        $tooSoon->assertStatus(409);

        $tooFar = $this->postJson('/api/appointments', $this->bookingPayload($tooFarType, now()->addDays(30)->toDateString(), '10:00', ['assigned_user_id' => $admin->id]));
        $tooFar->assertStatus(409);
    }

    public function test_existing_staff_availability_validation_is_unchanged(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        // No weekly availability granted at all.
        $type = $this->makeType();
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/appointments', $this->bookingPayload($type, now()->addDays(3)->toDateString(), '10:00', ['assigned_user_id' => $admin->id]));
        $response->assertStatus(409);
    }

    public function test_public_manual_mode_booking_enforces_minimum_notice(): void
    {
        $type = $this->makeType(['is_public' => true, 'min_notice_hours' => 72]);

        $response = $this->postJson("/api/public/appointment-types/{$type->slug}/book", array_merge(
            $this->bookingPayload($type, now()->addDay()->toDateString(), '10:00'),
            ['appointment_type_slug' => $type->slug, 'consent' => true]
        ));
        $response->assertStatus(409);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_public_manual_mode_booking_enforces_maximum_advance(): void
    {
        $type = $this->makeType(['is_public' => true, 'max_advance_days' => 5]);

        $response = $this->postJson("/api/public/appointment-types/{$type->slug}/book", array_merge(
            $this->bookingPayload($type, now()->addDays(30)->toDateString(), '10:00'),
            ['appointment_type_slug' => $type->slug, 'consent' => true]
        ));
        $response->assertStatus(409);
    }

    public function test_public_manual_mode_booking_within_valid_range_succeeds(): void
    {
        $type = $this->makeType(['is_public' => true, 'min_notice_hours' => 1, 'max_advance_days' => 30]);

        $response = $this->postJson("/api/public/appointment-types/{$type->slug}/book", array_merge(
            $this->bookingPayload($type, now()->addDays(3)->toDateString(), '10:00'),
            ['appointment_type_slug' => $type->slug, 'consent' => true]
        ));
        $response->assertStatus(201);
    }

    public function test_check_availability_preview_for_unassigned_booking_matches_real_creation(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $type = $this->makeType(['max_advance_days' => 5]);
        Sanctum::actingAs($superAdmin);

        $preview = $this->postJson('/api/appointments/check-availability', [
            'appointment_type_id' => $type->id,
            'date' => now()->addDays(30)->toDateString(),
            'start_time' => '10:00',
            'timezone' => 'Europe/London',
        ]);
        $preview->assertStatus(200)->assertJsonPath('available', false);

        $create = $this->postJson('/api/appointments', $this->bookingPayload($type, now()->addDays(30)->toDateString(), '10:00'));
        $create->assertStatus(409);
    }

    // ── Finding #2: checkAvailability ownership ────────────────────────────

    public function test_admin_can_check_their_own_availability(): void
    {
        $admin = $this->makeUser('Admin');
        $this->grantOpenAvailability($admin);
        $type = $this->makeType();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/appointments/check-availability', [
            'appointment_type_id' => $type->id,
            'assigned_user_id' => $admin->id,
            'date' => now()->addDays(3)->toDateString(),
            'start_time' => '10:00',
            'timezone' => 'Europe/London',
        ]);
        $response->assertStatus(200)->assertJsonPath('available', true);
    }

    public function test_admin_cannot_check_another_staff_members_availability(): void
    {
        $adminA = $this->makeUser('Admin');
        $adminB = $this->makeUser('Admin');
        $this->grantOpenAvailability($adminB);
        $type = $this->makeType();
        Sanctum::actingAs($adminA);

        $response = $this->postJson('/api/appointments/check-availability', [
            'appointment_type_id' => $type->id,
            'assigned_user_id' => $adminB->id,
            'date' => now()->addDays(3)->toDateString(),
            'start_time' => '10:00',
            'timezone' => 'Europe/London',
        ]);
        $response->assertStatus(403);
    }

    public function test_super_admin_can_check_anyones_availability(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $this->grantOpenAvailability($admin);
        $type = $this->makeType();
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/appointments/check-availability', [
            'appointment_type_id' => $type->id,
            'assigned_user_id' => $admin->id,
            'date' => now()->addDays(3)->toDateString(),
            'start_time' => '10:00',
            'timezone' => 'Europe/London',
        ]);
        $response->assertStatus(200)->assertJsonPath('available', true);
    }

    public function test_client_has_no_access_to_check_availability(): void
    {
        $client = $this->makeUser('Client');
        $type = $this->makeType();
        Sanctum::actingAs($client);

        $response = $this->postJson('/api/appointments/check-availability', [
            'appointment_type_id' => $type->id,
            'date' => now()->addDays(3)->toDateString(),
            'start_time' => '10:00',
            'timezone' => 'Europe/London',
        ]);
        $response->assertStatus(403);
    }

    public function test_omitted_assigned_user_id_requires_no_authorization_and_checks_type_rules_only(): void
    {
        $admin = $this->makeUser('Admin');
        $type = $this->makeType();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/appointments/check-availability', [
            'appointment_type_id' => $type->id,
            'date' => now()->addDays(3)->toDateString(),
            'start_time' => '10:00',
            'timezone' => 'Europe/London',
        ]);
        $response->assertStatus(200)->assertJsonPath('available', true);
    }

    public function test_manipulated_assigned_user_id_payload_cannot_bypass_authorization(): void
    {
        $adminA = $this->makeUser('Admin');
        $adminB = $this->makeUser('Admin');
        $type = $this->makeType();
        Sanctum::actingAs($adminA);

        // Same rejection whether the id is sent as an int or a numeric string.
        $response = $this->postJson('/api/appointments/check-availability', [
            'appointment_type_id' => $type->id,
            'assigned_user_id' => (string) $adminB->id,
            'date' => now()->addDays(3)->toDateString(),
            'start_time' => '10:00',
            'timezone' => 'Europe/London',
        ]);
        $response->assertStatus(403);
    }

    public function test_unauthorized_check_availability_response_does_not_leak_staff_details(): void
    {
        $adminA = $this->makeUser('Admin');
        $adminB = $this->makeUser('Admin');
        $type = $this->makeType();
        Sanctum::actingAs($adminA);

        $response = $this->postJson('/api/appointments/check-availability', [
            'appointment_type_id' => $type->id,
            'assigned_user_id' => $adminB->id,
            'date' => now()->addDays(3)->toDateString(),
            'start_time' => '10:00',
            'timezone' => 'Europe/London',
        ]);
        $response->assertStatus(403);
        $this->assertStringNotContainsString($adminB->name, $response->getContent());
    }
}
