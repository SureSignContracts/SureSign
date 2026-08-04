<?php

namespace Tests\Feature;

use App\Models\AppointmentType;
use App\Models\ConsultancyService;
use App\Models\ConsultancySlotReservation;
use App\Models\Organization;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Services\AppointmentAvailabilityService;
use App\Services\Consultancy\ConsultancyCatalogueService;
use App\Services\Consultancy\ConsultancySlotReservationService;
use App\Services\TimezoneResolver;
use App\Support\Appointments\AvailabilityContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Consultancy Live Booking Upgrade, Stage 2 — temporary slot reservation
 * foundation. Explicitly excludes Stripe/Google — see
 * internal-docs/super-admin/consultancy.md's Stage 2 section.
 *
 * IMPORTANT: every test in this class runs against SQLite (this project's
 * test database) inside a single PHP process/connection. They validate
 * state transitions, conflict-query logic, idempotency, and ownership —
 * they do NOT and cannot prove MySQL/InnoDB row-lock semantics under
 * genuine multi-connection concurrency. See the documented MySQL
 * validation procedure in internal-docs/super-admin/consultancy.md for
 * what must be run separately against a real MySQL instance before this
 * is considered production-verified for true concurrent load.
 */
class ConsultancyLiveBookingStage2Test extends TestCase
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
            'code'                             => "stage2-service-{$n}",
            'display_name'                     => "Stage 2 Service {$n}",
            'enabled'                          => true,
            'publicly_bookable'                => true,
            'available_to_existing_customers'  => true,
            'price_minor_units'                => 4000,
            'currency'                         => 'GBP',
            'duration_minutes'                 => 30,
            'requires_confirmation'            => false,
        ], $overrides));
    }

    private function configureConsultant(User $user): void
    {
        SuresignSetting::instance()->update(['consultancy_consultant_user_id' => $user->id]);
    }

    private function nextDateForWeekday(int $weekday): string
    {
        $date = now()->addDays(3);
        while ($date->dayOfWeek !== $weekday) {
            $date = $date->addDay();
        }
        return $date->toDateString();
    }

    private function grantConsultancyAvailability(User $staff, int $weekday): void
    {
        app(AppointmentAvailabilityService::class)->setWeeklySchedule($staff, [
            ['weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '17:00'],
        ], $staff, AvailabilityContext::CONSULTANCY);
    }

    private function token(): string
    {
        return Str::random(40);
    }

    // ── State model ──────────────────────────────────────────────────────────

    public function test_reservation_defaults_to_active_with_a_future_expiry(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 1);
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(1);

        $reservation = app(ConsultancySlotReservationService::class)->reserve(
            $service,
            TimezoneResolver::buildLocalInstant($date, '10:00', 'Europe/London'),
            TimezoneResolver::buildLocalInstant($date, '10:00', 'Europe/London')->addMinutes(30),
            ['name' => 'Jane', 'email' => 'jane@example.com', 'timezone' => 'Europe/London'],
            $this->token(),
        );

        $this->assertSame('active', $reservation->status);
        $this->assertTrue($reservation->expires_at->isFuture());
        $this->assertTrue($reservation->isActiveAndUnexpired());
    }

    public function test_terminal_reservation_never_becomes_active_again(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 2);
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(2);
        $reservationService = app(ConsultancySlotReservationService::class);

        $reservation = $reservationService->reserve(
            $service,
            TimezoneResolver::buildLocalInstant($date, '10:00', 'Europe/London'),
            TimezoneResolver::buildLocalInstant($date, '10:00', 'Europe/London')->addMinutes(30),
            ['name' => 'Jane', 'email' => 'jane@example.com', 'timezone' => 'Europe/London'],
            $this->token(),
        );

        $cancelled = $reservationService->cancel($reservation);
        $this->assertSame('cancelled', $cancelled->status);

        // Cancelling an already-terminal reservation is a safe no-op.
        $stillCancelled = $reservationService->cancel($cancelled->fresh());
        $this->assertSame('cancelled', $stillCancelled->status);
        $this->assertNotNull($stillCancelled->cancelled_at);
    }

    // ── Reservation creation ─────────────────────────────────────────────────

    public function test_public_reservation_creation_snapshots_consultant_and_duration(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 3);
        $service = $this->makeService(['duration_minutes' => 45]);
        $date = $this->nextDateForWeekday(3);

        $response = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'Jane Client', 'attendee_email' => 'jane@client.example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
        ]);

        $response->assertStatus(201);
        $reservation = ConsultancySlotReservation::first();
        $this->assertSame($staff->id, $reservation->consultant_user_id);
        $this->assertEquals(45, $reservation->starts_at->diffInMinutes($reservation->ends_at));
    }

    public function test_authenticated_reservation_creation_is_organisation_scoped(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 4);
        [$org, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(4);

        $response = $this->actingAs($client)->postJson("/api/consultations/services/{$service->code}/reservations", [
            'attendee_name' => 'Jane Client', 'attendee_email' => 'jane@client.example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('consultancy_slot_reservations', ['organization_id' => $org->id]);
    }

    public function test_reservation_rejected_when_no_consultant_configured(): void
    {
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(5);

        $response = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'Jane Client', 'attendee_email' => 'jane@client.example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
        ]);

        $response->assertStatus(409);
        $this->assertDatabaseCount('consultancy_slot_reservations', 0);
    }

    public function test_reservation_rejected_outside_configured_availability(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        // No availability granted at all for this weekday.
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(6);

        $response = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'Jane Client', 'attendee_email' => 'jane@client.example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
        ]);

        $response->assertStatus(409);
        $this->assertDatabaseCount('consultancy_slot_reservations', 0);
    }

    public function test_reservation_rejected_for_inactive_service(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 0);
        $service = $this->makeService(['enabled' => false]);
        $date = $this->nextDateForWeekday(0);

        $response = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'Jane Client', 'attendee_email' => 'jane@client.example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
        ]);

        $response->assertStatus(404);
    }

    // ── Conflict integration ─────────────────────────────────────────────────

    public function test_existing_appointment_blocks_reservation_creation(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 1);
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(1);
        $startsAt = TimezoneResolver::buildLocalInstant($date, '10:00', 'Europe/London');

        \App\Models\Appointment::create([
            'reference' => 'EXIST001', 'appointment_type_id' => $service->appointmentType->id, 'assigned_user_id' => $staff->id,
            'attendee_name' => 'Existing', 'attendee_email' => 'existing@example.com', 'attendee_timezone' => 'Europe/London',
            'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addMinutes(30),
            'booking_timezone' => 'Europe/London', 'status' => 'confirmed', 'booking_source' => 'admin_created',
        ]);

        $response = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'Jane Client', 'attendee_email' => 'jane@client.example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
        ]);

        $response->assertStatus(409);
    }

    public function test_active_reservation_blocks_a_second_competing_reservation(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 2);
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(2);

        $first = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'First Customer', 'attendee_email' => 'first@example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
        ]);
        $first->assertStatus(201);

        // A genuinely DIFFERENT booking attempt (different token) for the
        // exact same slot — a real competitor, not a retry.
        $second = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'Second Customer', 'attendee_email' => 'second@example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
        ]);

        $second->assertStatus(409);
        $this->assertDatabaseCount('consultancy_slot_reservations', 1);
    }

    public function test_expired_reservation_no_longer_blocks(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 3);
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(3);
        $startsAt = TimezoneResolver::buildLocalInstant($date, '10:00', 'Europe/London');

        ConsultancySlotReservation::create([
            'booking_attempt_token' => $this->token(), 'active_attempt_token' => null,
            'consultancy_service_id' => $service->id, 'consultant_user_id' => $staff->id,
            'attendee_name' => 'Stale', 'attendee_email' => 'stale@example.com',
            'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addMinutes(30),
            'booking_timezone' => 'Europe/London', 'status' => 'active',
            'expires_at' => now()->subMinutes(5), // already elapsed
        ]);

        $response = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'Jane Client', 'attendee_email' => 'jane@client.example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
        ]);

        // Elapsed reservation must stop blocking immediately, before the
        // scheduled expire command ever runs — the row above is still
        // literally status='active' in the database.
        $response->assertStatus(201);
    }

    public function test_cancelled_reservation_no_longer_blocks(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 4);
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(4);
        $reservationService = app(ConsultancySlotReservationService::class);
        $startsAt = TimezoneResolver::buildLocalInstant($date, '10:00', 'Europe/London');

        $reservation = $reservationService->reserve(
            $service, $startsAt, $startsAt->copy()->addMinutes(30),
            ['name' => 'Jane', 'email' => 'jane@example.com', 'timezone' => 'Europe/London'], $this->token(),
        );
        $reservationService->cancel($reservation);

        $response = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'New Customer', 'attendee_email' => 'new@example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
        ]);

        $response->assertStatus(201);
    }

    public function test_different_consultant_does_not_conflict(): void
    {
        [, $staffA] = $this->makeOrgAndUser('Admin');
        [, $staffB] = $this->makeOrgAndUser('Admin');
        $this->grantConsultancyAvailability($staffA, 5);
        $this->grantConsultancyAvailability($staffB, 5);
        $serviceA = $this->makeService();
        $date = $this->nextDateForWeekday(5);
        $startsAt = TimezoneResolver::buildLocalInstant($date, '10:00', 'Europe/London');

        $this->configureConsultant($staffA);
        app(ConsultancySlotReservationService::class)->reserve(
            $serviceA, $startsAt, $startsAt->copy()->addMinutes(30),
            ['name' => 'Jane', 'email' => 'jane@example.com', 'timezone' => 'Europe/London'], $this->token(),
        );

        // Switch the configured consultant to staffB — a fresh reservation
        // for staffB at the exact same time must not conflict with staffA's.
        $this->configureConsultant($staffB);
        $serviceB = $this->makeService();

        $response = $this->postJson("/api/public/consultancy-services/{$serviceB->code}/reservations", [
            'attendee_name' => 'Other Customer', 'attendee_email' => 'other@example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
        ]);

        $response->assertStatus(201);
    }

    // ── Cross-workflow: reservation vs Book a Demo ───────────────────────────

    public function test_active_consultancy_reservation_blocks_an_overlapping_book_a_demo_slot(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 1);
        app(AppointmentAvailabilityService::class)->setWeeklySchedule($staff, [
            ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
        ], $staff, AvailabilityContext::APPOINTMENTS);

        $demoType = AppointmentType::create([
            'name' => 'Book a Demo', 'slug' => 'stage2-demo', 'duration_minutes' => 30,
            'is_public' => true, 'is_active' => true, 'assignment_mode' => 'fixed', 'default_assigned_user_id' => $staff->id,
        ]);
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(1);
        $startsAt = TimezoneResolver::buildLocalInstant($date, '10:00', 'Europe/London');

        app(ConsultancySlotReservationService::class)->reserve(
            $service, $startsAt, $startsAt->copy()->addMinutes(30),
            ['name' => 'Jane', 'email' => 'jane@example.com', 'timezone' => 'Europe/London'], $this->token(),
        );

        $slotsResponse = $this->getJson("/api/public/appointment-types/{$demoType->slug}/slots?date={$date}&timezone=Europe/London");
        $slotsResponse->assertStatus(200);

        $this->assertNotContains('10:00', collect($slotsResponse->json('slots'))->pluck('time')->all());
    }

    public function test_confirmed_book_a_demo_appointment_blocks_consultancy_reservation_creation(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 2);

        $demoType = AppointmentType::create([
            'name' => 'Book a Demo', 'slug' => 'stage2-demo-2', 'duration_minutes' => 30,
            'is_public' => true, 'is_active' => true,
        ]);
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(2);
        $startsAt = TimezoneResolver::buildLocalInstant($date, '10:00', 'Europe/London');

        \App\Models\Appointment::create([
            'reference' => 'DEMO001', 'appointment_type_id' => $demoType->id, 'assigned_user_id' => $staff->id,
            'attendee_name' => 'Demo Attendee', 'attendee_email' => 'demo@example.com', 'attendee_timezone' => 'Europe/London',
            'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addMinutes(30),
            'booking_timezone' => 'Europe/London', 'status' => 'confirmed', 'booking_source' => 'admin_created',
        ]);

        $response = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'Jane Client', 'attendee_email' => 'jane@client.example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
        ]);

        $response->assertStatus(409);
    }

    // ── Buffers ───────────────────────────────────────────────────────────────

    public function test_reservation_respects_service_buffer_against_another_reservation(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 3);
        $service = $this->makeService(['duration_minutes' => 30, 'buffer_after_minutes' => 15]);
        $date = $this->nextDateForWeekday(3);

        $first = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'First', 'attendee_email' => 'first@example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
        ]);
        $first->assertStatus(201);

        // 10:30-11:00 is within the first reservation's 15-minute
        // post-buffer (10:30-10:45) — must be rejected.
        $second = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'Second', 'attendee_email' => 'second@example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:30',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
        ]);
        $second->assertStatus(409);
    }

    // ── Idempotency ───────────────────────────────────────────────────────────

    public function test_same_booking_attempt_token_and_same_slot_returns_the_same_reservation(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 4);
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(4);
        $token = $this->token();

        $first = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'Jane', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $token,
        ]);
        $second = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'Jane', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $token,
        ]);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertSame($first->json('token'), $second->json('token'));
        $this->assertDatabaseCount('consultancy_slot_reservations', 1);
    }

    public function test_same_booking_attempt_token_selecting_a_different_slot_replaces_the_reservation(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 5);
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(5);
        $token = $this->token();

        $first = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'Jane', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $token,
        ]);
        $second = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'Jane', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '14:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $token,
        ]);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertNotSame($first->json('token'), $second->json('token'));

        $original = ConsultancySlotReservation::where('public_token', $first->json('token'))->first();
        $this->assertSame('cancelled', $original->status);
        $this->assertNotNull($original->cancelled_at);

        $replacement = ConsultancySlotReservation::where('public_token', $second->json('token'))->first();
        $this->assertSame('active', $replacement->status);
        $this->assertDatabaseCount('consultancy_slot_reservations', 2);
    }

    public function test_replacement_original_slot_is_freed_only_after_replacement_secured(): void
    {
        // If replacement genuinely validates the NEW slot before cancelling
        // the OLD one, a replacement attempt to an UNAVAILABLE slot must
        // leave the original reservation untouched (still active).
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 6);
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(6);
        $token = $this->token();

        $first = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'Jane', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $token,
        ]);
        $first->assertStatus(201);

        // Someone else takes 14:00 first.
        $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'Other', 'attendee_email' => 'other@example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '14:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
        ])->assertStatus(201);

        // Jane's attempt to replace with the now-unavailable 14:00 slot.
        $replaceAttempt = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'Jane', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '14:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $token,
        ]);

        $replaceAttempt->assertStatus(409);
        $original = ConsultancySlotReservation::where('public_token', $first->json('token'))->first();
        $this->assertSame('active', $original->status, 'Original reservation must not be released when the replacement slot is unavailable.');
    }

    // ── Ownership and security ────────────────────────────────────────────────

    public function test_public_reservation_response_never_exposes_consultant_identity_or_internal_id(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 0);
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(0);

        $response = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'Jane', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
        ]);

        $response->assertStatus(201);
        $json = $response->json();
        $this->assertArrayNotHasKey('id', $json);
        $this->assertArrayNotHasKey('consultant_user_id', $json);
        $this->assertArrayNotHasKey('consultant', $json);
        $this->assertArrayNotHasKey('context', $json);
        $this->assertStringNotContainsString($staff->email, $response->getContent());
        $this->assertStringNotContainsString($staff->name, $response->getContent());
    }

    public function test_reservation_token_cannot_be_guessed_from_sequential_id(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 1);
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(1);

        $response = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'Jane', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
        ]);

        $reservation = ConsultancySlotReservation::first();
        $this->assertNotEquals((string) $reservation->id, $response->json('token'));
        $this->assertGreaterThanOrEqual(40, strlen($response->json('token')));

        // Guessing sequential ID "1" as a token must not resolve anything.
        $guess = $this->getJson('/api/public/consultancy-reservations/1');
        $guess->assertStatus(404);
    }

    public function test_authenticated_user_cannot_read_another_organisations_reservation(): void
    {
        [, $staffA] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staffA);
        $this->grantConsultancyAvailability($staffA, 2);
        [$orgA, $clientA] = $this->makeOrgAndUser('Client');
        [, $clientB] = $this->makeOrgAndUser('Client');
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(2);

        $created = $this->actingAs($clientA)->postJson("/api/consultations/services/{$service->code}/reservations", [
            'attendee_name' => 'Jane', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
        ]);
        $token = $created->json('token');

        $this->actingAs($clientB)->getJson("/api/consultations/reservations/{$token}")->assertStatus(403);
        $this->actingAs($clientB)->postJson("/api/consultations/reservations/{$token}/cancel")->assertStatus(403);
    }

    public function test_price_duration_consultant_and_context_are_never_trusted_from_the_browser(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 3);
        $service = $this->makeService(['duration_minutes' => 30]);
        $date = $this->nextDateForWeekday(3);

        $response = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'Jane', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
            // Tampering attempts — none exist on the request shape at all.
            'consultant_user_id' => 99999, 'duration_minutes' => 999, 'context' => 'appointments',
            'expires_at' => now()->addYears(10)->toIso8601String(), 'status' => 'consumed',
        ]);

        $response->assertStatus(201);
        $reservation = ConsultancySlotReservation::first();
        $this->assertSame($staff->id, $reservation->consultant_user_id);
        $this->assertEquals(30, $reservation->starts_at->diffInMinutes($reservation->ends_at));
        $this->assertSame('active', $reservation->status);
        $this->assertTrue($reservation->expires_at->lessThan(now()->addHour()));
    }

    // ── Expiry / cleanup command ──────────────────────────────────────────────

    public function test_expire_command_marks_elapsed_active_reservations_expired_and_is_idempotent(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $startsAt = now()->addDay();

        $elapsed = ConsultancySlotReservation::create([
            'booking_attempt_token' => $this->token(), 'active_attempt_token' => $this->token(),
            'consultancy_service_id' => $service->id, 'consultant_user_id' => $staff->id,
            'attendee_name' => 'Elapsed', 'attendee_email' => 'elapsed@example.com',
            'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addMinutes(30),
            'booking_timezone' => 'Europe/London', 'status' => 'active', 'expires_at' => now()->subMinutes(1),
        ]);
        $future = ConsultancySlotReservation::create([
            'booking_attempt_token' => $this->token(), 'active_attempt_token' => $this->token(),
            'consultancy_service_id' => $service->id, 'consultant_user_id' => $staff->id,
            'attendee_name' => 'Future', 'attendee_email' => 'future@example.com',
            'starts_at' => $startsAt->copy()->addHour(), 'ends_at' => $startsAt->copy()->addHour()->addMinutes(30),
            'booking_timezone' => 'Europe/London', 'status' => 'active', 'expires_at' => now()->addMinutes(10),
        ]);

        $this->artisan('consultancy:reservations:expire')->assertSuccessful();

        $this->assertSame('expired', $elapsed->fresh()->status);
        $this->assertSame('active', $future->fresh()->status);

        // Idempotent re-run.
        $this->artisan('consultancy:reservations:expire')->assertSuccessful();
        $this->assertSame('expired', $elapsed->fresh()->status);
    }

    public function test_scheduler_registers_the_expiry_command(): void
    {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $commands = collect($schedule->events())->map(fn ($e) => $e->command ?? '')->implode(' ');

        $this->assertStringContainsString('consultancy:reservations:expire', $commands);
    }

    // ── Admin diagnostics ─────────────────────────────────────────────────────

    public function test_admin_can_view_reservation_diagnostics(): void
    {
        [, $admin] = $this->makeOrgAndUser('Admin');
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 4);
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(4);

        $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'Jane', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
        ])->assertStatus(201);

        $response = $this->actingAs($admin)->getJson('/api/admin/consultancy/reservations');

        $response->assertStatus(200)->assertJsonPath('counts.active', 1);
    }

    public function test_admin_can_cancel_a_reservation(): void
    {
        [, $admin] = $this->makeOrgAndUser('Admin');
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 5);
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(5);

        $created = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
            'attendee_name' => 'Jane', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
            'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
        ]);
        $reservationId = ConsultancySlotReservation::first()->id;

        $response = $this->actingAs($admin)->postJson("/api/admin/consultancy/reservations/{$reservationId}/cancel");

        $response->assertStatus(200)->assertJsonPath('status', 'cancelled');
    }

    public function test_client_cannot_access_admin_reservation_diagnostics(): void
    {
        [, $client] = $this->makeOrgAndUser('Client');

        $this->actingAs($client)->getJson('/api/admin/consultancy/reservations')->assertStatus(403);
    }

    // ── Readiness unaffected by Stripe/Google absence ────────────────────────

    public function test_readiness_still_excludes_stripe_and_google_in_stage_2(): void
    {
        $readiness = app(\App\Services\Consultancy\ConsultancyBookingReadinessService::class)->check();

        $this->assertArrayNotHasKey('stripe_configured', $readiness);
        $this->assertArrayNotHasKey('google_connected', $readiness);
        $this->assertArrayNotHasKey('reservation_infrastructure', $readiness);
    }

    /**
     * Sequential logic proof, NOT a concurrency test — see class docblock.
     * Proves the query/locking CODE PATH behaves correctly when exercised
     * in order; it cannot exercise real InnoDB row-lock contention since
     * both requests run in the same process against the same SQLite
     * connection. Genuine concurrent-connection verification is deferred
     * to the documented MySQL validation procedure.
     */
    public function test_sequential_competing_attempts_only_one_reservation_survives(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 6);
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(6);

        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->postJson("/api/public/consultancy-services/{$service->code}/reservations", [
                'attendee_name' => "Customer {$i}", 'attendee_email' => "customer{$i}@example.com",
                'attendee_timezone' => 'Europe/London', 'date' => $date, 'start_time' => '10:00',
                'timezone' => 'Europe/London', 'booking_attempt_token' => $this->token(),
            ]);
        }

        $succeeded = collect($results)->filter(fn ($r) => $r->status() === 201);
        $this->assertCount(1, $succeeded);
        $this->assertDatabaseCount('consultancy_slot_reservations', 1);
    }
}
