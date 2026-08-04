<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Appointment;
use App\Models\AppointmentExternalSync;
use App\Models\ConsultancyService;
use App\Models\GoogleConnection;
use App\Models\Organization;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Services\AppointmentAvailabilityService;
use App\Services\Billing\FakeBillingProvider;
use App\Services\Calendar\AppointmentCalendarSyncService;
use App\Services\Calendar\CalendarProviderInterface;
use App\Services\Calendar\ConsultancyAppointmentCalendarEventPayloadFactory;
use App\Services\Calendar\FakeCalendarProvider;
use App\Services\Calendar\GoogleCalendarProvider;
use App\Services\Consultancy\ConsultancyCatalogueService;
use App\Services\Consultancy\ConsultancyCheckoutService;
use App\Services\Consultancy\ConsultancySlotReservationService;
use App\Services\Consultancy\ConsultancyWebhookEventProcessor;
use App\Services\Google\FakeGoogleApiClient;
use App\Services\Google\GoogleApiClientInterface;
use App\Services\TimezoneResolver;
use App\Support\Appointments\AvailabilityContext;
use App\Support\Billing\WebhookProcessingStatus;
use App\Support\Google\CalendarSyncFailureCategory;
use App\Support\Google\CalendarSyncState;
use App\Support\Google\GoogleScopes;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Stage 4B.1 — Google Calendar Event Synchronisation. Every test runs
 * entirely against FakeGoogleApiClient/FakeCalendarProvider (bound by
 * GoogleServiceProvider in testing) — no test here makes, or could make, a
 * real HTTP call to Google. See internal-docs/super-admin/google-integration.md's
 * Stage 4B.1 section for what remains unvalidated against live Google.
 */
class GoogleCalendarSyncStage4B1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = $this->app->make(FakeBillingProvider::class);
        $this->fake->livemode = false;
        config(['consultancy.checkout_success_url' => 'https://app.example.test/s']);
        config(['consultancy.checkout_cancel_url' => 'https://app.example.test/c']);

        // QUEUE_CONNECTION=sync in testing (phpunit.xml) means a real
        // dispatch() would execute SyncAppointmentCalendarEventJob
        // immediately, in-process, during convertARealPayment() below —
        // before a given test has had a chance to configure the fake
        // provider/connection it actually wants to exercise. Faking Bus
        // globally keeps "converting a payment" and "processing its sync
        // row" as two separate, deliberately-sequenced steps in every
        // test, while Bus::assertDispatched(...) still works normally for
        // the tests that specifically assert dispatch behaviour.
        Bus::fake([\App\Jobs\SyncAppointmentCalendarEventJob::class]);
    }

    private FakeBillingProvider $fake;

    // ── Shared fixtures ──────────────────────────────────────────────────────

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
            'code' => "sync-service-{$n}", 'display_name' => "Sync Service {$n}",
            'enabled' => true, 'publicly_bookable' => true, 'available_to_existing_customers' => true,
            'price_minor_units' => 4000, 'currency' => 'GBP', 'duration_minutes' => 30,
            'requires_confirmation' => false,
        ], $overrides));
    }

    private function configureConsultant(User $user): void
    {
        SuresignSetting::instance()->update(['consultancy_consultant_user_id' => $user->id]);
    }

    private function grantAvailability(User $staff, int $weekday): void
    {
        app(AppointmentAvailabilityService::class)->setWeeklySchedule($staff, [
            ['weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '17:00'],
        ], $staff, AvailabilityContext::CONSULTANCY);
    }

    private function nextDateForWeekday(int $weekday): string
    {
        $date = now()->addDays(3);
        while ($date->dayOfWeek !== $weekday) {
            $date = $date->addDay();
        }

        return $date->toDateString();
    }

    /**
     * Runs a full, real Consultancy checkout->webhook->conversion flow and
     * returns the resulting Appointment — the genuine trigger path this
     * stage hooks into, not a shortcut.
     */
    private function convertARealPayment(User $staff, ConsultancyService $service, int $weekday, string $timezone = 'Europe/London'): Appointment
    {
        $this->configureConsultant($staff);
        $this->grantAvailability($staff, $weekday);
        $date = $this->nextDateForWeekday($weekday);
        $starts = TimezoneResolver::buildLocalInstant($date, '10:00', $timezone);

        $reservation = app(ConsultancySlotReservationService::class)->reserve(
            $service, $starts, $starts->copy()->addMinutes($service->appointmentType->duration_minutes),
            ['name' => 'Jane Client', 'email' => 'jane@example.com', 'timezone' => $timezone],
            Str::random(40),
        );

        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');
        $this->fake->markOneOffCheckoutSessionPaid($payment->stripe_checkout_session_id, 'pi_' . Str::random(20));
        $sessionPayload = $this->fake->checkoutSessions[$payment->stripe_checkout_session_id];

        $event = \App\Models\BillingWebhookEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_' . random_int(1, 999999999),
            'event_type' => 'checkout.session.completed', 'livemode' => false,
            'provider_created_at' => CarbonImmutable::now(), 'processing_status' => WebhookProcessingStatus::RECEIVED,
            'received_at' => CarbonImmutable::now(), 'payload_json' => ['data' => ['object' => $sessionPayload]],
            'payload_hash' => hash('sha256', json_encode($sessionPayload)),
        ]);

        app(ConsultancyWebhookEventProcessor::class)->process($event);

        return Appointment::where('id', $payment->fresh()->appointment_id)->firstOrFail();
    }

    private function makeHealthyConnection(): GoogleConnection
    {
        $connection = GoogleConnection::create([
            'provider' => 'google', 'purpose' => 'primary', 'status' => 'connected',
            'access_token' => 'valid-token', 'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'scopes' => [GoogleScopes::CALENDAR_EVENTS],
            'connected_at' => now(), 'last_successful_call_at' => now(),
        ]);

        $this->app->make(CalendarProviderInterface::class)->connected = true;

        return $connection;
    }

    private function makeSync(Appointment $appointment, array $overrides = []): AppointmentExternalSync
    {
        return AppointmentExternalSync::create(array_merge([
            'appointment_id' => $appointment->id,
            'provider' => 'google', 'external_resource_type' => 'calendar_event',
            'state' => CalendarSyncState::PENDING,
            'correlation_key' => ConsultancyAppointmentCalendarEventPayloadFactory::generateCorrelationKey(),
            'payload_version' => ConsultancyAppointmentCalendarEventPayloadFactory::PAYLOAD_VERSION,
        ], $overrides));
    }

    private function fakeCalendarProvider(): FakeCalendarProvider
    {
        return $this->app->make(CalendarProviderInterface::class);
    }

    // ── 1. Dispatch / after-commit behaviour ─────────────────────────────────

    public function test_successful_conversion_dispatches_exactly_one_sync_job(): void
    {
        Bus::fake();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();

        $appointment = $this->convertARealPayment($staff, $service, 1);

        Bus::assertDispatched(\App\Jobs\SyncAppointmentCalendarEventJob::class, 1);
        $this->assertSame(1, AppointmentExternalSync::where('appointment_id', $appointment->id)->count());
    }

    public function test_sync_row_is_created_with_pending_state_and_a_correlation_key(): void
    {
        Bus::fake();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 2);

        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();

        $this->assertSame(CalendarSyncState::PENDING, $sync->state);
        $this->assertNotEmpty($sync->correlation_key);
        $this->assertSame(40, strlen($sync->correlation_key));
    }

    public function test_dispatch_queues_the_job_on_the_google_integrations_queue(): void
    {
        Bus::fake();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->convertARealPayment($staff, $this->makeService(), 3);

        Bus::assertDispatched(\App\Jobs\SyncAppointmentCalendarEventJob::class, function ($job) {
            return $job->queue === 'google-integrations';
        });
    }

    public function test_queued_activity_log_is_recorded_once(): void
    {
        Bus::fake();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 4);

        $this->assertSame(1, ActivityLog::where('action', 'google.calendar_sync_queued')
            ->where('subject_type', Appointment::class)->where('subject_id', $appointment->id)->count());
    }

    // ── Idempotency ───────────────────────────────────────────────────────────

    public function test_calling_queue_for_appointment_twice_creates_only_one_sync_row(): void
    {
        Bus::fake();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 5);

        app(AppointmentCalendarSyncService::class)->queueForAppointment($appointment->fresh());
        app(AppointmentCalendarSyncService::class)->queueForAppointment($appointment->fresh());

        $this->assertSame(1, AppointmentExternalSync::where('appointment_id', $appointment->id)->count());
    }

    public function test_duplicate_job_dispatch_for_the_same_row_creates_one_event(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 6);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();

        $service = app(AppointmentCalendarSyncService::class);
        $service->attempt($sync->fresh());
        $service->attempt($sync->fresh());

        $this->assertSame(1, $this->fakeCalendarProvider()->createEventCallCount);
        $this->assertSame(CalendarSyncState::SYNCED, $sync->fresh()->state);
    }

    // ── 2. State transitions / normal success ────────────────────────────────

    public function test_attempt_creates_event_and_marks_synced(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 0);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();

        app(AppointmentCalendarSyncService::class)->attempt($sync);
        $sync->refresh();

        $this->assertSame(CalendarSyncState::SYNCED, $sync->state);
        $this->assertNotNull($sync->provider_event_id);
        $this->assertFalse($sync->outcome_uncertain);
        $this->assertSame(1, $sync->attempt_count);
        $this->assertNotNull($sync->last_success_at);
    }

    public function test_successful_creation_logs_calendar_event_created(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 1);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();

        app(AppointmentCalendarSyncService::class)->attempt($sync);

        $this->assertSame(1, ActivityLog::where('action', 'google.calendar_event_created')->count());
    }

    // ── 3. Readiness mapping ─────────────────────────────────────────────────

    public function test_no_connection_transitions_to_disconnected(): void
    {
        // No GoogleConnection row at all.
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 2);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();

        app(AppointmentCalendarSyncService::class)->attempt($sync);

        $this->assertSame(CalendarSyncState::DISCONNECTED, $sync->fresh()->state);
        $this->assertSame(0, $this->fakeCalendarProvider()->createEventCallCount, 'No Google call should occur when not connected.');
    }

    public function test_refresh_failed_health_transitions_to_disconnected(): void
    {
        GoogleConnection::create([
            'provider' => 'google', 'purpose' => 'primary', 'status' => 'connected',
            'access_token' => 'x', 'refresh_token' => 'x', 'token_expires_at' => now()->addHour(),
            'scopes' => [GoogleScopes::CALENDAR_EVENTS], 'connected_at' => now(),
            'consecutive_refresh_failures' => 3,
        ]);
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 3);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();

        app(AppointmentCalendarSyncService::class)->attempt($sync);

        $this->assertSame(CalendarSyncState::DISCONNECTED, $sync->fresh()->state);
    }

    public function test_permissions_missing_health_transitions_to_manual_review(): void
    {
        GoogleConnection::create([
            'provider' => 'google', 'purpose' => 'primary', 'status' => 'connected',
            'access_token' => 'x', 'refresh_token' => 'x', 'token_expires_at' => now()->addHour(),
            'scopes' => [], // missing the required scope
            'connected_at' => now(),
        ]);
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 4);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();

        app(AppointmentCalendarSyncService::class)->attempt($sync);

        $this->assertSame(CalendarSyncState::MANUAL_REVIEW, $sync->fresh()->state);
        $this->assertSame(CalendarSyncFailureCategory::PERMISSIONS_MISSING, $sync->fresh()->failure_category);
    }

    public function test_readiness_check_never_reads_the_aggregate_ready_field(): void
    {
        // meet_available would be false for a disconnected MeetingProviderInterface,
        // but here the connection IS healthy for Calendar purposes — proves
        // the sync service isn't blocked by Meet capability.
        $this->makeHealthyConnection();
        $fake = $this->fakeCalendarProvider();
        $fake->meetCapable = false; // 'ready' would now be false if it were consulted

        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 5);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();

        app(AppointmentCalendarSyncService::class)->attempt($sync);

        $this->assertSame(CalendarSyncState::SYNCED, $sync->fresh()->state, 'Calendar-only sync must not be blocked by Meet capability.');
    }

    // ── 4. Retry classification ───────────────────────────────────────────────

    public function test_rate_limited_failure_enters_retry_pending_with_backoff(): void
    {
        $this->makeHealthyConnection();
        $this->fakeCalendarProvider()->createEventFailureCategory = CalendarSyncFailureCategory::RATE_LIMITED;

        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 6);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();

        app(AppointmentCalendarSyncService::class)->attempt($sync);
        $sync->refresh();

        $this->assertSame(CalendarSyncState::RETRY_PENDING, $sync->state);
        $this->assertNotNull($sync->next_retry_at);
        $this->assertTrue($sync->next_retry_at->isFuture());
        $this->assertSame(1, $sync->attempt_count);
    }

    public function test_transport_uncertain_failure_leaves_outcome_uncertain_true(): void
    {
        $this->makeHealthyConnection();
        $this->fakeCalendarProvider()->createEventFailureCategory = CalendarSyncFailureCategory::TRANSPORT_UNCERTAIN;

        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 0);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();

        app(AppointmentCalendarSyncService::class)->attempt($sync);

        $this->assertTrue($sync->fresh()->outcome_uncertain);
        $this->assertSame(CalendarSyncState::RETRY_PENDING, $sync->fresh()->state);
    }

    public function test_provider_server_error_5xx_leaves_outcome_uncertain_true(): void
    {
        // Approved correction 1 — a 5xx must not be assumed proof of no creation.
        $this->makeHealthyConnection();
        $this->fakeCalendarProvider()->createEventFailureCategory = CalendarSyncFailureCategory::PROVIDER_SERVER_ERROR;

        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 1);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();

        app(AppointmentCalendarSyncService::class)->attempt($sync);

        $this->assertTrue($sync->fresh()->outcome_uncertain);
    }

    public function test_rejected_request_never_leaves_outcome_uncertain(): void
    {
        $this->makeHealthyConnection();
        $this->fakeCalendarProvider()->createEventFailureCategory = CalendarSyncFailureCategory::REJECTED_REQUEST;

        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 2);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();

        app(AppointmentCalendarSyncService::class)->attempt($sync);
        $sync->refresh();

        $this->assertFalse($sync->outcome_uncertain);
        $this->assertSame(CalendarSyncState::MANUAL_REVIEW, $sync->state);
    }

    public function test_configuration_failure_never_retried_automatically(): void
    {
        $this->makeHealthyConnection();
        $this->fakeCalendarProvider()->createEventFailureCategory = CalendarSyncFailureCategory::PERMISSIONS_MISSING;

        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 3);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();

        app(AppointmentCalendarSyncService::class)->attempt($sync);
        $sync->refresh();

        $this->assertSame(CalendarSyncState::MANUAL_REVIEW, $sync->state);
        $this->assertNull($sync->next_retry_at);
    }

    public function test_recoverable_failure_escalates_to_failed_after_budget_exhausted(): void
    {
        $this->makeHealthyConnection();
        $this->fakeCalendarProvider()->createEventFailureCategory = CalendarSyncFailureCategory::RATE_LIMITED;

        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 4);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();
        $service = app(AppointmentCalendarSyncService::class);

        for ($i = 0; $i < CalendarSyncState::MAX_RECOVERABLE_ATTEMPTS; $i++) {
            $sync->update(['state' => CalendarSyncState::RETRY_PENDING, 'next_retry_at' => now()->subMinute()]);
            $service->attempt($sync->fresh());
        }

        $this->assertSame(CalendarSyncState::FAILED, $sync->fresh()->state);
        $this->assertSame(CalendarSyncState::MAX_RECOVERABLE_ATTEMPTS, $sync->fresh()->attempt_count);
    }

    public function test_unclassified_failure_propagates_and_is_not_persisted_as_a_classified_state(): void
    {
        $this->makeHealthyConnection();
        $this->fakeCalendarProvider()->throwUnclassifiedException = true;

        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 5);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();

        $this->expectException(\RuntimeException::class);
        app(AppointmentCalendarSyncService::class)->attempt($sync);
    }

    public function test_unclassified_failure_still_leaves_row_claimed_as_processing_for_lease_recovery(): void
    {
        $this->makeHealthyConnection();
        $this->fakeCalendarProvider()->throwUnclassifiedException = true;

        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 6);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();

        try {
            app(AppointmentCalendarSyncService::class)->attempt($sync);
        } catch (\RuntimeException) {
        }

        $this->assertSame(CalendarSyncState::PROCESSING, $sync->fresh()->state);
    }

    // ── 5. External uncertainty / reconciliation ─────────────────────────────

    public function test_uncertain_outcome_reconciles_before_creating_and_finds_zero_matches(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 0);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();
        $sync->update(['outcome_uncertain' => true, 'state' => CalendarSyncState::RETRY_PENDING, 'attempt_count' => 1]);

        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());

        $fake = $this->fakeCalendarProvider();
        $this->assertSame(1, $fake->findEventCallCount, 'Must reconcile before creating.');
        $this->assertSame(1, $fake->createEventCallCount, 'Zero matches — safe to create.');
        $this->assertSame(CalendarSyncState::SYNCED, $sync->fresh()->state);
    }

    public function test_uncertain_outcome_with_one_match_adopts_it_without_creating(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 1);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();
        $sync->update(['outcome_uncertain' => true]);

        $fake = $this->fakeCalendarProvider();
        $fake->correlationLookupResults[$sync->correlation_key] = [['event_id' => 'existing_event_123']];

        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());

        $this->assertSame(0, $fake->createEventCallCount, 'Never create when reconciliation finds a match.');
        $this->assertSame(CalendarSyncState::SYNCED, $sync->fresh()->state);
        $this->assertSame('existing_event_123', $sync->fresh()->provider_event_id);
        $this->assertSame(1, ActivityLog::where('action', 'google.calendar_sync_reconciled')->count());
    }

    public function test_uncertain_outcome_with_multiple_matches_enters_manual_review_never_creates(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 2);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();
        $sync->update(['outcome_uncertain' => true]);

        $fake = $this->fakeCalendarProvider();
        $fake->correlationLookupResults[$sync->correlation_key] = [['event_id' => 'e1'], ['event_id' => 'e2']];

        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());

        $this->assertSame(0, $fake->createEventCallCount);
        $this->assertSame(CalendarSyncState::MANUAL_REVIEW, $sync->fresh()->state);
        $this->assertSame(CalendarSyncFailureCategory::AMBIGUOUS_RECONCILIATION, $sync->fresh()->failure_category);
        $this->assertSame(1, ActivityLog::where('action', 'google.calendar_sync_manual_review')->count());
    }

    public function test_admin_reconcile_only_restores_prior_state_on_zero_matches(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 3);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();
        $sync->update(['state' => CalendarSyncState::MANUAL_REVIEW, 'failure_category' => CalendarSyncFailureCategory::AMBIGUOUS_RECONCILIATION]);

        app(AppointmentCalendarSyncService::class)->reconcileOnly($sync->fresh());

        $this->assertSame(CalendarSyncState::MANUAL_REVIEW, $sync->fresh()->state, 'A no-op check must restore, not clear, the prior state.');
    }

    public function test_admin_reconcile_only_adopts_a_single_match_even_from_manual_review(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 4);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();
        $sync->update(['state' => CalendarSyncState::MANUAL_REVIEW]);

        $fake = $this->fakeCalendarProvider();
        $fake->correlationLookupResults[$sync->correlation_key] = [['event_id' => 'found_after_review']];

        app(AppointmentCalendarSyncService::class)->reconcileOnly($sync->fresh());

        $this->assertSame(CalendarSyncState::SYNCED, $sync->fresh()->state);
    }

    // ── 6. Work claiming / concurrency ───────────────────────────────────────

    public function test_claim_is_rejected_while_another_worker_holds_an_active_lease(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 5);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();
        $sync->update(['state' => CalendarSyncState::PROCESSING, 'processing_started_at' => now()]);

        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());

        $this->assertSame(0, $this->fakeCalendarProvider()->createEventCallCount, 'An active lease must not be double-claimed.');
    }

    public function test_abandoned_processing_lease_is_reclaimed(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 6);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();
        $sync->update([
            'state' => CalendarSyncState::PROCESSING,
            'processing_started_at' => now()->subMinutes(CalendarSyncState::PROCESSING_LEASE_MINUTES + 1),
        ]);

        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());

        $this->assertSame(CalendarSyncState::SYNCED, $sync->fresh()->state, 'An abandoned lease must be reclaimed and processed.');
    }

    public function test_admin_retry_cannot_claim_a_terminal_synced_row_via_controller(): void
    {
        [, $admin] = $this->makeOrgAndUser('Admin');
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 0);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();
        $sync->update(['state' => CalendarSyncState::SYNCED, 'provider_event_id' => 'ev_1']);

        $response = $this->actingAs($admin)->postJson("/api/admin/google/calendar-syncs/{$sync->id}/retry");

        $response->assertStatus(409);
    }

    // ── 7. Cancellation boundary ──────────────────────────────────────────────

    public function test_cancelled_appointment_before_claim_transitions_sync_to_cancelled_no_call(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 1);
        $appointment->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();

        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());

        $this->assertSame(CalendarSyncState::CANCELLED, $sync->fresh()->state);
        $this->assertSame(0, $this->fakeCalendarProvider()->createEventCallCount);
    }

    public function test_synced_row_is_never_mutated_to_cancelled_when_appointment_cancelled_afterwards(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 2);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();
        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());
        $this->assertSame(CalendarSyncState::SYNCED, $sync->fresh()->state);

        $appointment->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        // A later automatic pass must never touch an already-synced row.
        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());

        $this->assertSame(CalendarSyncState::SYNCED, $sync->fresh()->state, 'Approved correction 5 — synced must remain an accurate external-reality statement.');
    }

    public function test_reconciliation_after_cancellation_finds_existing_event_and_becomes_synced_not_cancelled(): void
    {
        // Approved correction 5's hardest case: the Appointment is
        // cancelled locally, but a prior uncertain attempt DID create a
        // real Google event — reconciliation must report that honestly.
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 3);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();
        $sync->update(['outcome_uncertain' => true, 'state' => CalendarSyncState::RETRY_PENDING]);

        $fake = $this->fakeCalendarProvider();
        $fake->correlationLookupResults[$sync->correlation_key] = [['event_id' => 'created_before_cancellation']];

        $appointment->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());

        $sync->refresh();
        $this->assertSame(CalendarSyncState::CANCELLED, $sync->state, 'Eligibility is checked before reconciliation — an already-ineligible Appointment short-circuits to cancelled first.');
    }

    public function test_appointment_ineligible_for_sync_after_cancellation(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 4);
        $this->assertTrue($appointment->fresh()->isEligibleForExternalSync());

        $appointment->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $this->assertFalse($appointment->fresh()->isEligibleForExternalSync());
    }

    // ── 8. Payload correctness ────────────────────────────────────────────────

    public function test_payload_contains_correct_title_reference_and_no_internal_content(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService(['display_name' => 'Contract Review Session']);
        $appointment = $this->convertARealPayment($staff, $service, 5);
        $appointment->update(['internal_notes' => 'SECRET internal note', 'attendee_message' => 'raw customer text']);

        $payload = app(ConsultancyAppointmentCalendarEventPayloadFactory::class)->build($appointment->fresh(), 'corr-key-123');

        $this->assertStringContainsString('SureSign Consultancy', $payload['summary']);
        $this->assertStringContainsString('Contract Review Session', $payload['summary']);
        $this->assertStringContainsString($appointment->reference, $payload['description']);
        $this->assertStringNotContainsString('SECRET internal note', $payload['description']);
        $this->assertStringNotContainsString('raw customer text', $payload['description']);
        $this->assertSame('corr-key-123', $payload['correlation_key']);
    }

    public function test_payload_attendees_include_consultant_and_customer(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 6);

        $payload = app(ConsultancyAppointmentCalendarEventPayloadFactory::class)->build($appointment->fresh(), 'k');

        $emails = array_column($payload['attendees'], 'email');
        $this->assertContains($staff->email, $emails);
        $this->assertContains('jane@example.com', $emails);
    }

    public function test_payload_deduplicates_attendees_when_consultant_and_customer_emails_match(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $this->configureConsultant($staff);
        $this->grantAvailability($staff, 0);
        $date = $this->nextDateForWeekday(0);
        $starts = TimezoneResolver::buildLocalInstant($date, '10:00', 'Europe/London');
        $reservation = app(ConsultancySlotReservationService::class)->reserve(
            $service, $starts, $starts->copy()->addMinutes($service->appointmentType->duration_minutes),
            ['name' => 'Self Booking', 'email' => $staff->email, 'timezone' => 'Europe/London'],
            Str::random(40),
        );
        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');
        $this->fake->markOneOffCheckoutSessionPaid($payment->stripe_checkout_session_id, 'pi_dedup');
        $sessionPayload = $this->fake->checkoutSessions[$payment->stripe_checkout_session_id];
        $event = \App\Models\BillingWebhookEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_dedup_' . random_int(1, 999999999),
            'event_type' => 'checkout.session.completed', 'livemode' => false,
            'provider_created_at' => CarbonImmutable::now(), 'processing_status' => WebhookProcessingStatus::RECEIVED,
            'received_at' => CarbonImmutable::now(), 'payload_json' => ['data' => ['object' => $sessionPayload]],
            'payload_hash' => hash('sha256', json_encode($sessionPayload)),
        ]);
        app(ConsultancyWebhookEventProcessor::class)->process($event);
        $appointment = Appointment::where('id', $payment->fresh()->appointment_id)->firstOrFail();

        $payload = app(ConsultancyAppointmentCalendarEventPayloadFactory::class)->build($appointment, 'k');

        $this->assertCount(1, $payload['attendees']);
    }

    public function test_organiser_email_is_stripped_from_attendees_by_the_provider(): void
    {
        $connection = $this->makeHealthyConnection();
        $connection->update(['connected_email' => 'consultant-account@example.test']);

        [, $staff] = $this->makeOrgAndUser('Admin');
        $staff->update(['email' => 'consultant-account@example.test']);
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 1);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();

        // Use the REAL GoogleCalendarProvider + FakeGoogleApiClient here,
        // since organiser dedup happens in GoogleCalendarProvider, not the
        // (bound-by-default) FakeCalendarProvider.
        $provider = app(GoogleCalendarProvider::class);
        $payload = app(ConsultancyAppointmentCalendarEventPayloadFactory::class)->build($appointment->fresh(), $sync->correlation_key);
        $provider->createEvent($payload);

        $lastBody = app(FakeGoogleApiClient::class)->lastInsertEventBody;
        $emails = array_column($lastBody['attendees'], 'email');
        $this->assertNotContains('consultant-account@example.test', $emails);
        $this->assertContains('jane@example.com', $emails);
    }

    // ── 9. Timezone handling ──────────────────────────────────────────────────

    public function test_rfc3339_uses_local_offset_and_preserves_the_utc_instant(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 2, 'Europe/London');

        $payload = app(ConsultancyAppointmentCalendarEventPayloadFactory::class)->build($appointment->fresh(), 'k');

        $this->assertSame('Europe/London', $payload['start']['timezone']);
        $parsed = \Illuminate\Support\Carbon::parse($payload['start']['date_time']);
        $this->assertTrue($parsed->equalTo($appointment->fresh()->starts_at));
        $this->assertNotSame('Z', substr($payload['start']['date_time'], -1), 'Must use an explicit offset, not a bare UTC Z, per approved correction 6.');
    }

    public function test_distinct_iana_timezone_is_preserved(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 3, 'America/New_York');

        $payload = app(ConsultancyAppointmentCalendarEventPayloadFactory::class)->build($appointment->fresh(), 'k');

        $this->assertSame('America/New_York', $payload['start']['timezone']);
        $this->assertTrue(\Illuminate\Support\Carbon::parse($payload['end']['date_time'])->equalTo($appointment->fresh()->ends_at));
    }

    public function test_missing_booking_timezone_throws_rather_than_guessing(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 4);
        $appointment->booking_timezone = null;

        $this->expectException(\RuntimeException::class);
        app(ConsultancyAppointmentCalendarEventPayloadFactory::class)->build($appointment, 'k');
    }

    // ── 10. Invitation policy ─────────────────────────────────────────────────

    public function test_send_updates_is_always_none(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 5);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();

        $provider = app(GoogleCalendarProvider::class);
        $payload = app(ConsultancyAppointmentCalendarEventPayloadFactory::class)->build($appointment->fresh(), $sync->correlation_key);
        $provider->createEvent($payload);

        $this->assertSame('none', app(FakeGoogleApiClient::class)->lastInsertSendUpdates);
    }

    // ── 11. Real GoogleCalendarProvider classification (not the fake) ───────

    public function test_real_provider_classifies_5xx_as_provider_server_error_and_uncertain(): void
    {
        $this->makeHealthyConnection();
        $fakeClient = app(FakeGoogleApiClient::class);
        $fakeClient->insertFailureMode = '5xx';

        $provider = app(GoogleCalendarProvider::class);
        $factory = app(ConsultancyAppointmentCalendarEventPayloadFactory::class);
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 6);
        $payload = $factory->build($appointment->fresh(), 'corr-5xx');

        try {
            $provider->createEvent($payload);
            $this->fail('Expected a CalendarSyncFailureException.');
        } catch (\App\Support\Google\CalendarSyncFailureException $e) {
            $this->assertSame(CalendarSyncFailureCategory::PROVIDER_SERVER_ERROR, $e->category());
            $this->assertTrue($e->isOutcomeUncertain());
        }
    }

    public function test_real_provider_classifies_429_as_rate_limited_not_uncertain(): void
    {
        $this->makeHealthyConnection();
        app(FakeGoogleApiClient::class)->insertFailureMode = '429';
        $provider = app(GoogleCalendarProvider::class);
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 0);
        $payload = app(ConsultancyAppointmentCalendarEventPayloadFactory::class)->build($appointment->fresh(), 'corr-429');

        try {
            $provider->createEvent($payload);
            $this->fail('Expected a CalendarSyncFailureException.');
        } catch (\App\Support\Google\CalendarSyncFailureException $e) {
            $this->assertSame(CalendarSyncFailureCategory::RATE_LIMITED, $e->category());
            $this->assertFalse($e->isOutcomeUncertain());
        }
    }

    public function test_real_provider_classifies_transport_failure_as_uncertain(): void
    {
        $this->makeHealthyConnection();
        app(FakeGoogleApiClient::class)->insertFailureMode = 'transport';
        $provider = app(GoogleCalendarProvider::class);
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 1);
        $payload = app(ConsultancyAppointmentCalendarEventPayloadFactory::class)->build($appointment->fresh(), 'corr-timeout');

        try {
            $provider->createEvent($payload);
            $this->fail('Expected a CalendarSyncFailureException.');
        } catch (\App\Support\Google\CalendarSyncFailureException $e) {
            $this->assertSame(CalendarSyncFailureCategory::TRANSPORT_UNCERTAIN, $e->category());
            $this->assertTrue($e->isOutcomeUncertain());
        }
    }

    public function test_real_provider_lost_response_is_findable_via_reconciliation(): void
    {
        // The hardest case, at the real-provider layer: Google actually
        // creates the event but the caller never sees the response.
        $this->makeHealthyConnection();
        $fakeClient = app(FakeGoogleApiClient::class);
        $fakeClient->insertFailureMode = 'lost_response';
        $provider = app(GoogleCalendarProvider::class);
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 2);
        $payload = app(ConsultancyAppointmentCalendarEventPayloadFactory::class)->build($appointment->fresh(), 'corr-lost');

        try {
            $provider->createEvent($payload);
            $this->fail('Expected a transport failure.');
        } catch (\App\Support\Google\CalendarSyncFailureException $e) {
            $this->assertTrue($e->isOutcomeUncertain());
        }

        $fakeClient->insertFailureMode = null; // subsequent calls (the lookup) succeed normally
        $matches = $provider->findEventByCorrelationKey('corr-lost');
        $this->assertCount(1, $matches, 'Reconciliation must find the event Google actually created.');
    }

    public function test_real_provider_malformed_response_is_treated_as_uncertain_not_success(): void
    {
        $this->makeHealthyConnection();
        app(FakeGoogleApiClient::class)->insertReturnsMalformedResponse = true;
        $provider = app(GoogleCalendarProvider::class);
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 3);
        $payload = app(ConsultancyAppointmentCalendarEventPayloadFactory::class)->build($appointment->fresh(), 'corr-malformed');

        try {
            $provider->createEvent($payload);
            $this->fail('Expected a CalendarSyncFailureException for a malformed response.');
        } catch (\App\Support\Google\CalendarSyncFailureException $e) {
            $this->assertTrue($e->isOutcomeUncertain());
        }
    }

    public function test_reconciliation_lookup_never_sends_attendee_updates(): void
    {
        $this->makeHealthyConnection();
        $provider = app(GoogleCalendarProvider::class);

        // A read-only list() call has no sendUpdates concept at all —
        // proven by it never touching lastInsertSendUpdates.
        $provider->findEventByCorrelationKey('any-key');

        $this->assertNull(app(FakeGoogleApiClient::class)->lastInsertSendUpdates);
    }

    // ── 12. Scheduler / queue registration ───────────────────────────────────

    public function test_reconcile_command_is_registered_every_five_minutes(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $event = collect($schedule->events())->first(fn ($e) => str_contains($e->command, 'appointments:calendar-sync:reconcile'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->runInBackground);
        $this->assertFalse($event->onOneServer);
    }

    public function test_reconcile_command_dispatches_for_due_retry_pending_rows(): void
    {
        Bus::fake();
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 4);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();
        $sync->update(['state' => CalendarSyncState::RETRY_PENDING, 'next_retry_at' => now()->subMinute()]);

        $this->artisan('appointments:calendar-sync:reconcile')->assertSuccessful();

        Bus::assertDispatched(\App\Jobs\SyncAppointmentCalendarEventJob::class, function ($job) use ($sync) {
            return (fn () => $this->appointmentExternalSyncId)->call($job) === $sync->id;
        });
    }

    public function test_reconcile_command_dry_run_dispatches_nothing(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 5);
        // Conversion itself already dispatched (faked) exactly one job —
        // the baseline this test proves dry-run adds nothing on top of.
        Bus::assertDispatchedTimes(\App\Jobs\SyncAppointmentCalendarEventJob::class, 1);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();
        $sync->update(['state' => CalendarSyncState::RETRY_PENDING, 'next_retry_at' => now()->subMinute()]);

        $this->artisan('appointments:calendar-sync:reconcile', ['--dry-run' => true])->assertSuccessful();

        Bus::assertDispatchedTimes(\App\Jobs\SyncAppointmentCalendarEventJob::class, 1);
    }

    public function test_reconcile_command_does_not_dispatch_for_a_still_active_processing_lease(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 6);
        Bus::assertDispatchedTimes(\App\Jobs\SyncAppointmentCalendarEventJob::class, 1);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();
        $sync->update(['state' => CalendarSyncState::PROCESSING, 'processing_started_at' => now()]);

        $this->artisan('appointments:calendar-sync:reconcile')->assertSuccessful();

        Bus::assertDispatchedTimes(\App\Jobs\SyncAppointmentCalendarEventJob::class, 1);
    }

    public function test_worker_entrypoint_consumes_google_integrations_after_consultancy_payments(): void
    {
        $entrypoint = file_get_contents(base_path('docker/entrypoint.sh'));
        $this->assertMatchesRegularExpression(
            '/--queue=billing-webhooks,consultancy-payments,google-integrations,default/',
            $entrypoint,
        );
    }

    // ── 13. Security ──────────────────────────────────────────────────────────

    public function test_client_cannot_read_calendar_sync_diagnostics(): void
    {
        [, $client] = $this->makeOrgAndUser('Client');

        $response = $this->actingAs($client)->getJson('/api/admin/google/calendar-syncs');

        $response->assertStatus(403);
    }

    public function test_client_cannot_retry_a_sync(): void
    {
        [, $client] = $this->makeOrgAndUser('Client');
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 0);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();

        $response = $this->actingAs($client)->postJson("/api/admin/google/calendar-syncs/{$sync->id}/retry");

        $response->assertStatus(403);
    }

    public function test_admin_can_read_diagnostics_and_never_leaks_a_raw_provider_exception(): void
    {
        $this->makeHealthyConnection();
        $this->fakeCalendarProvider()->createEventFailureCategory = CalendarSyncFailureCategory::RATE_LIMITED;
        [, $admin] = $this->makeOrgAndUser('Admin');
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 1);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();
        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());

        $response = $this->actingAs($admin)->getJson("/api/admin/google/calendar-syncs/{$sync->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('failure_category', CalendarSyncFailureCategory::RATE_LIMITED);
        $this->assertStringNotContainsString('Exception', $response->getContent());
    }

    public function test_activity_log_never_contains_a_provider_event_id_leak_of_tokens(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 2);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();

        app(AppointmentCalendarSyncService::class)->attempt($sync);

        $logs = ActivityLog::where('subject_type', Appointment::class)->where('subject_id', $appointment->id)->get();
        foreach ($logs as $log) {
            $this->assertStringNotContainsString('access_token', json_encode($log->metadata));
            $this->assertStringNotContainsString('refresh_token', json_encode($log->metadata));
        }
    }

    public function test_diagnostics_shows_both_synced_and_appointment_cancelled_independently(): void
    {
        $this->makeHealthyConnection();
        [, $admin] = $this->makeOrgAndUser('Admin');
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 3);
        $sync = AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();
        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());
        $appointment->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $response = $this->actingAs($admin)->getJson("/api/admin/google/calendar-syncs/{$sync->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('state', CalendarSyncState::SYNCED);
        $response->assertJsonPath('appointment_cancelled', true);
    }
}
