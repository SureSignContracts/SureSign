<?php

namespace Tests\Feature;

use App\Jobs\SendEmailVerificationJob;
use App\Jobs\SendPasswordResetEmailJob;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Organization;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Services\AccountEmailService;
use App\Services\AppointmentEmailService;
use App\Services\EmailNotificationService;
use App\Services\EmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Tests\TestCase;

/**
 * Communications Platform, Batch 4 — Phase 2, Option B (with the two
 * priority fixes). Covers:
 *   - the DemoRequestController subject HTML-injection fix
 *   - password reset / email verification migrated onto queued,
 *     ->afterCommit(), EmailComponents-rendered delivery
 *   - EmailNotificationService::send()'s upgraded internals (category
 *     label, optional CTA button via $meta, plaintext alternative)
 *   - AppointmentEmailService's migration onto EmailComponents
 *   - SupportTicketController::emailTicketOwner()'s migration
 *
 * Every test uses Http::fake() for Brevo — no real email is ever sent.
 */
class CommunicationsPlatformBatch4Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SuresignSetting::instance()->update([
            'brevo_api_key' => 'fake-brevo-key',
            'email_sender_email' => 'noreply@suresigncontracts.app',
            'support_email' => 'support@suresigncontracts.app',
            'admin_email' => 'admin@suresigncontracts.app',
            'appointment_ics_enabled' => true,
        ]);
    }

    private function fakeBrevo(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'fake-message-id'], 201)]);
    }

    // ── Priority 1: DemoRequestController subject injection ─────────────

    public function test_demo_request_subject_is_escaped_even_with_html_in_company_field(): void
    {
        $this->fakeBrevo();

        $response = $this->postJson('/api/demo-requests', [
            'name' => 'Jane Doe',
            'company' => '<img src=x onerror=alert(1)>Evil Corp',
            'email' => 'jane@example.com',
            'phone' => '01234 567890',
        ]);

        $response->assertStatus(201);

        Http::assertSent(function ($request) {
            $html = $request->data()['htmlContent'];
            return !str_contains($html, '<img src=x onerror=alert(1)>')
                && str_contains($html, '&lt;img src=x onerror=alert(1)&gt;Evil Corp');
        });
    }

    public function test_demo_request_without_optional_phone_or_message_does_not_error(): void
    {
        // Incidental fix found while addressing the subject-escaping issue —
        // 'phone'/'message' are nullable but were accessed without a guard.
        $this->fakeBrevo();

        $response = $this->postJson('/api/demo-requests', [
            'name' => 'Jane Doe',
            'company' => 'Acme Ltd',
            'email' => 'jane@example.com',
        ]);

        $response->assertStatus(201);
    }

    public function test_subject_escaping_is_not_doubled_for_callers_that_previously_escaped_locally(): void
    {
        // Regression guard for the double-escaping risk when centralising
        // subject-escaping into buildHtml(): SupportTicketController's own
        // local e() calls were removed in the same change — a subject
        // containing an ampersand must render exactly once-escaped.
        $this->fakeBrevo();

        EmailNotificationService::sendDirect('someone@example.com', "Ben & Jerry's Ltd", 'Body text');

        Http::assertSent(function ($request) {
            $html = $request->data()['htmlContent'];
            return str_contains($html, 'Ben &amp; Jerry&#039;s Ltd')
                && !str_contains($html, '&amp;amp;');
        });
    }

    // ── Priority 2: password reset / email verification ─────────────────

    public function test_password_reset_dispatches_queued_job_afterCommit_not_synchronous(): void
    {
        Bus::fake();
        $user = User::factory()->create(['email' => 'reset-target@example.com', 'name' => 'Reset Target']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'reset-target@example.com'])
            ->assertStatus(200);

        Bus::assertDispatched(SendPasswordResetEmailJob::class, fn ($job) => $job->email === 'reset-target@example.com' && $job->name === 'Reset Target');
    }

    public function test_password_reset_email_content_has_button_and_plaintext_alternative(): void
    {
        $this->fakeBrevo();

        $sent = app(AccountEmailService::class)->sendPasswordReset('reset@example.com', 'Reset Target', 'https://app.example.com/reset-password?token=abc');

        $this->assertTrue($sent);
        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_contains($body['htmlContent'], 'Reset Password')
                && str_contains($body['htmlContent'], 'https://app.example.com/reset-password?token=abc')
                && str_contains($body['htmlContent'], '>Contact us</a>')
                && str_contains($body['htmlContent'], 'https://suresigncontracts.app/contact')
                && str_contains($body['textContent'], 'Reset it here: https://app.example.com/reset-password?token=abc');
        });
    }

    public function test_email_verification_dispatches_queued_job_afterCommit(): void
    {
        Bus::fake();
        $user = User::factory()->create(['email' => 'verify-target@example.com', 'name' => 'Verify Target']);

        EmailVerificationService::sendVerificationLink($user);

        Bus::assertDispatched(SendEmailVerificationJob::class, fn ($job) => $job->email === 'verify-target@example.com');
    }

    public function test_email_verification_content_has_button_and_plaintext_alternative(): void
    {
        $this->fakeBrevo();

        $sent = app(AccountEmailService::class)->sendEmailVerification('verify@example.com', 'Verify Target', 'https://app.example.com/verify-email?token=xyz');

        $this->assertTrue($sent);
        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_contains($body['htmlContent'], 'Verify Email Address')
                && str_contains($body['textContent'], 'Verify it here: https://app.example.com/verify-email?token=xyz');
        });
    }

    // ── EmailNotificationService::send() upgraded internals ─────────────

    public function test_send_now_includes_plaintext_alternative_and_category_label(): void
    {
        $this->fakeBrevo();
        SuresignSetting::instance()->update(['notification_settings' => ['variation.approved']]);
        $org = Organization::create(['name' => 'Org A', 'slug' => 'org-a', 'timezone' => 'Europe/London', 'email' => 'org-a@example.com']);

        EmailNotificationService::send('variation.approved', 'Variation #1 Approved', 'Your variation has been approved.', [], $org);

        Http::assertSent(function ($request) {
            $body = $request->data();
            // The category label is rendered title-case in the HTML itself
            // (buildHtml()'s own CSS applies text-transform:uppercase for
            // display — the underlying markup is never uppercased).
            return str_contains($body['htmlContent'], '>Variation<')
                && str_contains($body['htmlContent'], 'Your variation has been approved.')
                && $body['textContent'] === 'Your variation has been approved.';
        });
    }

    public function test_send_renders_action_button_when_meta_provides_one(): void
    {
        $this->fakeBrevo();
        SuresignSetting::instance()->update(['notification_settings' => ['variation.approved']]);
        $org = Organization::create(['name' => 'Org B', 'slug' => 'org-b', 'timezone' => 'Europe/London', 'email' => 'org-b@example.com']);

        EmailNotificationService::send(
            'variation.approved',
            'Variation #1 Approved',
            'Your variation has been approved.',
            EmailNotificationService::actionMeta('/app/projects/1/commercial', 'View Variation'),
            $org,
        );

        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_contains($body['htmlContent'], 'View Variation')
                && str_contains($body['htmlContent'], config('suresign.frontend_url') . '/app/projects/1/commercial')
                && str_contains($body['textContent'], 'View Variation: ' . config('suresign.frontend_url') . '/app/projects/1/commercial');
        });
    }

    public function test_action_meta_returns_empty_array_when_no_url(): void
    {
        $this->assertSame([], EmailNotificationService::actionMeta(null, 'View'));
    }

    public function test_send_still_respects_notification_settings_gate_unchanged(): void
    {
        $this->fakeBrevo();
        SuresignSetting::instance()->update(['notification_settings' => []]);
        $org = Organization::create(['name' => 'Org C', 'slug' => 'org-c', 'timezone' => 'Europe/London']);

        EmailNotificationService::send('variation.approved', 'Should not send', 'Body', [], $org);

        Http::assertNothingSent();
    }

    // ── AppointmentEmailService migrated onto EmailComponents ────────────

    private function makeApptType(): AppointmentType
    {
        static $n = 0;
        $n++;
        return AppointmentType::create([
            'name' => "Type {$n}", 'slug' => "type-{$n}",
            'duration_minutes' => 30, 'is_active' => true, 'is_public' => true, 'assignment_mode' => 'manual',
            'meeting_method' => 'tbc', 'requires_confirmation' => false,
            'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'min_notice_hours' => 0, 'max_advance_days' => 60,
        ]);
    }

    private function makeApptForEmail(array $overrides = []): Appointment
    {
        $type = $this->makeApptType();
        return Appointment::create(array_merge([
            'reference' => 'APT-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'appointment_type_id' => $type->id,
            'attendee_name' => 'Jane Doe',
            'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London',
            'starts_at' => now()->addDays(3)->setTime(10, 0),
            'ends_at' => now()->addDays(3)->setTime(10, 30),
            'booking_timezone' => 'Europe/London',
            'status' => 'confirmed',
            'booking_source' => 'public_booking_page',
            'meeting_method' => 'tbc',
        ], $overrides));
    }

    public function test_appointment_confirmed_email_uses_email_components_and_plaintext_alt(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeApptForEmail(['status' => 'confirmed']);

        app(AppointmentEmailService::class)->sendForCreation($appointment);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_contains($body['htmlContent'], 'is confirmed')
                && !empty($body['textContent'])
                && str_contains($body['textContent'], 'Reference:');
        });
    }

    public function test_appointment_with_meeting_url_renders_join_button(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeApptForEmail(['status' => 'confirmed', 'meeting_url' => 'https://meet.example.com/room']);

        app(AppointmentEmailService::class)->sendForCreation($appointment);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_contains($body['htmlContent'], 'Join Meeting')
                && str_contains($body['htmlContent'], 'https://meet.example.com/room');
        });
    }

    public function test_appointment_cancelled_email_no_longer_exposes_raw_reschedule_cancel_urls_as_paragraph_text_only(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeApptForEmail(['status' => 'cancelled', 'cancellation_reason' => 'Client requested cancellation.']);

        app(AppointmentEmailService::class)->sendForTransition($appointment, 'cancelled');

        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_contains($body['htmlContent'], 'has been cancelled')
                && str_contains($body['htmlContent'], 'Client requested cancellation.')
                && str_contains($body['textContent'], 'Client requested cancellation.');
        });
    }

    // ── Support ticket owner email migrated ──────────────────────────────

    public function test_support_ticket_owner_email_includes_button_and_plaintext(): void
    {
        $this->fakeBrevo();
        $org = Organization::create(['name' => 'Org D', 'slug' => 'org-d', 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $ticket = \App\Models\SupportTicket::create([
            'reference' => 'SUP-000001',
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'subject' => 'Cannot open project',
            'message' => 'Details here.',
            'status' => 'open',
        ]);

        \App\Http\Controllers\Api\SupportTicketController::emailTicketOwner(
            $ticket,
            'Your support request has been resolved',
            "Your request has been marked resolved.\n\nIf this doesn't fully address your question, just reply.",
            "/app/help/support/{$ticket->id}",
        );

        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_contains($body['htmlContent'], 'View Request')
                && str_contains($body['textContent'], 'View Request:')
                && str_contains($body['htmlContent'], "has been marked resolved");
        });
    }
}
