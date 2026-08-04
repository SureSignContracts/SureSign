<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\ConsultancyPayment;
use App\Models\ConsultancyService;
use App\Models\ConsultancySlotReservation;
use App\Models\Organization;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Services\AppointmentAvailabilityService;
use App\Services\Consultancy\ConsultancyBookingReadinessService;
use App\Services\Consultancy\ConsultancyCatalogueService;
use App\Services\Consultancy\ConsultancyCheckoutService;
use App\Services\Consultancy\ConsultancyConsultantResolver;
use App\Services\Consultancy\ConsultancySlotReservationService;
use App\Services\TimezoneResolver;
use App\Support\Appointments\AvailabilityContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Consultancy Live Booking Activation Hardening — a focused production-
 * readiness pass between Stage 4A (Google Integration Foundation) and
 * Stage 4B (Calendar/Meet automation). Resolves exactly the two gaps the
 * Stage 4A verification identified (unenforced paid-booking readiness gate,
 * manual-only payment recovery) plus the Stage 4B pre-requisite (cancelled-
 * Appointment sync protection) — see internal-docs/super-admin/consultancy.md
 * for the full writeup. Deliberately does not touch Google/Calendar code at
 * all.
 */
class ConsultancyActivationHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['consultancy.checkout_success_url' => 'https://app.example.test/consultancy/success']);
        config(['consultancy.checkout_cancel_url' => 'https://app.example.test/consultancy/cancel']);
    }

    private function makeOrgAndUser(string $role): array
    {
        static $n = 0;
        $n++;
        $org = Organization::create(['name' => "Hardening Org {$n}", 'slug' => "hardening-org-{$n}", 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

        return [$org, $user];
    }

    private function makeService(array $overrides = []): ConsultancyService
    {
        static $n = 0;
        $n++;

        return app(ConsultancyCatalogueService::class)->create(array_merge([
            'code'                             => "hardening-service-{$n}",
            'display_name'                     => "Hardening Service {$n}",
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

    private function makeReadyReservation(ConsultancyService $service, int $weekday, ?User $forOrgUser = null): ConsultancySlotReservation
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, $weekday);
        $date = $this->nextDateForWeekday($weekday);
        $starts = TimezoneResolver::buildLocalInstant($date, '10:00', 'Europe/London');

        $args = [
            $service, $starts, $starts->copy()->addMinutes($service->appointmentType->duration_minutes),
            ['name' => 'Jane Client', 'email' => 'jane@example.com', 'timezone' => 'Europe/London'],
            Str::random(40),
        ];

        if ($forOrgUser) {
            $args[] = $forOrgUser->organization_id;
            $args[] = $forOrgUser->id;
        }

        return app(ConsultancySlotReservationService::class)->reserve(...$args);
    }

    // ── 1/2. Paid Booking Readiness Enforcement (public + authenticated) ────

    public function test_public_checkout_is_blocked_when_platform_not_ready(): void
    {
        // Reservation is created while the platform IS ready (reserve()
        // itself only requires a resolvable consultant); availability is
        // then withdrawn before checkout is attempted — the realistic gap
        // this hardening pass closes: readiness can regress in the window
        // between a customer holding a slot and paying for it.
        $service = $this->makeService();
        $reservation = $this->makeReadyReservation($service, 2);

        $consultant = $reservation->consultant;
        app(AppointmentAvailabilityService::class)->setWeeklySchedule($consultant, [], $consultant, AvailabilityContext::CONSULTANCY);

        $response = $this->postJson("/api/public/consultancy-reservations/{$reservation->public_token}/checkout");

        $response->assertStatus(503);
        $response->assertJsonPath('reason', 'configuration_unavailable');
        $this->assertArrayNotHasKey('consultant_configured', $response->json());
        $this->assertSame(0, ConsultancyPayment::count(), 'No Checkout Session/payment row should be created when readiness fails.');
    }

    public function test_public_checkout_succeeds_when_platform_is_ready(): void
    {
        $service = $this->makeService();
        $reservation = $this->makeReadyReservation($service, 2);

        $response = $this->postJson("/api/public/consultancy-reservations/{$reservation->public_token}/checkout");

        $response->assertStatus(201);
        $this->assertSame(1, ConsultancyPayment::count());
    }

    public function test_authenticated_checkout_is_blocked_when_platform_not_ready(): void
    {
        [, $user] = $this->makeOrgAndUser('Client');
        $service = $this->makeService(['available_to_existing_customers' => true]);
        $reservation = $this->makeReadyReservation($service, 1, $user);

        $consultant = $reservation->consultant;
        app(AppointmentAvailabilityService::class)->setWeeklySchedule($consultant, [], $consultant, AvailabilityContext::CONSULTANCY);

        $response = $this->actingAs($user)->postJson("/api/consultations/reservations/{$reservation->public_token}/checkout");

        $response->assertStatus(503);
        $response->assertJsonPath('reason', 'configuration_unavailable');
        $this->assertSame(0, ConsultancyPayment::count());
    }

    public function test_authenticated_checkout_succeeds_when_platform_is_ready(): void
    {
        [, $user] = $this->makeOrgAndUser('Client');
        $service = $this->makeService(['available_to_existing_customers' => true]);
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 3);
        $date = $this->nextDateForWeekday(3);
        $starts = TimezoneResolver::buildLocalInstant($date, '10:00', 'Europe/London');

        $reservation = app(ConsultancySlotReservationService::class)->reserve(
            $service, $starts, $starts->copy()->addMinutes($service->appointmentType->duration_minutes),
            ['name' => 'Jane', 'email' => 'jane@example.com', 'timezone' => 'Europe/London'],
            Str::random(40), $user->organization_id, $user->id,
        );

        $response = $this->actingAs($user)->postJson("/api/consultations/reservations/{$reservation->public_token}/checkout");

        $response->assertStatus(201);
        $this->assertSame(1, ConsultancyPayment::count());
    }

    // ── 2. Structured readiness result ───────────────────────────────────────

    public function test_checkout_availability_is_available_true_when_ready(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, 4);
        $this->makeService();

        $result = app(ConsultancyBookingReadinessService::class)->checkoutAvailability();

        $this->assertSame(['available' => true, 'reason_category' => null, 'message' => null], $result);
    }

    public function test_checkout_availability_reports_configuration_unavailable_without_leaking_which_check_failed(): void
    {
        $result = app(ConsultancyBookingReadinessService::class)->checkoutAvailability();

        $this->assertFalse($result['available']);
        $this->assertSame('configuration_unavailable', $result['reason_category']);
        $this->assertIsString($result['message']);
        $this->assertStringNotContainsStringIgnoringCase('consultant', $result['message']);
        $this->assertStringNotContainsStringIgnoringCase('availability', $result['message']);
    }

    public function test_checkout_availability_reports_temporarily_unavailable_when_the_check_itself_throws(): void
    {
        $this->mock(ConsultancyConsultantResolver::class, function ($mock) {
            $mock->shouldReceive('resolve')->andThrow(new \RuntimeException('simulated database failure'));
        });
        Log::shouldReceive('error')->once();

        $result = app(ConsultancyBookingReadinessService::class)->checkoutAvailability();

        $this->assertFalse($result['available']);
        $this->assertSame('temporarily_unavailable', $result['reason_category']);
        $this->assertStringNotContainsString('simulated database failure', (string) $result['message']);
    }

    public function test_admin_readiness_diagnostics_exposes_checkout_blocked_alongside_full_breakdown(): void
    {
        [, $admin] = $this->makeOrgAndUser('Admin');

        $response = $this->actingAs($admin)->getJson('/api/admin/consultancy/settings/readiness');

        $response->assertStatus(200);
        $response->assertJsonPath('checkout_blocked', true);
        $response->assertJsonPath('consultant_configured', false);
    }

    // ── 3/4. Automatic recovery scheduling ───────────────────────────────────

    public function test_reconcile_command_is_scheduled_every_five_minutes(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $event = collect($schedule->events())->first(
            fn ($e) => str_contains($e->command, 'consultancy:payments:reconcile')
        );

        $this->assertNotNull($event, 'consultancy:payments:reconcile is not registered in the scheduler.');
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->runInBackground);
        $this->assertFalse($event->onOneServer);
    }

    public function test_manual_reconciliation_still_works_alongside_scheduling(): void
    {
        $service = $this->makeService();
        $reservation = $this->makeReadyReservation($service, 5);
        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');
        $payment->update(['status' => 'conversion_pending', 'paid_at' => now(), 'confirming_stripe_event_id' => 'evt_hardening_manual']);

        $this->artisan('consultancy:payments:reconcile')->assertSuccessful();

        $this->assertSame('converted', $payment->fresh()->status);
    }

    // ── 5. Cancelled Appointment protection ──────────────────────────────────

    private function makeAppointment(array $overrides = []): Appointment
    {
        static $n = 0;
        $n++;
        $type = AppointmentType::create([
            'name' => "Hardening Type {$n}", 'slug' => "hardening-type-{$n}",
            'duration_minutes' => 30, 'is_active' => true, 'is_public' => true, 'assignment_mode' => 'manual',
            'meeting_method' => 'tbc', 'requires_confirmation' => false,
            'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'min_notice_hours' => 0, 'max_advance_days' => 60,
        ]);

        return Appointment::create(array_merge([
            'reference'           => 'APT-HARD-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'appointment_type_id' => $type->id,
            'attendee_name'       => 'Jane Doe',
            'attendee_email'      => 'jane@example.com',
            'attendee_timezone'   => 'Europe/London',
            'starts_at'           => now()->addDays(3)->setTime(10, 0),
            'ends_at'             => now()->addDays(3)->setTime(10, 30),
            'booking_timezone'    => 'Europe/London',
            'status'              => 'confirmed',
            'booking_source'      => 'public_booking_page',
            'meeting_method'      => 'tbc',
        ], $overrides));
    }

    public function test_confirmed_appointment_is_eligible_for_external_sync(): void
    {
        $appointment = $this->makeAppointment(['status' => 'confirmed']);

        $this->assertTrue($appointment->isEligibleForExternalSync());
    }

    public function test_cancelled_appointment_is_never_eligible_for_external_sync(): void
    {
        $appointment = $this->makeAppointment(['status' => 'cancelled', 'cancelled_at' => now()]);

        $this->assertFalse($appointment->isEligibleForExternalSync());
    }

    public function test_soft_deleted_appointment_is_never_eligible_for_external_sync(): void
    {
        $appointment = $this->makeAppointment(['status' => 'confirmed']);
        $appointment->delete();

        $this->assertFalse($appointment->fresh()->isEligibleForExternalSync());
    }

    // ── Security ──────────────────────────────────────────────────────────────

    public function test_client_cannot_read_admin_readiness_diagnostics(): void
    {
        [, $client] = $this->makeOrgAndUser('Client');

        $response = $this->actingAs($client)->getJson('/api/admin/consultancy/settings/readiness');

        $response->assertStatus(403);
    }
}
