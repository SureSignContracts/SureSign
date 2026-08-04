<?php

namespace Tests\Feature;

use App\Jobs\SendAppointmentEmailJob;
use App\Jobs\SendConsultationCommunicationJob;
use App\Models\Appointment;
use App\Models\AppointmentAvailability;
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
use App\Support\Google\CalendarSyncState;
use App\Support\Google\MeetConferenceState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Consultancy Communications & Global Email Experience Upgrade, Batch 2 —
 * `booking_rescheduled`, `booking_cancelled`, and `meeting_reminder_{offset}`,
 * plus wiring both the generic Appointment reschedule/cancel/reminder paths
 * (which also serve Consultancy bookings) and the Consultancy-only endpoints
 * to route through this Consultancy-specific communication instead of the
 * generic AppointmentEmailService one. Every automated test here uses
 * Http::fake() for Brevo — no real email is ever sent from this suite.
 */
class ConsultancyCommunicationsBatch2Test extends TestCase
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
            'appointment_reminders_enabled' => true,
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
    private function makeConsultationAppointment(bool $public = true, array $overrides = [], ?User $consultant = null): Appointment
    {
        static $n = 0;
        $n++;
        $type = $this->makeType();
        $service = $this->makeConsultancyService($type);

        $consultant ??= User::factory()->create();
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

    // ── booking_rescheduled ──────────────────────────────────────────────

    public function test_booking_rescheduled_sends_with_pending_meet_wording_and_no_duplicate(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeConsultationAppointment(public: true);

        $service = app(ConsultationCommunicationService::class);
        $this->assertTrue($service->sendBookingRescheduled($appointment));

        Http::assertSent(fn ($request) => str_contains($request->data()['htmlContent'], 'has been rescheduled')
            && str_contains($request->data()['htmlContent'], 'Your Google Meet link is being prepared'));

        $this->assertSame(1, ConsultationCommunicationDelivery::where('appointment_id', $appointment->id)
            ->where('communication_type', 'booking_rescheduled')->count());

        $this->assertFalse($service->sendBookingRescheduled($appointment));
        Http::assertSentCount(1);
    }

    public function test_booking_rescheduled_includes_join_button_and_ics_when_meet_available(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeConsultationAppointment(public: true);
        $this->makeSync($appointment, CalendarSyncState::SYNCED, MeetConferenceState::AVAILABLE, 'https://meet.google.com/abc-defg-hij');

        app(ConsultationCommunicationService::class)->sendBookingRescheduled($appointment->fresh(['externalSync']));

        Http::assertSent(function ($request) {
            $body = $request->data();
            if (!str_contains($body['htmlContent'], 'https://meet.google.com/abc-defg-hij')) {
                return false;
            }
            $attachment = $body['attachment'][0] ?? null;
            $ics = $attachment ? base64_decode($attachment['content']) : '';
            return str_contains($ics, 'meet.google.com/abc-defg-hij');
        });
    }

    // ── booking_cancelled ────────────────────────────────────────────────

    public function test_booking_cancelled_sends_with_reason_ics_cancellation_and_no_duplicate(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeConsultationAppointment(public: true, overrides: [
            'status' => 'cancelled', 'cancellation_reason' => 'Client requested a different date.',
        ]);

        $service = app(ConsultationCommunicationService::class);
        $this->assertTrue($service->sendBookingCancelled($appointment));

        Http::assertSent(function ($request) {
            $body = $request->data();
            if (!str_contains($body['htmlContent'], 'has been cancelled')
                || !str_contains($body['textContent'], 'Client requested a different date.')) {
                return false;
            }
            $attachment = $body['attachment'][0] ?? null;
            $ics = $attachment ? base64_decode($attachment['content']) : '';
            return str_contains($ics, 'METHOD:CANCEL') && str_contains($ics, 'STATUS:CANCELLED');
        });

        $this->assertSame(1, ConsultationCommunicationDelivery::where('appointment_id', $appointment->id)
            ->where('communication_type', 'booking_cancelled')->count());

        $this->assertFalse($service->sendBookingCancelled($appointment));
        Http::assertSentCount(1);
    }

    public function test_booking_cancelled_omits_reschedule_and_cancel_actions(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeConsultationAppointment(public: true, overrides: ['status' => 'cancelled']);

        app(ConsultationCommunicationService::class)->sendBookingCancelled($appointment);

        Http::assertSent(fn ($request) => !str_contains($request->data()['textContent'], 'Reschedule:')
            && !str_contains($request->data()['textContent'], 'Cancel Booking:'));
    }

    // ── meeting_reminder ─────────────────────────────────────────────────

    public function test_meeting_reminder_sends_with_offset_wording_and_no_ics(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeConsultationAppointment(public: true);
        $this->makeSync($appointment, CalendarSyncState::SYNCED, MeetConferenceState::AVAILABLE, 'https://meet.google.com/abc-defg-hij');

        $service = app(ConsultationCommunicationService::class);
        $this->assertTrue($service->sendMeetingReminder($appointment->fresh(['externalSync']), 1440));

        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_contains($body['htmlContent'], 'coming up in about 24 hour(s)')
                && str_contains($body['htmlContent'], 'https://meet.google.com/abc-defg-hij')
                && empty($body['attachment'] ?? []);
        });
    }

    public function test_meeting_reminder_is_idempotent_per_offset_but_distinct_across_offsets(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeConsultationAppointment(public: true);
        $service = app(ConsultationCommunicationService::class);

        $this->assertTrue($service->sendMeetingReminder($appointment, 1440));
        $this->assertFalse($service->sendMeetingReminder($appointment, 1440));
        // A different offset for the same schedule_version is a genuinely
        // distinct communication, not a duplicate.
        $this->assertTrue($service->sendMeetingReminder($appointment, 60));

        Http::assertSentCount(2);
        $this->assertSame(2, ConsultationCommunicationDelivery::where('appointment_id', $appointment->id)->count());
        $this->assertSame(1, ConsultationCommunicationDelivery::where('appointment_id', $appointment->id)
            ->where('communication_type', 'meeting_reminder_1440')->count());
        $this->assertSame(1, ConsultationCommunicationDelivery::where('appointment_id', $appointment->id)
            ->where('communication_type', 'meeting_reminder_60')->count());
    }

    // ── SendAppointmentReminders command routing ────────────────────────

    public function test_reminder_command_routes_consultancy_appointment_through_consultation_job(): void
    {
        Bus::fake();
        $appointment = $this->makeConsultationAppointment(public: true, overrides: [
            'starts_at' => now()->addHours(23), 'ends_at' => now()->addHours(23)->addMinutes(30),
        ]);

        $this->artisan('suresign:send-appointment-reminders')->assertExitCode(0);

        Bus::assertDispatched(SendConsultationCommunicationJob::class, fn ($job) => $job->kind === 'meeting_reminder'
            && $job->appointmentId === $appointment->id
            && $job->context['offset_minutes'] === 1440);
        Bus::assertNotDispatched(SendAppointmentEmailJob::class);

        $this->assertSame(1, AppointmentReminderSend::where('appointment_id', $appointment->id)->count());
    }

    public function test_reminder_command_still_routes_non_consultancy_appointment_through_generic_job(): void
    {
        Bus::fake();
        $type = $this->makeType();
        $staff = User::factory()->create();
        $staff->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));

        Appointment::create([
            'reference' => 'APT-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'appointment_type_id' => $type->id,
            'assigned_user_id' => $staff->id,
            'attendee_name' => 'Jane Doe', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London',
            'starts_at' => now()->addHours(23), 'ends_at' => now()->addHours(23)->addMinutes(30),
            'booking_timezone' => 'Europe/London', 'status' => 'confirmed',
            'booking_source' => 'admin_manual', 'meeting_method' => 'tbc',
        ]);

        $this->artisan('suresign:send-appointment-reminders')->assertExitCode(0);

        Bus::assertDispatched(SendAppointmentEmailJob::class, fn ($job) => $job->kind === 'reminder');
        Bus::assertNotDispatched(SendConsultationCommunicationJob::class);
    }

    public function test_meeting_reminder_job_marks_reminder_send_row_sent(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeConsultationAppointment(public: true);
        $send = AppointmentReminderSend::create([
            'appointment_id' => $appointment->id, 'offset_minutes' => 1440,
            'schedule_version' => $appointment->schedule_version,
            'scheduled_for' => now(), 'status' => 'pending',
        ]);

        (new SendConsultationCommunicationJob($appointment->id, 'meeting_reminder', [
            'offset_minutes' => 1440, 'reminder_send_id' => $send->id,
        ]))->handle(app(ConsultationCommunicationService::class));

        $this->assertSame('sent', $send->fresh()->status);
        $this->assertNotNull($send->fresh()->sent_at);
    }

    // ── Dispatch wiring: internal AppointmentController ─────────────────

    public function test_internal_reschedule_of_consultancy_appointment_dispatches_consultation_job(): void
    {
        Bus::fake();
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($superAdmin);

        $consultant = User::factory()->create();
        $appointment = $this->makeConsultationAppointment(public: true, consultant: $consultant);
        $this->grantOpenAvailability($consultant);

        $response = $this->postJson("/api/appointments/{$appointment->id}/reschedule", [
            'date' => now()->addDays(5)->toDateString(), 'start_time' => '11:00', 'timezone' => 'Europe/London',
        ]);

        $response->assertStatus(200);
        Bus::assertDispatched(SendConsultationCommunicationJob::class, fn ($job) => $job->kind === 'booking_rescheduled'
            && $job->appointmentId === $appointment->id);
        Bus::assertNotDispatched(SendAppointmentEmailJob::class);
    }

    public function test_internal_cancel_of_consultancy_appointment_dispatches_consultation_job(): void
    {
        Bus::fake();
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($superAdmin);

        $appointment = $this->makeConsultationAppointment(public: true);

        $response = $this->postJson("/api/appointments/{$appointment->id}/cancel", []);

        $response->assertStatus(200);
        Bus::assertDispatched(SendConsultationCommunicationJob::class, fn ($job) => $job->kind === 'booking_cancelled'
            && $job->appointmentId === $appointment->id);
        Bus::assertNotDispatched(SendAppointmentEmailJob::class);
    }

    // ── Dispatch wiring: public signed links ────────────────────────────

    public function test_public_reschedule_of_consultancy_booking_dispatches_consultation_job(): void
    {
        Bus::fake();
        $consultant = User::factory()->create();
        $appointment = $this->makeConsultationAppointment(public: true, consultant: $consultant);
        $this->grantOpenAvailability($consultant);

        $url = \URL::temporarySignedRoute('public.appointments.reschedule', now()->addDay(), ['token' => $appointment->public_token]);
        $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);

        $response = $this->postJson($path, [
            'date' => now()->addDays(5)->toDateString(), 'start_time' => '11:00', 'timezone' => 'Europe/London',
        ]);

        $response->assertStatus(200);
        Bus::assertDispatched(SendConsultationCommunicationJob::class, fn ($job) => $job->kind === 'booking_rescheduled');
        Bus::assertNotDispatched(SendAppointmentEmailJob::class);
    }

    public function test_public_cancel_of_consultancy_booking_dispatches_consultation_job(): void
    {
        Bus::fake();
        $appointment = $this->makeConsultationAppointment(public: true);

        $url = \URL::temporarySignedRoute('public.appointments.cancel', now()->addDay(), ['token' => $appointment->public_token]);
        $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);

        $response = $this->postJson($path, []);

        $response->assertStatus(200);
        Bus::assertDispatched(SendConsultationCommunicationJob::class, fn ($job) => $job->kind === 'booking_cancelled');
        Bus::assertNotDispatched(SendAppointmentEmailJob::class);
    }

    public function test_public_cancel_of_ordinary_appointment_still_uses_generic_job(): void
    {
        Bus::fake();
        $type = $this->makeType();
        $staff = User::factory()->create();
        $staff->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));

        $appointment = Appointment::create([
            'reference' => 'APT-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'appointment_type_id' => $type->id,
            'assigned_user_id' => $staff->id,
            'attendee_name' => 'Jane Doe', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London',
            'starts_at' => now()->addDays(3)->setTime(14, 0), 'ends_at' => now()->addDays(3)->setTime(14, 30),
            'booking_timezone' => 'Europe/London', 'status' => 'confirmed',
            'booking_source' => 'public_booking_page', 'meeting_method' => 'tbc',
        ]);

        $url = \URL::temporarySignedRoute('public.appointments.cancel', now()->addDay(), ['token' => $appointment->public_token]);
        $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);

        $response = $this->postJson($path, []);

        $response->assertStatus(200);
        Bus::assertDispatched(SendAppointmentEmailJob::class, fn ($job) => $job->kind === 'transition');
        Bus::assertNotDispatched(SendConsultationCommunicationJob::class);
    }

    // ── Dispatch wiring: customer-facing ConsultationController ─────────

    public function test_customer_facing_cancel_dispatches_consultation_job(): void
    {
        Bus::fake();
        $appointment = $this->makeConsultationAppointment(public: false);
        Sanctum::actingAs($appointment->linkedUser);

        $response = $this->postJson("/api/consultations/{$appointment->id}/cancel", ['reason' => 'No longer needed']);

        $response->assertStatus(200);
        Bus::assertDispatched(SendConsultationCommunicationJob::class, fn ($job) => $job->kind === 'booking_cancelled'
            && $job->appointmentId === $appointment->id);
        Bus::assertNotDispatched(SendAppointmentEmailJob::class);
    }
}
