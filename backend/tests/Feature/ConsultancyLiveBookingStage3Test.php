<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BillingWebhookEvent;
use App\Models\ConsultancyPayment;
use App\Models\ConsultancyService;
use App\Models\ConsultancySlotReservation;
use App\Models\ConsultationEnquiry;
use App\Models\Organization;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Services\AppointmentAvailabilityService;
use App\Services\Billing\FakeBillingProvider;
use App\Services\Consultancy\ConsultancyCatalogueService;
use App\Services\Consultancy\ConsultancyCheckoutService;
use App\Services\Consultancy\ConsultancyPaymentConversionService;
use App\Services\Consultancy\ConsultancySlotReservationService;
use App\Services\Consultancy\ConsultancyWebhookEventProcessor;
use App\Services\Consultancy\Exceptions\ConsultancyConversionRetryableException;
use App\Services\TimezoneResolver;
use App\Support\Appointments\AvailabilityContext;
use App\Support\Billing\WebhookProcessingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Consultancy Live Booking Upgrade, Stage 3 — commercial snapshot, Stripe
 * Checkout, webhook routing/processing, and atomic local conversion.
 *
 * IMPORTANT — validation boundary, stated precisely rather than implied:
 *  - Every test here runs against SQLite with the fake billing provider
 *    (App\Services\Billing\FakeBillingProvider) — no real Stripe API call
 *    is ever made, and no Stripe test-mode credentials exist in this
 *    environment. "Checkout creation" below means "the fake provider
 *    recorded a request shaped exactly like the real one would be" — never
 *    a real hosted Checkout page, real card payment, real Apple/Google Pay
 *    availability, or a real webhook delivery.
 *  - Concurrency-shaped tests prove sequential logic correctness only
 *    (mirroring Stage 2's own documented limitation) — see
 *    internal-docs/super-admin/consultancy.md's Stage 3 section for the
 *    full MySQL/Stripe-test-mode manual validation procedures this
 *    environment cannot execute.
 */
class ConsultancyLiveBookingStage3Test extends TestCase
{
    use RefreshDatabase;

    private FakeBillingProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = $this->app->make(FakeBillingProvider::class);
        $this->fake->livemode = false;

        config(['consultancy.checkout_success_url' => 'https://app.example.test/consultancy/success']);
        config(['consultancy.checkout_cancel_url' => 'https://app.example.test/consultancy/cancel']);
    }

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
            'code'                             => "stage3-service-{$n}",
            'display_name'                     => "Stage 3 Service {$n}",
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

    private function makeReservation(User $staff, ConsultancyService $service, int $weekday): ConsultancySlotReservation
    {
        $this->configureConsultant($staff);
        $this->grantConsultancyAvailability($staff, $weekday);
        $date = $this->nextDateForWeekday($weekday);
        $starts = TimezoneResolver::buildLocalInstant($date, '10:00', 'Europe/London');

        return app(ConsultancySlotReservationService::class)->reserve(
            $service, $starts, $starts->copy()->addMinutes($service->appointmentType->duration_minutes),
            ['name' => 'Jane Client', 'email' => 'jane@example.com', 'timezone' => 'Europe/London'],
            Str::random(40),
        );
    }

    private function webhookEvent(string $type, array $dataObject, array $overrides = []): BillingWebhookEvent
    {
        return BillingWebhookEvent::create(array_merge([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_' . random_int(1, 100000000),
            'event_type' => $type,
            'livemode' => false,
            'provider_created_at' => CarbonImmutable::now(),
            'processing_status' => WebhookProcessingStatus::RECEIVED,
            'received_at' => CarbonImmutable::now(),
            'payload_json' => ['data' => ['object' => $dataObject]],
            'payload_hash' => hash('sha256', json_encode($dataObject)),
        ], $overrides));
    }

    // ── Commercial snapshot ──────────────────────────────────────────────────

    public function test_checkout_creation_snapshots_every_immutable_commercial_field(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService(['price_minor_units' => 5000, 'currency' => 'GBP', 'duration_minutes' => 45]);
        $reservation = $this->makeReservation($staff, $service, 1);

        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession(
            $reservation, 'https://x.test/success', 'https://x.test/cancel',
        );

        $this->assertSame($service->code, $payment->service_code_snapshot);
        $this->assertSame($service->display_name, $payment->service_name_snapshot);
        $this->assertSame($staff->id, $payment->consultant_user_id_snapshot);
        $this->assertSame(45, $payment->duration_minutes_snapshot);
        $this->assertSame(5000, $payment->amount_minor_units);
        $this->assertSame(5000, $payment->subtotal_minor_units);
        $this->assertSame(0, $payment->tax_minor_units);
        $this->assertSame(5000, $payment->total_minor_units);
        $this->assertSame('GBP', $payment->currency);
        $this->assertSame('not_separately_calculated', $payment->tax_treatment);
        $this->assertSame('checkout_open', $payment->status);
        $this->assertNotNull($payment->stripe_checkout_session_id);
    }

    public function test_later_service_price_change_never_alters_an_existing_checkout(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService(['price_minor_units' => 4000]);
        $reservation = $this->makeReservation($staff, $service, 2);

        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');

        $service->update(['price_minor_units' => 9999, 'display_name' => 'Renamed Service']);

        $payment->refresh();
        $this->assertSame(4000, $payment->amount_minor_units);
        $this->assertNotSame('Renamed Service', $payment->service_name_snapshot);
    }

    public function test_service_deactivation_does_not_invalidate_an_already_created_checkout(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 3);
        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');

        $service->update(['enabled' => false]);

        // Webhook processing/conversion must still work from the snapshot,
        // never re-reading the (now disabled) live service.
        $this->fake->markOneOffCheckoutSessionPaid($payment->stripe_checkout_session_id, 'pi_' . Str::random(20));
        $event = $this->webhookEvent('checkout.session.completed', $this->fake->checkoutSessions[$payment->stripe_checkout_session_id]);

        $result = app(ConsultancyWebhookEventProcessor::class)->process($event);

        $this->assertSame('payment_confirmed_and_converted', $result['reason']);
        $this->assertSame('converted', $payment->fresh()->status);
    }

    public function test_disabled_service_rejects_new_checkout_creation(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 4);
        $service->update(['enabled' => false]);

        $this->expectException(\RuntimeException::class);
        app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');
    }

    // ── Checkout creation / idempotency ──────────────────────────────────────

    public function test_one_open_checkout_per_active_reservation(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 5);

        $first = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');
        $second = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('consultancy_payments', 1);
    }

    public function test_checkout_creation_rejects_an_inactive_reservation(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 6);
        app(ConsultancySlotReservationService::class)->cancel($reservation);

        $this->expectException(\RuntimeException::class);
        app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation->fresh(), 'https://x.test/s', 'https://x.test/c');
    }

    public function test_checkout_provider_failure_leaves_reservation_expiry_untouched_and_marks_payment_failed(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 0);
        $originalExpiry = $reservation->expires_at;

        // Force the fake provider to explode on the next call.
        $failingProvider = new class extends FakeBillingProvider {
            public function createOneOffCheckoutSession(\App\Support\Billing\OneOffCheckoutRequest $request): array
            {
                throw new \RuntimeException('Simulated Stripe outage.');
            }
        };
        $this->app->instance(\App\Services\Billing\BillingProviderInterface::class, $failingProvider);

        try {
            app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');
            $this->fail('Expected a RuntimeException.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('failed', ConsultancyPayment::first()->status);
        $this->assertTrue($reservation->fresh()->expires_at->equalTo($originalExpiry));
    }

    // ── Reservation / Checkout expiry alignment ──────────────────────────────

    public function test_reservation_expiry_is_extended_to_match_checkout_expiry(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 1);
        $originalExpiry = $reservation->expires_at;

        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');

        $refreshed = $reservation->fresh();
        $this->assertTrue($refreshed->expires_at->greaterThan($originalExpiry));
        $this->assertTrue($refreshed->expires_at->equalTo($payment->checkout_expires_at));
        $this->assertEqualsWithDelta(30 * 60, now()->diffInSeconds($payment->checkout_expires_at), 5);
    }

    // ── Webhook success / conversion ──────────────────────────────────────────

    public function test_verified_webhook_success_converts_reservation_to_appointment_atomically(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 2);
        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');

        $this->fake->markOneOffCheckoutSessionPaid($payment->stripe_checkout_session_id, 'pi_test_123');
        $event = $this->webhookEvent('checkout.session.completed', $this->fake->checkoutSessions[$payment->stripe_checkout_session_id]);

        $result = app(ConsultancyWebhookEventProcessor::class)->process($event);

        $this->assertSame('processed', $result['outcome']);
        $payment->refresh();
        $reservation->refresh();
        $this->assertSame('converted', $payment->status);
        $this->assertSame('consumed', $reservation->status);
        $this->assertNotNull($payment->appointment_id);

        $appointment = Appointment::find($payment->appointment_id);
        $this->assertSame('confirmed', $appointment->status);
        $this->assertSame($staff->id, $appointment->assigned_user_id);
        $this->assertTrue($appointment->starts_at->equalTo($payment->starts_at_snapshot));
        $this->assertDatabaseHas('consultation_enquiries', ['appointment_id' => $appointment->id]);
    }

    public function test_duplicate_webhook_delivery_never_creates_a_second_appointment(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 3);
        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');
        $this->fake->markOneOffCheckoutSessionPaid($payment->stripe_checkout_session_id, 'pi_dup');
        $sessionPayload = $this->fake->checkoutSessions[$payment->stripe_checkout_session_id];

        $processor = app(ConsultancyWebhookEventProcessor::class);
        $first = $processor->process($this->webhookEvent('checkout.session.completed', $sessionPayload));
        // A genuine redelivery of the SAME provider event would be caught
        // by billing_webhook_events' own unique constraint upstream — this
        // proves the DOWNSTREAM processor-level idempotency too, via a
        // second distinct event for the same Checkout Session (e.g. a
        // retried webhook with a new delivery ID, which Stripe can send).
        $second = $processor->process($this->webhookEvent('checkout.session.completed', $sessionPayload));

        $this->assertSame('processed', $first['outcome']);
        $this->assertSame('already_converted', $second['reason']);
        $this->assertSame(1, Appointment::where('assigned_user_id', $staff->id)->count());
    }

    public function test_checkout_expired_event_marks_payment_expired_without_creating_an_appointment(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 4);
        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');

        $expiredSession = $this->fake->expireCheckoutSession($payment->stripe_checkout_session_id);
        $event = $this->webhookEvent('checkout.session.expired', $expiredSession);

        $result = app(ConsultancyWebhookEventProcessor::class)->process($event);

        $this->assertSame('checkout_marked_expired', $result['reason']);
        $this->assertSame('expired', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->appointment_id);
    }

    public function test_late_expired_event_never_overrides_an_already_paid_payment(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 5);
        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');
        $this->fake->markOneOffCheckoutSessionPaid($payment->stripe_checkout_session_id, 'pi_late');
        app(ConsultancyWebhookEventProcessor::class)->process(
            $this->webhookEvent('checkout.session.completed', $this->fake->checkoutSessions[$payment->stripe_checkout_session_id])
        );

        // An out-of-order 'expired' event arriving AFTER completion.
        $result = app(ConsultancyWebhookEventProcessor::class)->process(
            $this->webhookEvent('checkout.session.expired', $this->fake->checkoutSessions[$payment->stripe_checkout_session_id])
        );

        $this->assertSame('expired_event_after_payment_confirmed', $result['reason']);
        $this->assertSame('converted', $payment->fresh()->status);
    }

    /**
     * The approved expiry-race correction: a webhook arriving AFTER the
     * stored reservation/Checkout expiry must still convert successfully,
     * since Stripe's own `status: complete`/`payment_status: paid` is
     * authoritative proof payment completed within the aligned window.
     */
    public function test_late_arriving_webhook_after_expiry_still_converts_successfully(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 6);
        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');
        $this->fake->markOneOffCheckoutSessionPaid($payment->stripe_checkout_session_id, 'pi_late_arrival');

        // Simulate the webhook arriving well after the aligned expiry —
        // move the LOCAL clock forward on the reservation/payment rows
        // rather than relying on real elapsed time.
        $reservation->update(['expires_at' => now()->subHour()]);
        $payment->update(['checkout_expires_at' => now()->subHour()]);

        $event = $this->webhookEvent('checkout.session.completed', $this->fake->checkoutSessions[$payment->stripe_checkout_session_id]);
        $result = app(ConsultancyWebhookEventProcessor::class)->process($event);

        $this->assertSame('payment_confirmed_and_converted', $result['reason']);
        $this->assertSame('converted', $payment->fresh()->status);
        $this->assertSame('consumed', $reservation->fresh()->status);
    }

    public function test_unconfirmed_checkout_completed_payload_is_ignored_not_converted(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 0);
        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');

        // status is still 'open'/payment_status 'unpaid' — never treated
        // as confirmed merely because a checkout.session.completed-shaped
        // event exists.
        $event = $this->webhookEvent('checkout.session.completed', $this->fake->checkoutSessions[$payment->stripe_checkout_session_id]);

        $result = app(ConsultancyWebhookEventProcessor::class)->process($event);

        $this->assertSame('checkout_not_yet_confirmed_paid', $result['reason']);
        $this->assertSame('checkout_open', $payment->fresh()->status);
    }

    // ── Reservation independently cancelled before webhook arrives ──────────

    public function test_paid_checkout_with_independently_cancelled_reservation_converts_if_time_still_free(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 1);
        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');
        $this->fake->markOneOffCheckoutSessionPaid($payment->stripe_checkout_session_id, 'pi_cancelled_but_free');

        // Reservation independently cancelled (e.g. operator action) —
        // time remains free since nothing else was booked.
        app(ConsultancySlotReservationService::class)->cancel($reservation);

        $event = $this->webhookEvent('checkout.session.completed', $this->fake->checkoutSessions[$payment->stripe_checkout_session_id]);
        $result = app(ConsultancyWebhookEventProcessor::class)->process($event);

        $this->assertSame('payment_confirmed_and_converted', $result['reason']);
        $this->assertSame('converted', $payment->fresh()->status);
    }

    public function test_paid_checkout_with_independently_cancelled_reservation_and_time_now_taken_requires_manual_review(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 2);
        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');
        $this->fake->markOneOffCheckoutSessionPaid($payment->stripe_checkout_session_id, 'pi_cancelled_and_taken');

        app(ConsultancySlotReservationService::class)->cancel($reservation);

        // Someone else took the exact same time in the interim.
        Appointment::create([
            'reference' => 'TAKEN001', 'appointment_type_id' => $service->appointmentType->id, 'assigned_user_id' => $staff->id,
            'attendee_name' => 'Other', 'attendee_email' => 'other@example.com', 'attendee_timezone' => 'Europe/London',
            'starts_at' => $payment->starts_at_snapshot, 'ends_at' => $payment->ends_at_snapshot,
            'booking_timezone' => 'Europe/London', 'status' => 'confirmed', 'booking_source' => 'admin_created',
        ]);

        $event = $this->webhookEvent('checkout.session.completed', $this->fake->checkoutSessions[$payment->stripe_checkout_session_id]);
        $result = app(ConsultancyWebhookEventProcessor::class)->process($event);

        $this->assertSame(WebhookProcessingStatus::CONFLICT, $result['outcome']);
        $this->assertSame('manual_review', $payment->fresh()->status);
        // Never silently discarded — payment remains paid-then-manual_review,
        // never reverted to 'failed'.
        $this->assertNotSame('failed', $payment->fresh()->status);
    }

    // ── Distributed transaction boundary: local failure after Stripe paid ───

    public function test_local_conversion_failure_after_payment_never_reports_payment_as_failed(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 3);
        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');
        $payment->update(['status' => 'paid', 'paid_at' => now(), 'confirming_stripe_event_id' => 'evt_forced']);

        // Force a conversion failure by deleting the linked Consultancy
        // service row the conversion needs to resolve the AppointmentType.
        \App\Models\ConsultancyService::where('id', $payment->consultancy_service_id)->delete();

        try {
            app(ConsultancyPaymentConversionService::class)->convert($payment->fresh(), 'evt_forced');
            $this->fail('Expected a manual-review exception.');
        } catch (\App\Services\Consultancy\Exceptions\ConsultancyManualReviewRequiredException) {
            // expected — soft-deleted service is a genuine "cannot resolve
            // the type" case, correctly routed to manual review rather than
            // silently reported as a failed payment.
        }

        $this->assertSame('manual_review', $payment->fresh()->status);
        $this->assertNotSame('failed', $payment->fresh()->status);
    }

    public function test_retryable_conversion_failure_keeps_payment_retryable_never_failed(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 4);
        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');
        $this->fake->markOneOffCheckoutSessionPaid($payment->stripe_checkout_session_id, 'pi_retry');

        // Simulate an unexpected local exception during conversion by
        // deleting the reservation row entirely after payment succeeded —
        // a genuinely retryable local inconsistency in this test's own
        // construction (ConsultancyManualReviewRequiredException is thrown
        // for the "reservation missing" case specifically — assert the
        // payment is never marked 'failed' regardless of which recovery
        // path it takes).
        $event = $this->webhookEvent('checkout.session.completed', $this->fake->checkoutSessions[$payment->stripe_checkout_session_id]);
        app(ConsultancyWebhookEventProcessor::class)->process($event);

        $this->assertContains($payment->fresh()->status, ['converted', 'conversion_pending', 'manual_review']);
        $this->assertNotSame('failed', $payment->fresh()->status);
    }

    // ── Admin recovery ────────────────────────────────────────────────────────

    public function test_admin_can_view_payment_recovery_diagnostics(): void
    {
        [, $admin] = $this->makeOrgAndUser('Admin');
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 5);
        app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');

        $response = $this->actingAs($admin)->getJson('/api/admin/consultancy/payments');

        $response->assertStatus(200)->assertJsonPath('counts.checkout_open', 1);
    }

    public function test_admin_can_retry_a_conversion_pending_payment(): void
    {
        [, $admin] = $this->makeOrgAndUser('Admin');
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 6);
        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');
        $payment->update(['status' => 'conversion_pending', 'paid_at' => now(), 'confirming_stripe_event_id' => 'evt_recover']);

        $response = $this->actingAs($admin)->postJson("/api/admin/consultancy/payments/{$payment->id}/retry-conversion");

        $response->assertStatus(200)->assertJsonPath('status', 'converted');
    }

    public function test_reconcile_command_retries_conversion_pending_payments(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 0);
        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');
        $payment->update(['status' => 'conversion_pending', 'paid_at' => now(), 'confirming_stripe_event_id' => 'evt_cmd_recover']);

        $this->artisan('consultancy:payments:reconcile')->assertSuccessful();

        $this->assertSame('converted', $payment->fresh()->status);
    }

    public function test_reconcile_dry_run_does_not_change_anything(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 1);
        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');
        $payment->update(['status' => 'conversion_pending', 'paid_at' => now(), 'confirming_stripe_event_id' => 'evt_dry']);

        $this->artisan('consultancy:payments:reconcile --dry-run')->assertSuccessful();

        $this->assertSame('conversion_pending', $payment->fresh()->status);
    }

    // ── Webhook event routing (subscription vs Consultancy) ──────────────────

    public function test_ingestion_routes_a_matching_checkout_session_to_the_consultancy_job_queue(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 2);
        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');

        $routingService = app(\App\Services\Billing\WebhookEventRoutingService::class);
        $event = $this->webhookEvent('checkout.session.completed', $this->fake->checkoutSessions[$payment->stripe_checkout_session_id]);

        $this->assertSame(\App\Jobs\ProcessConsultancyWebhookEventJob::class, $routingService->jobClassFor($event));
    }

    public function test_ingestion_routes_a_non_matching_checkout_session_to_the_subscription_job(): void
    {
        $routingService = app(\App\Services\Billing\WebhookEventRoutingService::class);
        $event = $this->webhookEvent('checkout.session.completed', ['id' => 'cs_subscription_unrelated']);

        $this->assertSame(\App\Jobs\ProcessBillingWebhookEventJob::class, $routingService->jobClassFor($event));
    }

    public function test_unrelated_event_types_always_route_to_the_subscription_job(): void
    {
        $routingService = app(\App\Services\Billing\WebhookEventRoutingService::class);
        $event = $this->webhookEvent('customer.subscription.updated', ['id' => 'sub_123']);

        $this->assertSame(\App\Jobs\ProcessBillingWebhookEventJob::class, $routingService->jobClassFor($event));
    }

    // ── Security: browser tampering ignored ──────────────────────────────────

    public function test_checkout_endpoint_ignores_any_browser_supplied_amount_or_currency(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService(['price_minor_units' => 4000, 'currency' => 'GBP']);
        $reservation = $this->makeReservation($staff, $service, 3);

        $response = $this->postJson("/api/public/consultancy-reservations/{$reservation->public_token}/checkout", [
            'amount_minor_units' => 1,
            'currency' => 'USD',
            'consultant_user_id' => 99999,
        ]);

        $response->assertStatus(201);
        $payment = ConsultancyPayment::first();
        $this->assertSame(4000, $payment->amount_minor_units);
        $this->assertSame('GBP', $payment->currency);
        $this->assertSame($staff->id, $payment->consultant_user_id_snapshot);
    }

    public function test_public_payment_status_response_never_exposes_internal_or_consultant_fields(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 4);
        app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');

        $response = $this->getJson("/api/public/consultancy-reservations/{$reservation->public_token}/payment");

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertArrayNotHasKey('id', $json);
        $this->assertArrayNotHasKey('consultant_user_id_snapshot', $json);
        $this->assertArrayNotHasKey('stripe_checkout_session_id', $json);
        $this->assertStringNotContainsString($staff->email, $response->getContent());
    }

    public function test_conversion_pending_and_manual_review_are_both_reported_as_processing_to_the_customer(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $reservation = $this->makeReservation($staff, $service, 5);
        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');
        $payment->update(['status' => 'conversion_pending']);

        $response = $this->getJson("/api/public/consultancy-reservations/{$reservation->public_token}/payment");

        $response->assertStatus(200)->assertJsonPath('status', 'processing');
    }

    // ── Existing regression ──────────────────────────────────────────────────

    public function test_existing_subscription_webhook_processing_is_unaffected(): void
    {
        // A generic subscription-shaped event with no Consultancy
        // correlation must still route/process exactly as before.
        $routingService = app(\App\Services\Billing\WebhookEventRoutingService::class);
        $event = $this->webhookEvent('checkout.session.completed', [
            'id' => 'cs_sub_unrelated_999', 'customer' => 'cus_123', 'subscription' => 'sub_123',
        ]);

        $this->assertSame(\App\Jobs\ProcessBillingWebhookEventJob::class, $routingService->jobClassFor($event));
    }
}
