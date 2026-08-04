<?php

namespace Tests\Feature;

use App\Jobs\SendConsultationCommunicationJob;
use App\Models\Appointment;
use App\Models\AppointmentExternalSync;
use App\Models\AppointmentReminderSend;
use App\Models\AppointmentType;
use App\Models\ConsultancyService;
use App\Models\ConsultationCommunicationDelivery;
use App\Models\ConsultationEnquiry;
use App\Models\Organization;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Services\Consultancy\ConsultationCommunicationService;
use App\Support\Consultancy\ConsultationCommunicationLinks;
use App\Support\Google\CalendarSyncState;
use App\Support\Google\MeetConferenceState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Consultancy Communications & Global Email Experience Upgrade, Batch 3 —
 * the public no-account "view your consultation" page (Scope A/B), the
 * follow-up email (Scope C), the published-summary email (Scope D), and
 * the public summary page (Scope E). Every automated test here uses
 * Http::fake() for Brevo — no real email is ever sent from this suite.
 */
class ConsultancyCommunicationsBatch3Test extends TestCase
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

    private function makeConsultationAppointment(bool $public = true, array $overrides = [], ?User $consultant = null): Appointment
    {
        static $n = 0;
        $n++;
        $type = $this->makeType();
        $service = $this->makeConsultancyService($type);

        $consultant ??= User::factory()->create(['name' => 'Jane Consultant']);
        if (!$consultant->hasRole('Admin') && !$consultant->hasRole('Super Admin')) {
            $consultant->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        }

        $linkedUser = null;
        $organizationId = null;
        if (!$public) {
            $org = Organization::create(['name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => 'Europe/London']);
            $linkedUser = User::factory()->create(['organization_id' => $org->id]);
            $linkedUser->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
            $organizationId = $org->id;
        }

        $appointment = Appointment::create(array_merge([
            'reference' => 'APT-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'appointment_type_id' => $type->id,
            'assigned_user_id' => $consultant->id,
            'linked_user_id' => $linkedUser?->id,
            'organization_id' => $organizationId,
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
            'title' => 'Retention clause advice', 'description' => 'Test description',
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

    private function signedViewUrl(Appointment $appointment): string
    {
        $url = \URL::temporarySignedRoute('public.consultations.view', now()->addDay(), ['token' => $appointment->public_token]);
        return parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
    }

    private function signedSummaryUrl(Appointment $appointment): string
    {
        $url = \URL::temporarySignedRoute('public.consultations.summary', now()->addDay(), ['token' => $appointment->public_token]);
        return parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
    }

    private function signedIcsUrl(Appointment $appointment): string
    {
        $url = \URL::temporarySignedRoute('public.consultations.view.ics', now()->addDay(), ['token' => $appointment->public_token]);
        return parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
    }

    // ── Public view page (Scope A/B) ─────────────────────────────────────

    public function test_public_view_returns_no_internal_identifiers(): void
    {
        $appointment = $this->makeConsultationAppointment(public: true);

        $response = $this->getJson($this->signedViewUrl($appointment));

        $response->assertStatus(200)->assertJsonPath('reference', $appointment->reference);
        $body = $response->json();
        $this->assertArrayNotHasKey('id', $body);
        $this->assertArrayNotHasKey('appointment_id', $body);
        $this->assertArrayNotHasKey('public_token', $body);
        $this->assertArrayNotHasKey('attendee_email', $body);
    }

    public function test_public_view_meet_pending_state(): void
    {
        $appointment = $this->makeConsultationAppointment(public: true);
        $this->makeSync($appointment, CalendarSyncState::SYNCED, MeetConferenceState::PENDING);

        $response = $this->getJson($this->signedViewUrl($appointment));

        $response->assertStatus(200)
            ->assertJsonPath('meeting.status', 'pending')
            ->assertJsonPath('meeting.join_url', null);
    }

    public function test_public_view_meet_available_state_never_exposes_provider_ids(): void
    {
        $appointment = $this->makeConsultationAppointment(public: true);
        $this->makeSync($appointment, CalendarSyncState::SYNCED, MeetConferenceState::AVAILABLE, 'https://meet.google.com/abc-defg-hij');

        $response = $this->getJson($this->signedViewUrl($appointment));

        $response->assertStatus(200)
            ->assertJsonPath('meeting.status', 'available')
            ->assertJsonPath('meeting.join_url', 'https://meet.google.com/abc-defg-hij');
        $this->assertStringNotContainsString('provider_conference_id', $response->getContent());
        $this->assertStringNotContainsString('evt_', $response->getContent());
    }

    public function test_public_view_summary_url_only_present_once_published(): void
    {
        $appointment = $this->makeConsultationAppointment(public: true);

        $response = $this->getJson($this->signedViewUrl($appointment));
        $response->assertStatus(200)->assertJsonPath('summary_url', null);

        $appointment->consultationEnquiry->update([
            'customer_summary_published' => 'Here is your summary.',
            'customer_summary_published_at' => now(),
        ]);

        $response = $this->getJson($this->signedViewUrl($appointment));
        $response->assertStatus(200);
        $this->assertNotNull($response->json('summary_url'));
        $this->assertStringContainsString('/consultations/', $response->json('summary_url'));
        $this->assertStringContainsString('/summary', $response->json('summary_url'));
    }

    public function test_public_view_ics_download_available_and_unavailable_when_cancelled(): void
    {
        $appointment = $this->makeConsultationAppointment(public: true);
        $view = $this->getJson($this->signedViewUrl($appointment));
        $this->assertNotNull($view->json('ics_url'));

        $ics = $this->get($this->signedIcsUrl($appointment));
        $ics->assertStatus(200);
        $ics->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics->getContent());

        $cancelled = $this->makeConsultationAppointment(public: true, overrides: ['status' => 'cancelled']);
        $viewCancelled = $this->getJson($this->signedViewUrl($cancelled));
        $this->assertNull($viewCancelled->json('ics_url'));

        $icsCancelled = $this->get($this->signedIcsUrl($cancelled));
        $icsCancelled->assertStatus(404);
    }

    public function test_public_view_rejects_invalid_and_expired_signatures(): void
    {
        $appointment = $this->makeConsultationAppointment(public: true);

        // Unsigned request entirely.
        $this->getJson("/api/public/consultations/{$appointment->public_token}/view")->assertStatus(403);

        // A signature that has already expired.
        $expiredUrl = \URL::temporarySignedRoute('public.consultations.view', now()->subMinute(), ['token' => $appointment->public_token]);
        $path = parse_url($expiredUrl, PHP_URL_PATH) . '?' . parse_url($expiredUrl, PHP_URL_QUERY);
        $this->getJson($path)->assertStatus(403);
    }

    public function test_public_view_404s_for_unknown_token_and_non_consultancy_appointment(): void
    {
        $unknownUrl = \URL::temporarySignedRoute('public.consultations.view', now()->addDay(), ['token' => 'does-not-exist']);
        $path = parse_url($unknownUrl, PHP_URL_PATH) . '?' . parse_url($unknownUrl, PHP_URL_QUERY);
        $this->getJson($path)->assertStatus(404);

        // A real Appointment public_token that belongs to a NON-Consultancy
        // booking must 404 here too — this controller has nothing valid to
        // show, and confirming "real token, wrong kind" would itself be a
        // minor information leak.
        $type = $this->makeType();
        $staff = User::factory()->create();
        $staff->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        $plain = Appointment::create([
            'reference' => 'APT-999999', 'appointment_type_id' => $type->id, 'assigned_user_id' => $staff->id,
            'attendee_name' => 'Jane Doe', 'attendee_email' => 'jane@example.com', 'attendee_timezone' => 'Europe/London',
            'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addMinutes(30),
            'booking_timezone' => 'Europe/London', 'status' => 'confirmed',
            'booking_source' => 'public_booking_page', 'meeting_method' => 'tbc',
        ]);
        $this->getJson($this->signedViewUrl($plain))->assertStatus(404);
    }

    public function test_manage_url_falls_back_to_view_page_when_not_reschedulable_or_cancellable(): void
    {
        $appointment = $this->makeConsultationAppointment(public: true, overrides: ['status' => 'completed']);

        $url = app(ConsultationCommunicationLinks::class)->manageUrl($appointment);

        $this->assertNotNull($url);
        $this->assertStringContainsString('/consultations/', $url);
        $this->assertStringNotContainsString('/appointments/', $url);
    }

    // ── Public summary page (Scope E) ────────────────────────────────────

    public function test_public_summary_404s_when_not_yet_published(): void
    {
        $appointment = $this->makeConsultationAppointment(public: true);

        $this->getJson($this->signedSummaryUrl($appointment))->assertStatus(404);
    }

    public function test_public_summary_returns_content_and_no_internal_identifiers_when_published(): void
    {
        $appointment = $this->makeConsultationAppointment(public: true);
        $appointment->consultationEnquiry->update([
            'customer_summary_published' => 'Detailed advice on your retention clause.',
            'customer_summary_published_at' => now(),
        ]);

        $response = $this->getJson($this->signedSummaryUrl($appointment));

        $response->assertStatus(200)
            ->assertJsonPath('summary', 'Detailed advice on your retention clause.')
            ->assertJsonPath('title', 'Retention clause advice')
            ->assertJsonPath('assigned_consultant.name', 'Jane Consultant');
        $body = $response->json();
        $this->assertArrayNotHasKey('id', $body);
        $this->assertArrayNotHasKey('appointment_id', $body);
        $this->assertArrayNotHasKey('public_token', $body);
    }

    public function test_public_summary_rejects_expired_signature(): void
    {
        $appointment = $this->makeConsultationAppointment(public: true);
        $appointment->consultationEnquiry->update(['customer_summary_published' => 'X', 'customer_summary_published_at' => now()]);

        $expiredUrl = \URL::temporarySignedRoute('public.consultations.summary', now()->subMinute(), ['token' => $appointment->public_token]);
        $path = parse_url($expiredUrl, PHP_URL_PATH) . '?' . parse_url($expiredUrl, PHP_URL_QUERY);
        $this->getJson($path)->assertStatus(403);
    }

    // ── Follow-up email (Scope C) ────────────────────────────────────────

    public function test_follow_up_sends_thank_you_with_no_summary_content_and_no_duplicate(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeConsultationAppointment(public: true);

        $service = app(ConsultationCommunicationService::class);
        $this->assertTrue($service->sendConsultationFollowUp($appointment));

        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_contains($body['htmlContent'], 'Thank you for meeting with us')
                && str_contains($body['htmlContent'], "preparing a written summary")
                && !str_contains($body['htmlContent'], 'View Consultation Summary');
        });

        $this->assertSame(1, ConsultationCommunicationDelivery::where('appointment_id', $appointment->id)
            ->where('communication_type', 'consultation_followup')->count());

        $this->assertFalse($service->sendConsultationFollowUp($appointment));
        Http::assertSentCount(1);
    }

    public function test_marking_consultancy_appointment_completed_dispatches_follow_up_only_once(): void
    {
        Bus::fake();
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($superAdmin);

        $appointment = $this->makeConsultationAppointment(public: true);

        $response = $this->postJson("/api/appointments/{$appointment->id}/complete", []);

        $response->assertStatus(200);
        Bus::assertDispatched(SendConsultationCommunicationJob::class, fn ($job) => $job->kind === 'consultation_followup' && $job->appointmentId === $appointment->id);
        Bus::assertDispatchedTimes(SendConsultationCommunicationJob::class, 1);
    }

    public function test_marking_non_consultancy_appointment_completed_sends_nothing(): void
    {
        Bus::fake();
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($superAdmin);

        $type = $this->makeType();
        $staff = User::factory()->create();
        $appointment = Appointment::create([
            'reference' => 'APT-888888', 'appointment_type_id' => $type->id, 'assigned_user_id' => $staff->id,
            'attendee_name' => 'Jane Doe', 'attendee_email' => 'jane@example.com', 'attendee_timezone' => 'Europe/London',
            'starts_at' => now()->subHour(), 'ends_at' => now(),
            'booking_timezone' => 'Europe/London', 'status' => 'confirmed',
            'booking_source' => 'admin_manual', 'meeting_method' => 'tbc',
        ]);

        $this->postJson("/api/appointments/{$appointment->id}/complete", [])->assertStatus(200);

        Bus::assertNotDispatched(SendConsultationCommunicationJob::class);
        Bus::assertNotDispatched(\App\Jobs\SendAppointmentEmailJob::class);
    }

    // ── Summary-published email (Scope D) ────────────────────────────────

    public function test_summary_published_email_content_and_secure_button_no_raw_signature_in_html(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeConsultationAppointment(public: true);
        $appointment->consultationEnquiry->update([
            'customer_summary_published' => 'Here is the written advice.',
            'customer_summary_published_at' => now(),
        ]);

        $service = app(ConsultationCommunicationService::class);
        $this->assertTrue($service->sendSummaryPublished($appointment->fresh(['consultationEnquiry'])));

        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_contains($body['htmlContent'], 'View Consultation Summary')
                && str_contains($body['htmlContent'], 'Retention clause advice')
                && str_contains($body['htmlContent'], 'Jane Consultant')
                && !str_contains($body['htmlContent'], 'Here is the written advice.');
        });
    }

    public function test_summary_published_republish_sends_again_but_retry_of_same_publish_does_not(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeConsultationAppointment(public: true);
        $enquiry = $appointment->consultationEnquiry;
        $enquiry->update(['customer_summary_published' => 'Version 1', 'customer_summary_published_at' => now()]);

        $service = app(ConsultationCommunicationService::class);
        $this->assertTrue($service->sendSummaryPublished($appointment->fresh(['consultationEnquiry'])));

        // A retried job for the exact SAME publish (identical published_at)
        // must not send a second time.
        $this->assertFalse($service->sendSummaryPublished($appointment->fresh(['consultationEnquiry'])));
        Http::assertSentCount(1);

        // A genuine republish, later, with a fresh published_at — must send again.
        Carbon::setTestNow(now()->addMinute());
        $enquiry->update(['customer_summary_published' => 'Version 2', 'customer_summary_published_at' => now()]);
        $this->assertTrue($service->sendSummaryPublished($appointment->fresh(['consultationEnquiry'])));
        Carbon::setTestNow();

        Http::assertSentCount(2);
        $this->assertSame(2, ConsultationCommunicationDelivery::where('appointment_id', $appointment->id)
            ->where('communication_type', 'like', 'summary_published_%')->count());
    }

    public function test_publish_summary_endpoint_dispatches_the_new_job_end_to_end(): void
    {
        Bus::fake();
        $consultant = User::factory()->create(['name' => 'Jane Consultant']);
        $appointment = $this->makeConsultationAppointment(public: true, consultant: $consultant);
        $consultant->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($consultant);

        $this->putJson("/api/admin/consultancy/consultations/{$appointment->id}/summary", ['customer_summary_draft' => 'Draft text.'])
            ->assertStatus(200);
        $this->postJson("/api/admin/consultancy/consultations/{$appointment->id}/summary/publish")
            ->assertStatus(200);

        Bus::assertDispatched(SendConsultationCommunicationJob::class, fn ($job) => $job->kind === 'summary_published' && $job->appointmentId === $appointment->id);
        Bus::assertNotDispatched(\App\Jobs\SendConsultationEmailJob::class);
    }
}
