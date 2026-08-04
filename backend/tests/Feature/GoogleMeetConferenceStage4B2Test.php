<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Appointment;
use App\Models\AppointmentExternalSync;
use App\Models\ConsultancyService;
use App\Models\GoogleConnection;
use App\Models\SuresignSetting;
use App\Models\Organization;
use App\Models\User;
use App\Services\AppointmentAvailabilityService;
use App\Services\Billing\FakeBillingProvider;
use App\Services\Calendar\AppointmentCalendarSyncService;
use App\Services\Calendar\CalendarProviderInterface;
use App\Services\Calendar\ConsultancyAppointmentCalendarEventPayloadFactory;
use App\Services\Calendar\FakeCalendarProvider;
use App\Services\Calendar\GoogleCalendarProvider;
use App\Services\Consultancy\ConsultancyBookingReadinessService;
use App\Services\Consultancy\ConsultancyCatalogueService;
use App\Services\Consultancy\ConsultancyCheckoutService;
use App\Services\Consultancy\ConsultancySlotReservationService;
use App\Services\Consultancy\ConsultancyWebhookEventProcessor;
use App\Services\Google\FakeGoogleApiClient;
use App\Services\Google\GoogleIntegrationReadinessService;
use App\Services\TimezoneResolver;
use App\Support\Appointments\AvailabilityContext;
use App\Support\Billing\WebhookProcessingStatus;
use App\Support\Google\CalendarSyncFailureCategory;
use App\Support\Google\CalendarSyncState;
use App\Support\Google\GoogleScopes;
use App\Support\Google\MeetConferenceState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Stage 4B.2 — Google Meet Conference Generation. Every test runs entirely
 * against FakeGoogleApiClient/FakeCalendarProvider — no real Google HTTP
 * call is possible from any test in this codebase.
 */
class GoogleMeetConferenceStage4B2Test extends TestCase
{
    use RefreshDatabase;

    private FakeBillingProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = $this->app->make(FakeBillingProvider::class);
        $this->fake->livemode = false;
        config(['consultancy.checkout_success_url' => 'https://app.example.test/s']);
        config(['consultancy.checkout_cancel_url' => 'https://app.example.test/c']);
        Bus::fake([\App\Jobs\SyncAppointmentCalendarEventJob::class]);
    }

    // ── Shared fixtures (mirrors GoogleCalendarSyncStage4B1Test) ─────────────

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
            'code' => "meet-service-{$n}", 'display_name' => "Meet Service {$n}",
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

    private function convertARealPayment(User $staff, ConsultancyService $service, int $weekday): Appointment
    {
        $this->configureConsultant($staff);
        $this->grantAvailability($staff, $weekday);
        $date = $this->nextDateForWeekday($weekday);
        $starts = TimezoneResolver::buildLocalInstant($date, '10:00', 'Europe/London');

        $reservation = app(ConsultancySlotReservationService::class)->reserve(
            $service, $starts, $starts->copy()->addMinutes($service->appointmentType->duration_minutes),
            ['name' => 'Jane Client', 'email' => 'jane@example.com', 'timezone' => 'Europe/London'],
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

    private function fakeCalendarProvider(): FakeCalendarProvider
    {
        return $this->app->make(CalendarProviderInterface::class);
    }

    private function syncFor(Appointment $appointment): AppointmentExternalSync
    {
        return AppointmentExternalSync::where('appointment_id', $appointment->id)->firstOrFail();
    }

    // ── A. Successful creation ────────────────────────────────────────────────

    public function test_eligible_appointment_requests_meet_and_becomes_available(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 0);
        $sync = $this->syncFor($appointment);

        app(AppointmentCalendarSyncService::class)->attempt($sync);
        $sync->refresh();

        $this->assertSame(CalendarSyncState::SYNCED, $sync->state);
        $this->assertSame(MeetConferenceState::AVAILABLE, $sync->meeting_state);
        $this->assertNotNull($sync->provider_conference_id);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $sync->meeting_join_url);
        $this->assertFalse($sync->outcome_uncertain);
    }

    public function test_conference_request_id_is_the_stable_correlation_key(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 1);
        $sync = $this->syncFor($appointment);

        app(AppointmentCalendarSyncService::class)->attempt($sync);

        $this->assertSame($sync->correlation_key, $this->fakeCalendarProvider()->lastCreateEventPayload['correlation_key']);
        $this->assertTrue($this->fakeCalendarProvider()->lastRequestConference);
    }

    public function test_send_updates_remains_none_when_requesting_conference(): void
    {
        $this->makeHealthyConnection();
        app(FakeGoogleApiClient::class); // ensure bound
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 2);
        $sync = $this->syncFor($appointment);

        $provider = app(GoogleCalendarProvider::class);
        $payload = app(ConsultancyAppointmentCalendarEventPayloadFactory::class)->build($appointment->fresh(), $sync->correlation_key, true);
        $provider->createEvent($payload);

        $this->assertSame('none', app(FakeGoogleApiClient::class)->lastInsertSendUpdates);
    }

    public function test_real_adapter_uses_correlation_key_as_conference_request_id(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 3);
        $sync = $this->syncFor($appointment);

        $provider = app(GoogleCalendarProvider::class);
        $payload = app(ConsultancyAppointmentCalendarEventPayloadFactory::class)->build($appointment->fresh(), $sync->correlation_key, true);
        $provider->createEvent($payload);

        $fakeClient = app(FakeGoogleApiClient::class);
        $this->assertSame($sync->correlation_key, $fakeClient->lastConferenceRequestId);
    }

    // ── B. Pending conference ────────────────────────────────────────────────

    public function test_calendar_synced_while_meet_pending_no_link_shown_yet(): void
    {
        $this->makeHealthyConnection();
        $this->fakeCalendarProvider()->conferenceStatus = 'pending';
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 4);
        $sync = $this->syncFor($appointment);

        app(AppointmentCalendarSyncService::class)->attempt($sync);
        $sync->refresh();

        $this->assertSame(CalendarSyncState::SYNCED, $sync->state);
        $this->assertSame(MeetConferenceState::PENDING, $sync->meeting_state);
        $this->assertNull($sync->meeting_join_url);
        $this->assertFalse($sync->isMeetingJoinable());
    }

    public function test_reconciliation_later_adopts_the_link_no_second_calendar_event(): void
    {
        $this->makeHealthyConnection();
        $fake = $this->fakeCalendarProvider();
        $fake->conferenceStatus = 'pending';
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 5);
        $sync = $this->syncFor($appointment);
        app(AppointmentCalendarSyncService::class)->attempt($sync);
        $sync->refresh();
        $this->assertSame(MeetConferenceState::PENDING, $sync->meeting_state);

        // Google's conference resolves between ticks — findEventByCorrelationKey()
        // (what refreshPendingMeet() calls) must now report the SAME event, now available.
        $fake->correlationLookupResults[$sync->correlation_key] = [[
            'event_id' => $sync->provider_event_id,
            'conference' => ['status' => 'success', 'conference_id' => $fake->conferenceId, 'conference_type' => 'hangoutsMeet', 'join_url' => $fake->conferenceJoinUrl],
        ]];
        app(AppointmentCalendarSyncService::class)->refreshPendingMeet($sync);
        $sync->refresh();

        $this->assertSame(MeetConferenceState::AVAILABLE, $sync->meeting_state);
        $this->assertSame(1, $fake->createEventCallCount, 'refreshPendingMeet() must never create a second Calendar event.');
    }

    public function test_activity_log_recovery_recorded_once_not_on_every_pass(): void
    {
        $this->makeHealthyConnection();
        $fake = $this->fakeCalendarProvider();
        $fake->conferenceStatus = 'pending';
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 6);
        $sync = $this->syncFor($appointment);
        app(AppointmentCalendarSyncService::class)->attempt($sync);

        $fake->correlationLookupResults[$sync->fresh()->correlation_key] = [[
            'event_id' => $sync->fresh()->provider_event_id,
            'conference' => ['status' => 'success', 'conference_id' => $fake->conferenceId, 'conference_type' => 'hangoutsMeet', 'join_url' => $fake->conferenceJoinUrl],
        ]];
        app(AppointmentCalendarSyncService::class)->refreshPendingMeet($sync->fresh());
        // A second, unchanged pass must not log again.
        app(AppointmentCalendarSyncService::class)->refreshPendingMeet($sync->fresh());

        $this->assertSame(1, ActivityLog::where('action', 'google.meet_available')->count());
    }

    // ── C. Lost response / uncertainty ────────────────────────────────────────

    public function test_lost_response_reconciles_and_adopts_meet_without_duplicate_conference(): void
    {
        $this->makeHealthyConnection();
        $fake = $this->fakeCalendarProvider();
        $fake->createEventFailureCategory = CalendarSyncFailureCategory::TRANSPORT_UNCERTAIN;
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 0);
        $sync = $this->syncFor($appointment);

        app(AppointmentCalendarSyncService::class)->attempt($sync);
        $sync->refresh();
        $this->assertTrue($sync->outcome_uncertain);
        $this->assertSame(CalendarSyncState::RETRY_PENDING, $sync->state);

        // Google actually created the event+conference despite the lost response.
        $fake->createEventFailureCategory = null;
        $fake->correlationLookupResults[$sync->correlation_key] = [[
            'event_id' => 'the_real_event_google_created',
            'conference' => ['status' => 'success', 'conference_id' => 'conf_recovered', 'conference_type' => 'hangoutsMeet', 'join_url' => 'https://meet.google.com/rec-over-edxx'],
        ]];
        $sync->update(['state' => CalendarSyncState::RETRY_PENDING, 'next_retry_at' => now()->subMinute()]);
        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());
        $sync->refresh();

        $this->assertSame(CalendarSyncState::SYNCED, $sync->state);
        $this->assertSame(MeetConferenceState::AVAILABLE, $sync->meeting_state);
        $this->assertSame(1, $fake->createEventCallCount, 'Reconciliation found the event — createEvent() must never be called a second time (count reflects only the original failed attempt).');
    }

    // ── D. 5xx / transport ────────────────────────────────────────────────────

    public function test_provider_5xx_treated_as_uncertain_reconciles_before_retry(): void
    {
        $this->makeHealthyConnection();
        $fake = $this->fakeCalendarProvider();
        $fake->createEventFailureCategory = CalendarSyncFailureCategory::PROVIDER_SERVER_ERROR;
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 1);
        $sync = $this->syncFor($appointment);

        app(AppointmentCalendarSyncService::class)->attempt($sync);
        $sync->refresh();
        $this->assertTrue($sync->outcome_uncertain);

        $fake->createEventFailureCategory = null;
        $sync->update(['next_retry_at' => now()->subMinute()]);
        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());

        $this->assertSame(1, $fake->findEventCallCount, 'Correlation lookup must happen before any second create attempt.');
        $this->assertSame(2, $fake->createEventCallCount, 'First attempt failed (1 call), second reconciled to zero matches then created (2nd call) — never more.');
    }

    public function test_timeout_treated_as_uncertain(): void
    {
        $this->makeHealthyConnection();
        $this->fakeCalendarProvider()->createEventFailureCategory = CalendarSyncFailureCategory::TRANSPORT_UNCERTAIN;
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 2);
        $sync = $this->syncFor($appointment);

        app(AppointmentCalendarSyncService::class)->attempt($sync);

        $this->assertTrue($sync->fresh()->outcome_uncertain);
        $this->assertSame(MeetConferenceState::NOT_REQUESTED, $sync->fresh()->meeting_state);
    }

    // ── E. Definitive failures ───────────────────────────────────────────────

    public function test_missing_meet_capability_classified_as_meet_not_supported(): void
    {
        $this->makeHealthyConnection();
        $this->fakeCalendarProvider()->conferenceStatus = null; // Google returns no conferenceData at all
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 3);
        $sync = $this->syncFor($appointment);

        app(AppointmentCalendarSyncService::class)->attempt($sync);
        $sync->refresh();

        $this->assertSame(CalendarSyncState::SYNCED, $sync->state, 'Calendar must not be marked failed merely because Meet is unavailable.');
        $this->assertSame(MeetConferenceState::UNAVAILABLE, $sync->meeting_state);
        $this->assertSame(CalendarSyncFailureCategory::MEET_NOT_SUPPORTED, $sync->meeting_failure_category);
    }

    public function test_conference_solution_failure_classified_and_calendar_stays_synced(): void
    {
        $this->makeHealthyConnection();
        $this->fakeCalendarProvider()->conferenceStatus = 'failure';
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 4);
        $sync = $this->syncFor($appointment);

        app(AppointmentCalendarSyncService::class)->attempt($sync);
        $sync->refresh();

        $this->assertSame(CalendarSyncState::SYNCED, $sync->state);
        $this->assertSame(MeetConferenceState::FAILED, $sync->meeting_state);
        $this->assertSame(CalendarSyncFailureCategory::CONFERENCE_SOLUTION_UNAVAILABLE, $sync->meeting_failure_category);
    }

    public function test_malformed_success_response_moves_to_manual_review_never_available(): void
    {
        $this->makeHealthyConnection();
        $fake = $this->fakeCalendarProvider();
        $fake->conferenceStatus = 'success';
        $fake->conferenceMalformed = true;
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 5);
        $sync = $this->syncFor($appointment);

        app(AppointmentCalendarSyncService::class)->attempt($sync);
        $sync->refresh();

        $this->assertSame(MeetConferenceState::MANUAL_REVIEW, $sync->meeting_state);
        $this->assertNull($sync->meeting_join_url);
        $this->assertFalse($sync->isMeetingJoinable());
    }

    public function test_permission_failure_persisted_without_consuming_queue_retry(): void
    {
        $this->makeHealthyConnection();
        $this->fakeCalendarProvider()->createEventFailureCategory = CalendarSyncFailureCategory::PERMISSIONS_MISSING;
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 6);
        $sync = $this->syncFor($appointment);

        // A classified failure must complete normally — no exception.
        $result = app(AppointmentCalendarSyncService::class)->attempt($sync);

        $this->assertSame(CalendarSyncState::MANUAL_REVIEW, $result['state']);
    }

    public function test_raw_exception_details_never_exposed_via_admin_endpoint(): void
    {
        $this->makeHealthyConnection();
        $this->fakeCalendarProvider()->createEventFailureCategory = CalendarSyncFailureCategory::RATE_LIMITED;
        [, $admin] = $this->makeOrgAndUser('Admin');
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 0);
        $sync = $this->syncFor($appointment);
        app(AppointmentCalendarSyncService::class)->attempt($sync);

        $response = $this->actingAs($admin)->getJson("/api/admin/google/calendar-syncs/{$sync->id}");

        $response->assertStatus(200);
        $this->assertStringNotContainsString('Exception', $response->getContent());
        $this->assertStringNotContainsString('RuntimeException', $response->getContent());
    }

    // ── F. Reconciliation ────────────────────────────────────────────────────

    public function test_one_correlation_match_with_available_meet_is_adopted(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 1);
        $sync = $this->syncFor($appointment);
        $sync->update(['outcome_uncertain' => true]);

        $this->fakeCalendarProvider()->correlationLookupResults[$sync->correlation_key] = [[
            'event_id' => 'existing_evt',
            'conference' => ['status' => 'success', 'conference_id' => 'conf_x', 'conference_type' => 'hangoutsMeet', 'join_url' => 'https://meet.google.com/xyz-abcd-efg'],
        ]];

        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());
        $sync->refresh();

        $this->assertSame(CalendarSyncState::SYNCED, $sync->state);
        $this->assertSame(MeetConferenceState::AVAILABLE, $sync->meeting_state);
        $this->assertSame('https://meet.google.com/xyz-abcd-efg', $sync->meeting_join_url);
    }

    public function test_one_correlation_match_without_meet_is_adopted_as_unavailable(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 2);
        $sync = $this->syncFor($appointment);
        $sync->update(['outcome_uncertain' => true]);

        $this->fakeCalendarProvider()->correlationLookupResults[$sync->correlation_key] = [[
            'event_id' => 'existing_evt_no_meet',
            'conference' => ['status' => null, 'conference_id' => null, 'conference_type' => null, 'join_url' => null],
        ]];

        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());
        $sync->refresh();

        $this->assertSame(CalendarSyncState::SYNCED, $sync->state);
        $this->assertSame(MeetConferenceState::UNAVAILABLE, $sync->meeting_state);
    }

    public function test_more_than_one_correlation_match_moves_to_manual_review(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 3);
        $sync = $this->syncFor($appointment);
        $sync->update(['outcome_uncertain' => true]);

        $this->fakeCalendarProvider()->correlationLookupResults[$sync->correlation_key] = [
            ['event_id' => 'dup_1', 'conference' => ['status' => 'success', 'conference_id' => 'c1', 'conference_type' => 'hangoutsMeet', 'join_url' => 'https://meet.google.com/aaa-bbbb-ccc']],
            ['event_id' => 'dup_2', 'conference' => ['status' => 'success', 'conference_id' => 'c2', 'conference_type' => 'hangoutsMeet', 'join_url' => 'https://meet.google.com/ddd-eeee-fff']],
        ];

        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());
        $sync->refresh();

        $this->assertSame(CalendarSyncState::MANUAL_REVIEW, $sync->state);
        $this->assertNotSame(MeetConferenceState::AVAILABLE, $sync->meeting_state, 'Never create/adopt from an ambiguous match set.');
    }

    // ── G. Cancellation ──────────────────────────────────────────────────────

    public function test_cancelled_before_creation_gets_no_calendar_event_or_meet(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 4);
        $appointment->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $sync = $this->syncFor($appointment);

        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());
        $sync->refresh();

        $this->assertSame(CalendarSyncState::CANCELLED, $sync->state);
        $this->assertSame(MeetConferenceState::NOT_REQUESTED, $sync->meeting_state);
        $this->assertSame(0, $this->fakeCalendarProvider()->createEventCallCount);
    }

    public function test_cancellation_after_successful_sync_does_not_mutate_external_event(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 5);
        $sync = $this->syncFor($appointment);
        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());
        $this->assertSame(MeetConferenceState::AVAILABLE, $sync->fresh()->meeting_state);

        $appointment->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());

        $sync->refresh();
        $this->assertSame(CalendarSyncState::SYNCED, $sync->state, 'Approved correction 5 — synced state is never rewritten after later cancellation.');
        $this->assertSame(MeetConferenceState::AVAILABLE, $sync->meeting_state);
    }

    public function test_cancellation_and_meet_availability_remain_independent_fields(): void
    {
        $this->makeHealthyConnection();
        [, $admin] = $this->makeOrgAndUser('Admin');
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 6);
        $sync = $this->syncFor($appointment);
        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());
        $appointment->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $response = $this->actingAs($admin)->getJson("/api/admin/google/calendar-syncs/{$sync->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('appointment_cancelled', true);
        $response->assertJsonPath('meeting_state', MeetConferenceState::AVAILABLE);
    }

    // ── H. Security / customer link exposure ─────────────────────────────────

    public function test_client_cannot_read_admin_calendar_sync_endpoints(): void
    {
        [, $client] = $this->makeOrgAndUser('Client');

        $response = $this->actingAs($client)->getJson('/api/admin/google/calendar-syncs');

        $response->assertStatus(403);
    }

    public function test_authorised_client_sees_available_link_on_own_consultation(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        [$org, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService(['available_to_existing_customers' => true]);

        $this->configureConsultant($staff);
        $this->grantAvailability($staff, 0);
        $date = $this->nextDateForWeekday(0);
        $starts = TimezoneResolver::buildLocalInstant($date, '10:00', 'Europe/London');
        $reservation = app(ConsultancySlotReservationService::class)->reserve(
            $service, $starts, $starts->copy()->addMinutes($service->appointmentType->duration_minutes),
            ['name' => 'Client User', 'email' => $client->email, 'timezone' => 'Europe/London'],
            Str::random(40), $client->organization_id, $client->id,
        );
        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');
        $this->fake->markOneOffCheckoutSessionPaid($payment->stripe_checkout_session_id, 'pi_client');
        $sessionPayload = $this->fake->checkoutSessions[$payment->stripe_checkout_session_id];
        $event = \App\Models\BillingWebhookEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_client_' . random_int(1, 999999999),
            'event_type' => 'checkout.session.completed', 'livemode' => false,
            'provider_created_at' => CarbonImmutable::now(), 'processing_status' => WebhookProcessingStatus::RECEIVED,
            'received_at' => CarbonImmutable::now(), 'payload_json' => ['data' => ['object' => $sessionPayload]],
            'payload_hash' => hash('sha256', json_encode($sessionPayload)),
        ]);
        app(ConsultancyWebhookEventProcessor::class)->process($event);
        $appointment = Appointment::where('id', $payment->fresh()->appointment_id)->firstOrFail();
        $sync = $this->syncFor($appointment);
        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());

        $response = $this->actingAs($client)->getJson("/api/consultations/{$appointment->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('meeting.status', 'available');
        $response->assertJsonPath('meeting.join_url', 'https://meet.google.com/abc-defg-hij');
    }

    public function test_client_cannot_access_another_organisations_consultation_meeting(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 1);
        $sync = $this->syncFor($appointment);
        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());

        [, $otherOrgClient] = $this->makeOrgAndUser('Client');

        $response = $this->actingAs($otherOrgClient)->getJson("/api/consultations/{$appointment->id}");

        $response->assertStatus(403);
    }

    public function test_pending_meet_never_exposes_a_url_to_the_customer(): void
    {
        $this->makeHealthyConnection();
        $this->fakeCalendarProvider()->conferenceStatus = 'pending';
        [, $staff] = $this->makeOrgAndUser('Admin');
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService(['available_to_existing_customers' => true]);
        $this->configureConsultant($staff);
        $this->grantAvailability($staff, 2);
        $date = $this->nextDateForWeekday(2);
        $starts = TimezoneResolver::buildLocalInstant($date, '10:00', 'Europe/London');
        $reservation = app(ConsultancySlotReservationService::class)->reserve(
            $service, $starts, $starts->copy()->addMinutes($service->appointmentType->duration_minutes),
            ['name' => 'Client User', 'email' => $client->email, 'timezone' => 'Europe/London'],
            Str::random(40), $client->organization_id, $client->id,
        );
        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');
        $this->fake->markOneOffCheckoutSessionPaid($payment->stripe_checkout_session_id, 'pi_pending');
        $sessionPayload = $this->fake->checkoutSessions[$payment->stripe_checkout_session_id];
        $event = \App\Models\BillingWebhookEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_pending_' . random_int(1, 999999999),
            'event_type' => 'checkout.session.completed', 'livemode' => false,
            'provider_created_at' => CarbonImmutable::now(), 'processing_status' => WebhookProcessingStatus::RECEIVED,
            'received_at' => CarbonImmutable::now(), 'payload_json' => ['data' => ['object' => $sessionPayload]],
            'payload_hash' => hash('sha256', json_encode($sessionPayload)),
        ]);
        app(ConsultancyWebhookEventProcessor::class)->process($event);
        $appointment = Appointment::where('id', $payment->fresh()->appointment_id)->firstOrFail();
        $sync = $this->syncFor($appointment);
        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());

        $response = $this->actingAs($client)->getJson("/api/consultations/{$appointment->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('meeting.status', 'pending');
        $response->assertJsonPath('meeting.join_url', null);
    }

    public function test_consultation_list_endpoint_never_includes_meeting_field(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService(['available_to_existing_customers' => true]);
        $this->configureConsultant($staff);
        $this->grantAvailability($staff, 3);
        $date = $this->nextDateForWeekday(3);
        $starts = TimezoneResolver::buildLocalInstant($date, '10:00', 'Europe/London');
        app(ConsultancySlotReservationService::class)->reserve(
            $service, $starts, $starts->copy()->addMinutes($service->appointmentType->duration_minutes),
            ['name' => 'Client User', 'email' => $client->email, 'timezone' => 'Europe/London'],
            Str::random(40), $client->organization_id, $client->id,
        );

        $response = $this->actingAs($client)->getJson('/api/consultations');

        $response->assertStatus(200);
        $this->assertStringNotContainsString('"meeting"', $response->getContent());
    }

    public function test_provider_event_and_correlation_key_never_reach_customer_response(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService(['available_to_existing_customers' => true]);
        $this->configureConsultant($staff);
        $this->grantAvailability($staff, 4);
        $date = $this->nextDateForWeekday(4);
        $starts = TimezoneResolver::buildLocalInstant($date, '10:00', 'Europe/London');
        $reservation = app(ConsultancySlotReservationService::class)->reserve(
            $service, $starts, $starts->copy()->addMinutes($service->appointmentType->duration_minutes),
            ['name' => 'Client User', 'email' => $client->email, 'timezone' => 'Europe/London'],
            Str::random(40), $client->organization_id, $client->id,
        );
        $payment = app(ConsultancyCheckoutService::class)->createCheckoutSession($reservation, 'https://x.test/s', 'https://x.test/c');
        $this->fake->markOneOffCheckoutSessionPaid($payment->stripe_checkout_session_id, 'pi_ids');
        $sessionPayload = $this->fake->checkoutSessions[$payment->stripe_checkout_session_id];
        $event = \App\Models\BillingWebhookEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_ids_' . random_int(1, 999999999),
            'event_type' => 'checkout.session.completed', 'livemode' => false,
            'provider_created_at' => CarbonImmutable::now(), 'processing_status' => WebhookProcessingStatus::RECEIVED,
            'received_at' => CarbonImmutable::now(), 'payload_json' => ['data' => ['object' => $sessionPayload]],
            'payload_hash' => hash('sha256', json_encode($sessionPayload)),
        ]);
        app(ConsultancyWebhookEventProcessor::class)->process($event);
        $appointment = Appointment::where('id', $payment->fresh()->appointment_id)->firstOrFail();
        $sync = $this->syncFor($appointment);
        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());

        $response = $this->actingAs($client)->getJson("/api/consultations/{$appointment->id}");

        $body = $response->getContent();
        $this->assertStringNotContainsString($sync->fresh()->provider_event_id, $body);
        $this->assertStringNotContainsString($sync->correlation_key, $body);
    }

    // ── I. Retry ownership / attempt counting ────────────────────────────────

    public function test_attempt_count_increments_once_per_real_provider_operation(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 5);
        $sync = $this->syncFor($appointment);

        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());

        $this->assertSame(1, $sync->fresh()->attempt_count);
    }

    public function test_meet_only_refresh_never_increments_attempt_count(): void
    {
        $this->makeHealthyConnection();
        $fake = $this->fakeCalendarProvider();
        $fake->conferenceStatus = 'pending';
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 6);
        $sync = $this->syncFor($appointment);
        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());
        $attemptCountAfterCreate = $sync->fresh()->attempt_count;

        $fake->conferenceStatus = 'success';
        app(AppointmentCalendarSyncService::class)->refreshPendingMeet($sync->fresh());

        $this->assertSame($attemptCountAfterCreate, $sync->fresh()->attempt_count, 'A Meet-only recheck must never count as a provider-operation attempt.');
    }

    public function test_ineligible_cancelled_run_does_not_increment_attempt_count(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 0);
        $appointment->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $sync = $this->syncFor($appointment);

        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());

        $this->assertSame(0, $sync->fresh()->attempt_count);
    }

    public function test_dry_run_reconciliation_does_not_increment_attempt_count(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 1);
        $sync = $this->syncFor($appointment);
        $sync->update(['state' => CalendarSyncState::RETRY_PENDING, 'next_retry_at' => now()->subMinute()]);

        $this->artisan('appointments:calendar-sync:reconcile', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, $sync->fresh()->attempt_count);
    }

    public function test_unclassified_meet_failure_propagates_for_queue_retry(): void
    {
        $this->makeHealthyConnection();
        $this->fakeCalendarProvider()->throwUnclassifiedException = true;
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 2);
        $sync = $this->syncFor($appointment);

        $this->expectException(\RuntimeException::class);
        app(AppointmentCalendarSyncService::class)->attempt($sync);
    }

    // ── J. Readiness ─────────────────────────────────────────────────────────

    public function test_calendar_and_meet_ready_yields_overall_ready(): void
    {
        $this->makeHealthyConnection();

        $result = app(GoogleIntegrationReadinessService::class)->checkDetailed();

        $this->assertTrue($result['calendar_ready']);
        $this->assertTrue($result['meet_ready']);
        $this->assertTrue($result['google_overall_ready']);
        $this->assertEmpty($result['blockers']);
    }

    public function test_persistent_meet_blocker_makes_meet_ready_false_while_calendar_ready_true(): void
    {
        $connection = $this->makeHealthyConnection();
        // A prior appointment recorded a persistent Meet capability blocker.
        [, $staff] = $this->makeOrgAndUser('Admin');
        $appointment = $this->convertARealPayment($staff, $this->makeService(), 3);
        $sync = $this->syncFor($appointment);
        $this->fakeCalendarProvider()->conferenceStatus = null;
        app(AppointmentCalendarSyncService::class)->attempt($sync->fresh());
        $this->assertSame(MeetConferenceState::UNAVAILABLE, $sync->fresh()->meeting_state);

        $result = app(GoogleIntegrationReadinessService::class)->checkDetailed();

        $this->assertTrue($result['calendar_ready']);
        $this->assertFalse($result['meet_ready']);
        $this->assertFalse($result['google_overall_ready']);
        $this->assertContains(CalendarSyncFailureCategory::MEET_NOT_SUPPORTED, $result['blockers']);
    }

    public function test_a_later_available_result_clears_the_persisted_blocker(): void
    {
        $this->makeHealthyConnection();
        [, $staff] = $this->makeOrgAndUser('Admin');
        $blockedAppointment = $this->convertARealPayment($staff, $this->makeService(), 4);
        $blockedSync = $this->syncFor($blockedAppointment);
        $fake = $this->fakeCalendarProvider();
        $fake->conferenceStatus = null;
        app(AppointmentCalendarSyncService::class)->attempt($blockedSync->fresh());
        $this->assertFalse(app(GoogleIntegrationReadinessService::class)->checkDetailed()['meet_ready']);

        // A later appointment succeeds — the blocker must clear.
        $fake->conferenceStatus = 'success';
        $laterAppointment = $this->convertARealPayment($staff, $this->makeService(), 5);
        app(AppointmentCalendarSyncService::class)->attempt($this->syncFor($laterAppointment)->fresh());

        $this->assertTrue(app(GoogleIntegrationReadinessService::class)->checkDetailed()['meet_ready']);
    }

    public function test_customer_safe_readiness_is_never_exposed_since_no_customer_endpoint_reads_it(): void
    {
        // Readiness is Admin/operational-only — confirmed by security: the
        // Consultancy checkout endpoints never call GoogleIntegrationReadinessService
        // at all (see ConsultancyBookingReadinessService::checkoutAvailability(),
        // unchanged by this stage).
        $reflection = new \ReflectionClass(ConsultancyBookingReadinessService::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString('GoogleIntegrationReadinessService', $source);
        $this->assertStringNotContainsString('CalendarProviderInterface', $source);
    }

    public function test_admin_readiness_diagnostics_returns_structured_blockers(): void
    {
        // No connection at all.
        $result = app(GoogleIntegrationReadinessService::class)->checkDetailed();

        $this->assertFalse($result['calendar_ready']);
        $this->assertFalse($result['meet_ready']);
        $this->assertNotEmpty($result['blockers']);
    }

    // ── K. Regression ─────────────────────────────────────────────────────────
    // Run separately via the completion report's targeted regression pass.
}
