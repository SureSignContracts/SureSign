<?php

namespace Tests\Feature;

use App\Models\AppointmentAvailability;
use App\Models\AppointmentAvailabilityOverride;
use App\Models\AppointmentType;
use App\Models\ConsultancyService;
use App\Models\Organization;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Services\AppointmentAvailabilityService;
use App\Services\Consultancy\ConsultancyBookingReadinessService;
use App\Services\Consultancy\ConsultancyCatalogueService;
use App\Services\Consultancy\ConsultancyConsultantResolver;
use App\Support\Appointments\AvailabilityContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Consultancy Live Booking Upgrade, Stage 1 — availability context
 * foundation, dynamic consultant resolution, and dedicated Consultancy
 * availability admin surface. See
 * internal-docs/commercial/consultancy-live-booking-phase-0-architecture-review.md.
 *
 * Explicitly excludes anything from later stages: no Stripe, no Google, no
 * reservations, no payment-confirmed workflows.
 */
class ConsultancyLiveBookingStage1Test extends TestCase
{
    use RefreshDatabase;

    private function makeOrgAndUser(string $role): array
    {
        static $n = 0;
        $n++;
        $org = Organization::create(['name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

        return [$org, $user];
    }

    private function makeService(array $overrides = []): ConsultancyService
    {
        static $n = 0;
        $n++;

        return app(ConsultancyCatalogueService::class)->create(array_merge([
            'code'                             => "stage1-service-{$n}",
            'display_name'                     => "Stage 1 Service {$n}",
            'enabled'                          => true,
            'publicly_bookable'                => true,
            'available_to_existing_customers'  => true,
            'price_minor_units'                => 4000,
            'currency'                         => 'GBP',
            'duration_minutes'                 => 30,
            'requires_confirmation'            => false,
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

    private function configureConsultant(User $user): void
    {
        SuresignSetting::instance()->update(['consultancy_consultant_user_id' => $user->id]);
    }

    // ── Availability context isolation ──────────────────────────────────────

    public function test_appointments_context_availability_is_invisible_to_consultancy_and_vice_versa(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');

        app(AppointmentAvailabilityService::class)->setWeeklySchedule($staff, [
            ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '12:00'],
        ], $staff, AvailabilityContext::APPOINTMENTS);
        app(AppointmentAvailabilityService::class)->setWeeklySchedule($staff, [
            ['weekday' => 1, 'start_time' => '13:00', 'end_time' => '17:00'],
        ], $staff, AvailabilityContext::CONSULTANCY);

        $appointmentsWindows = app(AppointmentAvailabilityService::class)->getWeeklySchedule($staff, AvailabilityContext::APPOINTMENTS);
        $consultancyWindows = app(AppointmentAvailabilityService::class)->getWeeklySchedule($staff, AvailabilityContext::CONSULTANCY);

        $this->assertCount(1, $appointmentsWindows);
        $this->assertSame('09:00', $appointmentsWindows->first()->start_time);
        $this->assertCount(1, $consultancyWindows);
        $this->assertSame('13:00', $consultancyWindows->first()->start_time);
    }

    public function test_replacing_consultancy_weekly_schedule_does_not_touch_appointments_schedule(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = app(AppointmentAvailabilityService::class);

        $service->setWeeklySchedule($staff, [
            ['weekday' => 2, 'start_time' => '09:00', 'end_time' => '17:00'],
        ], $staff, AvailabilityContext::APPOINTMENTS);

        // Replacing Consultancy's schedule (a full delete-and-recreate for
        // that context only) must never delete the Appointments-context row.
        $service->setWeeklySchedule($staff, [
            ['weekday' => 2, 'start_time' => '10:00', 'end_time' => '11:00'],
        ], $staff, AvailabilityContext::CONSULTANCY);
        $service->setWeeklySchedule($staff, [], $staff, AvailabilityContext::CONSULTANCY);

        $this->assertCount(1, $service->getWeeklySchedule($staff, AvailabilityContext::APPOINTMENTS));
        $this->assertCount(0, $service->getWeeklySchedule($staff, AvailabilityContext::CONSULTANCY));
    }

    public function test_same_date_override_is_allowed_in_both_contexts_for_the_same_consultant(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = app(AppointmentAvailabilityService::class);
        $date = $this->nextDateForWeekday(3);

        // Identical overlapping hours on the SAME date, in two DIFFERENT
        // contexts, for the SAME consultant — must not conflict, since
        // sibling-overlap validation is scoped per-context.
        $service->createOverride($staff, ['local_date' => $date, 'start_time' => '09:00', 'end_time' => '12:00'], $staff, AvailabilityContext::APPOINTMENTS);
        $override = $service->createOverride($staff, ['local_date' => $date, 'start_time' => '09:00', 'end_time' => '12:00'], $staff, AvailabilityContext::CONSULTANCY);

        $this->assertSame(AvailabilityContext::CONSULTANCY, $override->context);
        $this->assertCount(1, $service->getOverrides($staff, null, null, AvailabilityContext::APPOINTMENTS));
        $this->assertCount(1, $service->getOverrides($staff, null, null, AvailabilityContext::CONSULTANCY));
    }

    public function test_invalid_context_is_rejected_not_silently_treated_as_consultancy(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = app(AppointmentAvailabilityService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->getWeeklySchedule($staff, 'book_a_demo_typo');
    }

    public function test_blocked_period_has_no_context_and_applies_regardless(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = app(AppointmentAvailabilityService::class);

        $period = $service->createBlockedPeriod($staff, [
            'starts_at' => now()->addDays(5),
            'ends_at'   => now()->addDays(5)->addHour(),
            'timezone'  => 'Europe/London',
        ], $staff);

        $this->assertArrayNotHasKey('context', $period->getAttributes());
    }

    // ── Consultant resolver ──────────────────────────────────────────────────

    public function test_resolver_returns_null_when_nothing_configured(): void
    {
        $this->assertNull(app(ConsultancyConsultantResolver::class)->resolve());
    }

    public function test_resolver_returns_the_configured_eligible_consultant(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);

        $resolved = app(ConsultancyConsultantResolver::class)->resolve();
        $this->assertSame($staff->id, $resolved->id);
    }

    public function test_resolver_fails_safe_when_configured_user_is_inactive(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $staff->update(['is_active' => false]);

        $this->assertNull(app(ConsultancyConsultantResolver::class)->resolve());
    }

    public function test_resolver_fails_safe_when_configured_user_is_banned(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $staff->update(['banned_at' => now()]);

        $this->assertNull(app(ConsultancyConsultantResolver::class)->resolve());
    }

    public function test_resolver_fails_safe_when_configured_user_is_deleted(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $staff->delete();

        $this->assertNull(app(ConsultancyConsultantResolver::class)->resolve());
    }

    public function test_resolver_fails_safe_when_configured_user_is_no_longer_admin_or_super_admin(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $staff->removeRole('Admin');

        $this->assertNull(app(ConsultancyConsultantResolver::class)->resolve());
    }

    public function test_changing_configured_consultant_does_not_alter_an_existing_appointment(): void
    {
        [, $staffA] = $this->makeOrgAndUser('Admin');
        [, $staffB] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staffA);
        app(AppointmentAvailabilityService::class)->setWeeklySchedule($staffA, [
            ['weekday' => 0, 'start_time' => '09:00', 'end_time' => '17:00'],
        ], $staffA, AvailabilityContext::CONSULTANCY);

        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService(['available_to_existing_customers' => true]);
        $date = $this->nextDateForWeekday(0);

        $booking = $this->actingAs($client)->postJson('/api/consultations', [
            'consultancy_service_code' => $service->code,
            'attendee_name'      => 'Jane Client',
            'attendee_email'     => 'jane@client.example.com',
            'attendee_timezone'  => 'Europe/London',
            'date'               => $date,
            'start_time'         => '10:00',
            'timezone'           => 'Europe/London',
            'title'              => 'A query',
            'description'        => 'A description.',
        ])->assertStatus(201)->json();

        $this->assertSame($staffA->id, $booking['assigned_user']['id'] ?? $this->fetchAssignedUserId($booking['id']));

        // Now change the configured consultant.
        $this->configureConsultant($staffB);

        $this->assertSame($staffA->id, $this->fetchAssignedUserId($booking['id']));
    }

    private function fetchAssignedUserId(int $appointmentId): ?int
    {
        return \App\Models\Appointment::find($appointmentId)->assigned_user_id;
    }

    // ── Cross-workflow conflict detection ────────────────────────────────────

    public function test_confirmed_book_a_demo_appointment_blocks_an_overlapping_consultancy_slot(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        app(AppointmentAvailabilityService::class)->setWeeklySchedule($staff, [
            ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
        ], $staff, AvailabilityContext::CONSULTANCY);

        $demoType = AppointmentType::create([
            'name' => 'Book a Demo', 'slug' => 'stage1-demo', 'duration_minutes' => 30,
            'is_public' => true, 'is_active' => true,
        ]);
        $date = $this->nextDateForWeekday(1);
        $startsAt = \App\Services\TimezoneResolver::buildLocalInstant($date, '10:00', 'Europe/London');

        \App\Models\Appointment::create([
            'reference' => 'DEMO0001', 'appointment_type_id' => $demoType->id, 'assigned_user_id' => $staff->id,
            'attendee_name' => 'Existing Demo Attendee', 'attendee_email' => 'demo@example.com',
            'attendee_timezone' => 'Europe/London', 'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addMinutes(30),
            'booking_timezone' => 'Europe/London', 'status' => 'confirmed', 'booking_source' => 'admin_created',
        ]);

        $service = $this->makeService(['available_to_existing_customers' => true]);
        $slotsResponse = $this->getJson("/api/public/consultancy-services/{$service->code}/slots?date={$date}&timezone=Europe/London");
        $slotsResponse->assertStatus(200);

        $this->assertNotContains('10:00', collect($slotsResponse->json('slots'))->pluck('time')->all());
    }

    public function test_confirmed_consultancy_appointment_blocks_an_overlapping_book_a_demo_slot(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        app(AppointmentAvailabilityService::class)->setWeeklySchedule($staff, [
            ['weekday' => 2, 'start_time' => '09:00', 'end_time' => '17:00'],
        ], $staff, AvailabilityContext::APPOINTMENTS);

        $demoType = AppointmentType::create([
            'name' => 'Book a Demo', 'slug' => 'stage1-demo-2', 'duration_minutes' => 30,
            'is_public' => true, 'is_active' => true, 'assignment_mode' => 'fixed', 'default_assigned_user_id' => $staff->id,
        ]);
        $date = $this->nextDateForWeekday(2);

        $consultancyType = $this->makeService(['available_to_existing_customers' => true])->appointmentType;
        $startsAt = \App\Services\TimezoneResolver::buildLocalInstant($date, '11:00', 'Europe/London');
        \App\Models\Appointment::create([
            'reference' => 'CONS0001', 'appointment_type_id' => $consultancyType->id, 'assigned_user_id' => $staff->id,
            'attendee_name' => 'Existing Consultation Attendee', 'attendee_email' => 'consult@example.com',
            'attendee_timezone' => 'Europe/London', 'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addMinutes(30),
            'booking_timezone' => 'Europe/London', 'status' => 'confirmed', 'booking_source' => 'admin_created',
        ]);

        $slotsResponse = $this->getJson("/api/public/appointment-types/{$demoType->slug}/slots?date={$date}&timezone=Europe/London");
        $slotsResponse->assertStatus(200);

        $this->assertNotContains('11:00', collect($slotsResponse->json('slots'))->pluck('time')->all());
    }

    // ── Readiness ─────────────────────────────────────────────────────────────

    public function test_readiness_is_false_when_nothing_is_configured(): void
    {
        $readiness = app(ConsultancyBookingReadinessService::class)->check();

        $this->assertFalse($readiness['consultant_configured']);
        $this->assertFalse($readiness['ready']);
    }

    public function test_readiness_is_true_once_consultant_availability_and_service_all_exist(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        app(AppointmentAvailabilityService::class)->setWeeklySchedule($staff, [
            ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
        ], $staff, AvailabilityContext::CONSULTANCY);
        $this->makeService(['publicly_bookable' => true]);

        $readiness = app(ConsultancyBookingReadinessService::class)->check();

        $this->assertTrue($readiness['ready']);
    }

    public function test_readiness_response_never_mentions_stripe_or_google(): void
    {
        $readiness = app(ConsultancyBookingReadinessService::class)->check();

        $this->assertArrayNotHasKey('stripe_configured', $readiness);
        $this->assertArrayNotHasKey('google_connected', $readiness);
    }

    // ── Consultancy Availability admin surface ───────────────────────────────

    public function test_admin_availability_endpoint_reports_not_ready_when_no_consultant_configured(): void
    {
        [, $admin] = $this->makeOrgAndUser('Admin');

        $response = $this->actingAs($admin)->getJson('/api/admin/consultancy/availability');

        $response->assertStatus(200)->assertJsonPath('ready', false);
    }

    public function test_admin_can_set_consultancy_weekly_schedule_via_dedicated_endpoint(): void
    {
        [, $admin] = $this->makeOrgAndUser('Admin');
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);

        $response = $this->actingAs($admin)->putJson('/api/admin/consultancy/availability', [
            'windows' => [
                ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertCount(1, AppointmentAvailability::where('user_id', $staff->id)->where('context', AvailabilityContext::CONSULTANCY)->get());
    }

    public function test_admin_can_manage_shared_blocked_periods_via_consultancy_endpoint(): void
    {
        [, $admin] = $this->makeOrgAndUser('Admin');
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);

        $response = $this->actingAs($admin)->postJson('/api/admin/consultancy/availability/blocked-periods', [
            'start_date' => now()->addDays(10)->toDateString(),
            'start_time' => '09:00',
            'end_date'   => now()->addDays(10)->toDateString(),
            'end_time'   => '17:00',
            'timezone'   => 'Europe/London',
            'reason'     => 'Annual leave',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('appointment_blocked_periods', ['user_id' => $staff->id, 'reason' => 'Annual leave']);
    }

    public function test_consultancy_override_cannot_be_edited_via_the_appointments_availability_endpoint(): void
    {
        [, $admin] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($admin);
        $date = $this->nextDateForWeekday(4);

        $override = app(AppointmentAvailabilityService::class)->createOverride(
            $admin, ['local_date' => $date, 'is_unavailable' => true], $admin, AvailabilityContext::CONSULTANCY,
        );

        // Admin manages their OWN appointments availability via the generic
        // endpoint — but this override belongs to the Consultancy context,
        // so it must be invisible there.
        $response = $this->actingAs($admin)->deleteJson("/api/appointment-availability/me/overrides/{$override->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('appointment_availability_overrides', ['id' => $override->id]);
    }

    // ── Security ──────────────────────────────────────────────────────────────

    public function test_admin_cannot_change_the_configured_consultant_only_super_admin_can(): void
    {
        [, $admin] = $this->makeOrgAndUser('Admin');
        [, $staff] = $this->makeOrgAndUser('Admin');

        $response = $this->actingAs($admin)->putJson('/api/admin/consultancy/settings/consultant', ['user_id' => $staff->id]);

        $response->assertStatus(403);
    }

    public function test_super_admin_can_change_the_configured_consultant(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        [, $staff] = $this->makeOrgAndUser('Admin');

        $response = $this->actingAs($superAdmin)->putJson('/api/admin/consultancy/settings/consultant', ['user_id' => $staff->id]);

        $response->assertStatus(200);
        $this->assertSame($staff->id, SuresignSetting::instance()->consultancy_consultant_user_id);
    }

    public function test_ineligible_user_cannot_be_configured_as_consultant(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        [, $client] = $this->makeOrgAndUser('Client');

        $response = $this->actingAs($superAdmin)->putJson('/api/admin/consultancy/settings/consultant', ['user_id' => $client->id]);

        $response->assertStatus(422);
        $this->assertNull(SuresignSetting::instance()->consultancy_consultant_user_id);
    }

    public function test_banned_user_cannot_be_configured_as_consultant(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        [, $staff] = $this->makeOrgAndUser('Admin');
        $staff->update(['banned_at' => now()]);

        $response = $this->actingAs($superAdmin)->putJson('/api/admin/consultancy/settings/consultant', ['user_id' => $staff->id]);

        $response->assertStatus(422);
    }

    public function test_client_cannot_read_consultancy_availability_admin_endpoint(): void
    {
        [, $client] = $this->makeOrgAndUser('Client');

        $this->actingAs($client)->getJson('/api/admin/consultancy/availability')->assertStatus(403);
    }

    public function test_unauthenticated_request_is_denied_on_consultancy_settings(): void
    {
        $this->getJson('/api/admin/consultancy/settings/consultant')->assertStatus(401);
    }

    public function test_price_and_duration_are_never_trusted_from_the_browser_on_public_booking(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        app(AppointmentAvailabilityService::class)->setWeeklySchedule($staff, [
            ['weekday' => 5, 'start_time' => '09:00', 'end_time' => '17:00'],
        ], $staff, AvailabilityContext::CONSULTANCY);
        $service = $this->makeService(['duration_minutes' => 30, 'price_minor_units' => 4000]);
        $date = $this->nextDateForWeekday(5);

        $response = $this->postJson("/api/public/consultancy-services/{$service->code}/book", [
            'attendee_name'      => 'Jane Client',
            'attendee_email'     => 'jane@client.example.com',
            'attendee_timezone'  => 'Europe/London',
            'date'               => $date,
            'start_time'         => '10:00',
            'timezone'           => 'Europe/London',
            'title'              => 'A query',
            'description'        => 'A description.',
            'consent'            => true,
            // Attempted tampering — none of these fields exist on the
            // public booking request shape at all, so they are silently
            // ignored, never trusted.
            'duration_minutes'   => 999,
            'price_minor_units'  => 1,
            'assigned_user_id'   => 99999,
        ]);

        $response->assertStatus(201);
        $appointment = \App\Models\Appointment::where('reference', $response->json('reference'))->firstOrFail();
        $this->assertSame($staff->id, $appointment->assigned_user_id);
        // Duration must come from the service's own 30-minute configuration
        // (never the tampered duration_minutes: 999 in the request body).
        $this->assertEquals(30, $appointment->starts_at->diffInMinutes($appointment->ends_at));
        $this->assertSame('10:00', $appointment->starts_at->copy()->setTimezone('Europe/London')->format('H:i'));
    }
}
