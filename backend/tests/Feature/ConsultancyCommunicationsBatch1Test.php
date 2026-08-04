<?php

namespace Tests\Feature;

use App\Jobs\SendConsultationCommunicationJob;
use App\Models\Appointment;
use App\Models\AppointmentAvailability;
use App\Models\AppointmentExternalSync;
use App\Models\AppointmentType;
use App\Models\ConsultancyService;
use App\Models\ConsultationCommunicationDelivery;
use App\Models\ConsultationEnquiry;
use App\Models\Organization;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Services\Calendar\AppointmentCalendarSyncService;
use App\Services\Calendar\FakeCalendarProvider;
use App\Services\Consultancy\ConsultationCommunicationService;
use App\Support\Consultancy\ConsultationCommunicationLinks;
use App\Support\Email\EmailComponents;
use App\Support\Google\CalendarSyncState;
use App\Support\Google\MeetConferenceState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Consultancy Communications & Global Email Experience Upgrade, Batch 1 —
 * shared email foundation, booking-confirmation upgrade, meeting-link-ready
 * email, communication delivery/idempotency model, and the action-link
 * resolver. Every automated test here uses Http::fake() for Brevo — no real
 * email is ever sent from this suite.
 */
class ConsultancyCommunicationsBatch1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SuresignSetting::instance()->update([
            'brevo_api_key' => 'fake-brevo-key',
            'email_sender_email' => 'noreply@suresigncontracts.app',
            'support_email' => 'support@suresigncontracts.app',
            'appointment_ics_enabled' => true,
        ]);
    }

    private function fakeBrevo(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'fake-message-id'], 201)]);
    }

    private function makeType(): AppointmentType
    {
        static $n = 0;
        $n++;

        return AppointmentType::create([
            'name' => "Consultancy Type {$n}", 'slug' => "consultancy-type-{$n}",
            'duration_minutes' => 30, 'is_active' => true, 'is_public' => true, 'assignment_mode' => 'manual',
            'meeting_method' => 'tbc', 'requires_confirmation' => false,
            'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'min_notice_hours' => 0, 'max_advance_days' => 60,
        ]);
    }

    private function grantOpenAvailability(User $staff): void
    {
        for ($weekday = 0; $weekday <= 6; $weekday++) {
            AppointmentAvailability::create([
                'user_id' => $staff->id, 'context' => \App\Support\Appointments\AvailabilityContext::CONSULTANCY,
                'weekday' => $weekday,
                'start_time' => '00:00', 'end_time' => '23:59', 'is_active' => true,
            ]);
        }
    }

    private function makeConsultancyService(AppointmentType $type): ConsultancyService
    {
        static $n = 0;
        $n++;

        return ConsultancyService::create([
            'code' => "consultancy-svc-{$n}", 'appointment_type_id' => $type->id,
            'display_name' => "Quick Consultation {$n}", 'public_description' => 'Test service',
            'price_minor_units' => 0, 'currency' => 'GBP', 'enabled' => true,
            'publicly_bookable' => true, 'available_to_existing_customers' => true,
            'display_order' => 1, 'is_introductory' => true,
        ]);
    }

    /**
     * @param  bool  $public  true = no linked_user_id (public/no-account customer)
     */
    private function makeConsultationAppointment(bool $public = true, array $overrides = []): Appointment
    {
        static $n = 0;
        $n++;
        $type = $this->makeType();
        $service = $this->makeConsultancyService($type);

        $consultant = User::factory()->create();
        $consultant->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));

        $linkedUser = null;
        if (!$public) {
            $org = Organization::create(['name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => 'Europe/London']);
            $linkedUser = User::factory()->create(['organization_id' => $org->id]);
            $linkedUser->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        }

        $appointment = Appointment::create(array_merge([
            'reference' => 'APT-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'appointment_type_id' => $type->id,
            'assigned_user_id' => $consultant->id,
            'linked_user_id' => $linkedUser?->id,
            'attendee_name' => 'Test Customer',
            'attendee_email' => 'customer@example.com',
            'attendee_timezone' => 'Europe/London',
            'starts_at' => now()->addDays(3)->setTime(14, 0),
            'ends_at' => now()->addDays(3)->setTime(14, 30),
            'booking_timezone' => 'Europe/London',
            'status' => 'confirmed',
            'booking_source' => $public ? 'public_booking_page' : 'consultancy_in_app',
            'meeting_method' => 'tbc',
        ], $overrides));

        ConsultationEnquiry::create([
            'appointment_id' => $appointment->id,
            'consultancy_service_id' => $service->id,
            'title' => 'Test enquiry', 'description' => 'Test description',
            'submitted_by' => $public ? 'public' : 'authenticated',
            'engagement_status' => 'awaiting_consultant',
        ]);

        return $appointment->fresh();
    }

    private function makeSync(Appointment $appointment, string $state, string $meetingState, ?string $joinUrl = null): AppointmentExternalSync
    {
        return AppointmentExternalSync::create([
            'appointment_id' => $appointment->id,
            'provider' => 'google', 'external_resource_type' => 'calendar_event',
            'state' => $state, 'meeting_state' => $meetingState,
            'provider_event_id' => $state === CalendarSyncState::SYNCED ? 'evt_' . $appointment->id : null,
            'meeting_join_url' => $joinUrl,
            'correlation_key' => 'corr_' . $appointment->id,
            'payload_version' => 'v1',
        ]);
    }

    // ── Shared email foundation ────────────────────────────────────────────

    public function test_email_components_button_escapes_and_renders_href(): void
    {
        $html = EmailComponents::button('Join <script>', 'https://meet.google.com/abc-defg-hij', 'primary');

        $this->assertStringContainsString('https://meet.google.com/abc-defg-hij', $html);
        $this->assertStringContainsString('Join &lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_email_components_details_table_escapes_values(): void
    {
        $html = EmailComponents::detailsTable(['Name' => '<b>Bad</b>']);

        $this->assertStringNotContainsString('<b>Bad</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;Bad&lt;/b&gt;', $html);
    }

    // ── Booking confirmation ────────────────────────────────────────────────

    public function test_public_booking_confirmation_sends_with_pending_meet_wording_and_no_duplicate(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeConsultationAppointment(public: true);

        $service = app(ConsultationCommunicationService::class);
        $this->assertTrue($service->sendBookingConfirmed($appointment));

        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_contains($body['htmlContent'], 'Your Google Meet link is being prepared')
                && !str_contains($body['htmlContent'], 'meet.google.com')
                && str_contains($body['textContent'], 'Reference:');
        });

        $this->assertSame(1, ConsultationCommunicationDelivery::where('appointment_id', $appointment->id)->count());

        // Duplicate trigger must not resend.
        $this->assertFalse($service->sendBookingConfirmed($appointment));
        $this->assertSame(1, ConsultationCommunicationDelivery::where('appointment_id', $appointment->id)->count());
        Http::assertSentCount(1);
    }

    public function test_confirmation_includes_join_button_when_meet_already_available(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeConsultationAppointment(public: true);
        $this->makeSync($appointment, CalendarSyncState::SYNCED, MeetConferenceState::AVAILABLE, 'https://meet.google.com/abc-defg-hij');

        app(ConsultationCommunicationService::class)->sendBookingConfirmed($appointment->fresh(['externalSync']));

        Http::assertSent(fn ($request) => str_contains($request->data()['htmlContent'], 'https://meet.google.com/abc-defg-hij')
            && str_contains($request->data()['htmlContent'], 'Join Google Meet'));
    }

    public function test_confirmation_ics_attached_and_contains_meet_url_when_available(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeConsultationAppointment(public: true);
        $this->makeSync($appointment, CalendarSyncState::SYNCED, MeetConferenceState::AVAILABLE, 'https://meet.google.com/abc-defg-hij');

        app(ConsultationCommunicationService::class)->sendBookingConfirmed($appointment->fresh(['externalSync']));

        Http::assertSent(function ($request) {
            $attachment = $request->data()['attachment'][0] ?? null;
            if (!$attachment) {
                return false;
            }
            $ics = base64_decode($attachment['content']);
            return str_contains($ics, 'meet.google.com/abc-defg-hij') && str_contains($ics, 'BEGIN:VCALENDAR');
        });
    }

    public function test_authenticated_customer_confirmation_uses_in_app_manage_link(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeConsultationAppointment(public: false);

        app(ConsultationCommunicationService::class)->sendBookingConfirmed($appointment);

        // The primary "Manage Consultation" destination is the in-app route
        // for an authenticated customer — the tertiary reschedule/cancel
        // actions still use the existing signed marketing links regardless
        // of authentication (no in-app reschedule/cancel action exists yet
        // for Consultancy — see ConsultationCommunicationLinks's own
        // docblock), so '/appointments/' legitimately still appears for
        // those, and is not itself a bug.
        Http::assertSent(fn ($request) => str_contains($request->data()['textContent'], "Manage Consultation: " . rtrim(config('suresign.frontend_url'), '/') . "/app/consultations/{$appointment->id}"));
    }

    public function test_public_customer_confirmation_uses_signed_marketing_link_not_in_app(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeConsultationAppointment(public: true);

        app(ConsultationCommunicationService::class)->sendBookingConfirmed($appointment);

        Http::assertSent(fn ($request) => str_contains($request->data()['textContent'], 'suresigncontracts.app/appointments/')
            && !str_contains($request->data()['textContent'], '/app/consultations/'));
    }

    public function test_booking_confirmation_dispatches_from_public_store_endpoint(): void
    {
        Bus::fake();
        $type = $this->makeType();
        $service = $this->makeConsultancyService($type);
        $consultant = User::factory()->create();
        $consultant->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        $this->grantOpenAvailability($consultant);
        SuresignSetting::instance()->update(['consultancy_consultant_user_id' => $consultant->id]);

        $response = $this->postJson("/api/public/consultancy-services/{$service->code}/book", [
            'attendee_name' => 'Jane Doe', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London',
            'date' => now()->addDays(3)->toDateString(), 'start_time' => '10:00', 'timezone' => 'Europe/London',
            'title' => 'Enquiry title', 'description' => 'Enquiry description',
            'consent' => true,
        ]);

        $response->assertStatus(201);
        Bus::assertDispatched(SendConsultationCommunicationJob::class, fn ($job) => $job->kind === 'booking_confirmed');
        Bus::assertNotDispatched(\App\Jobs\SendAppointmentEmailJob::class);
    }

    // ── Meeting-link-ready ───────────────────────────────────────────────────

    public function test_meeting_link_ready_dispatches_once_on_genuine_availability_transition(): void
    {
        Bus::fake();
        $appointment = $this->makeConsultationAppointment(public: true);
        $sync = $this->makeSync($appointment, CalendarSyncState::PROCESSING, MeetConferenceState::PENDING);

        $syncService = $this->syncServiceWithFakeProvider($appointment, joinUrl: 'https://meet.google.com/abc-defg-hij');
        $reflection = new \ReflectionClass($syncService);
        $method = $reflection->getMethod('applyConferenceResult');
        $method->setAccessible(true);
        $method->invoke($syncService, $sync, ['status' => 'success', 'conference_id' => 'c1', 'conference_type' => 'hangoutsMeet', 'join_url' => 'https://meet.google.com/abc-defg-hij']);

        Bus::assertDispatched(SendConsultationCommunicationJob::class, fn ($job) => $job->kind === 'meeting_link_ready' && $job->appointmentId === $appointment->id);
    }

    public function test_meeting_link_ready_does_not_redispatch_on_unchanged_available_state(): void
    {
        Bus::fake();
        $appointment = $this->makeConsultationAppointment(public: true);
        $sync = $this->makeSync($appointment, CalendarSyncState::SYNCED, MeetConferenceState::AVAILABLE, 'https://meet.google.com/abc-defg-hij');

        $syncService = $this->syncServiceWithFakeProvider($appointment);
        $reflection = new \ReflectionClass($syncService);
        $method = $reflection->getMethod('applyConferenceResult');
        $method->setAccessible(true);
        // Reconciliation re-observes the SAME already-available result.
        $method->invoke($syncService, $sync, ['status' => 'success', 'conference_id' => 'c1', 'conference_type' => 'hangoutsMeet', 'join_url' => 'https://meet.google.com/abc-defg-hij']);

        Bus::assertNotDispatched(SendConsultationCommunicationJob::class);
    }

    public function test_meeting_link_ready_email_sends_once_via_job_and_is_idempotent_on_retry(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeConsultationAppointment(public: true);
        $this->makeSync($appointment, CalendarSyncState::SYNCED, MeetConferenceState::AVAILABLE, 'https://meet.google.com/abc-defg-hij');

        $service = app(ConsultationCommunicationService::class);
        $this->assertTrue($service->sendMeetingLinkReady($appointment->fresh(['externalSync'])));
        $this->assertFalse($service->sendMeetingLinkReady($appointment->fresh(['externalSync'])));

        Http::assertSentCount(1);
        $this->assertSame(1, ConsultationCommunicationDelivery::where('appointment_id', $appointment->id)
            ->where('communication_type', 'meeting_link_ready')->count());
    }

    private function syncServiceWithFakeProvider(Appointment $appointment, ?string $joinUrl = null): AppointmentCalendarSyncService
    {
        return app(AppointmentCalendarSyncService::class);
    }

    // ── Action-link resolver ────────────────────────────────────────────────

    public function test_resolver_join_meet_url_only_returned_when_joinable(): void
    {
        $appointment = $this->makeConsultationAppointment(public: true);
        $resolver = app(ConsultationCommunicationLinks::class);

        $this->assertNull($resolver->joinMeetUrl($appointment->fresh(['externalSync'])));

        $this->makeSync($appointment, CalendarSyncState::SYNCED, MeetConferenceState::PENDING);
        $this->assertNull($resolver->joinMeetUrl($appointment->fresh(['externalSync'])));
    }

    public function test_resolver_manage_url_authenticated_vs_public(): void
    {
        $resolver = app(ConsultationCommunicationLinks::class);

        $authenticated = $this->makeConsultationAppointment(public: false);
        $this->assertStringContainsString('/app/consultations/', $resolver->manageUrl($authenticated));

        $public = $this->makeConsultationAppointment(public: true);
        $this->assertStringContainsString('suresigncontracts.app/appointments/', $resolver->manageUrl($public));
    }

    // ── Regression: existing Appointment/Book-a-Demo email flow unaffected ──

    public function test_non_consultancy_appointment_still_uses_generic_appointment_email_job(): void
    {
        Bus::fake();
        $type = $this->makeType();

        $response = $this->postJson("/api/public/appointment-types/{$type->slug}/book", [
            'appointment_type_slug' => $type->slug,
            'attendee_name' => 'Jane Doe', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London',
            'date' => now()->addDays(3)->toDateString(), 'start_time' => '10:00', 'timezone' => 'Europe/London',
            'consent' => true,
        ]);

        $response->assertStatus(201);
        Bus::assertDispatched(\App\Jobs\SendAppointmentEmailJob::class, fn ($job) => $job->kind === 'created');
        Bus::assertNotDispatched(SendConsultationCommunicationJob::class);
    }
}
