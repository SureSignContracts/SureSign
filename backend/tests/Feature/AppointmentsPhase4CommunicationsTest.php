<?php

namespace Tests\Feature;

use App\Jobs\SendAppointmentEmailJob;
use App\Models\Appointment;
use App\Models\AppointmentAvailability;
use App\Models\AppointmentReminderSend;
use App\Models\AppointmentType;
use App\Models\Organization;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Services\AppointmentEmailService;
use App\Services\AppointmentIcsService;
use App\Services\AppointmentPublicLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Appointments & Scheduling — Phase 4 (Communications & Appointment Experience).
 */
class AppointmentsPhase4CommunicationsTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role, ?Organization $org = null): User
    {
        static $n = 0;
        $n++;
        $org ??= Organization::create(['name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        return $user;
    }

    private function makeType(array $overrides = []): AppointmentType
    {
        static $n = 0;
        $n++;
        return AppointmentType::create(array_merge([
            'name' => "Type {$n}", 'slug' => "type-{$n}",
            'duration_minutes' => 30, 'is_active' => true, 'is_public' => true, 'assignment_mode' => 'manual',
            'meeting_method' => 'tbc', 'requires_confirmation' => false,
            'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'min_notice_hours' => 0, 'max_advance_days' => 60,
        ], $overrides));
    }

    private function grantOpenAvailability(User $staff): void
    {
        for ($weekday = 0; $weekday <= 6; $weekday++) {
            AppointmentAvailability::create([
                'user_id' => $staff->id, 'weekday' => $weekday,
                'start_time' => '00:00', 'end_time' => '23:59', 'is_active' => true,
            ]);
        }
    }

    private function makeAppointment(array $overrides = []): Appointment
    {
        $type = $this->makeType();
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

    private function fakeBrevo(): void
    {
        SuresignSetting::instance()->update(['brevo_api_key' => 'fake-brevo-key', 'email_sender_email' => 'noreply@suresigncontracts.app']);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'test'], 201)]);
    }

    // ── Confirmation / lifecycle emails dispatch correctly ─────────────────

    public function test_public_booking_dispatches_created_email_job(): void
    {
        Bus::fake();
        $type = $this->makeType(['requires_confirmation' => false]);

        $response = $this->postJson("/api/public/appointment-types/{$type->slug}/book", [
            'appointment_type_slug' => $type->slug,
            'attendee_name' => 'Jane Doe', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London',
            'date' => now()->addDays(3)->toDateString(), 'start_time' => '10:00', 'timezone' => 'Europe/London',
            'consent' => true,
        ]);
        $response->assertStatus(201);

        Bus::assertDispatched(SendAppointmentEmailJob::class, fn ($job) => $job->kind === 'created');
    }

    public function test_confirming_a_pending_appointment_dispatches_transition_email(): void
    {
        Bus::fake();
        $superAdmin = $this->makeUser('Super Admin');
        $type = $this->makeType(['requires_confirmation' => true]);
        Sanctum::actingAs($superAdmin);

        $store = $this->postJson('/api/appointments', [
            'appointment_type_id' => $type->id,
            'attendee_name' => 'Jane Doe', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London',
            'date' => now()->addDays(3)->toDateString(), 'start_time' => '10:00', 'timezone' => 'Europe/London',
        ]);
        $id = $store->json('id');

        $this->postJson("/api/appointments/{$id}/confirm")->assertStatus(200);

        Bus::assertDispatched(SendAppointmentEmailJob::class, fn ($job) => $job->kind === 'transition' && $job->context['to_status'] === 'confirmed');
    }

    public function test_completing_or_no_show_does_not_dispatch_any_email(): void
    {
        Bus::fake();
        $superAdmin = $this->makeUser('Super Admin');
        $type = $this->makeType();
        Sanctum::actingAs($superAdmin);

        $store = $this->postJson('/api/appointments', [
            'appointment_type_id' => $type->id,
            'attendee_name' => 'Jane Doe', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London',
            'date' => now()->addDays(3)->toDateString(), 'start_time' => '10:00', 'timezone' => 'Europe/London',
        ]);
        $id = $store->json('id');
        Bus::fake(); // reset after the 'created' dispatch above

        $this->postJson("/api/appointments/{$id}/complete")->assertStatus(200);
        Bus::assertNotDispatched(SendAppointmentEmailJob::class);
    }

    public function test_rescheduling_dispatches_reschedule_email(): void
    {
        Bus::fake();
        $superAdmin = $this->makeUser('Super Admin');
        $admin = $this->makeUser('Admin');
        $this->grantOpenAvailability($admin);
        $type = $this->makeType();
        Sanctum::actingAs($superAdmin);

        $store = $this->postJson('/api/appointments', [
            'appointment_type_id' => $type->id, 'assigned_user_id' => $admin->id,
            'attendee_name' => 'Jane Doe', 'attendee_email' => 'jane@example.com',
            'attendee_timezone' => 'Europe/London',
            'date' => now()->addDays(3)->toDateString(), 'start_time' => '10:00', 'timezone' => 'Europe/London',
        ]);
        $id = $store->json('id');
        Bus::fake();

        $this->postJson("/api/appointments/{$id}/reschedule", [
            'date' => now()->addDays(3)->toDateString(), 'start_time' => '11:00', 'timezone' => 'Europe/London',
        ])->assertStatus(200);

        Bus::assertDispatched(SendAppointmentEmailJob::class, fn ($job) => $job->kind === 'reschedule');
    }

    // ── Email content ───────────────────────────────────────────────────────

    public function test_confirmed_email_content_and_ics_attachment(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeAppointment(['status' => 'confirmed']);

        app(AppointmentEmailService::class)->sendForCreation($appointment);

        Http::assertSent(function ($request) use ($appointment) {
            $payload = $request->data();
            $sentTo = $payload['to'][0]['email'] ?? null;
            $hasIcs = collect($payload['attachment'] ?? [])->contains(fn ($a) => str_ends_with($a['name'], '.ics'));
            return $sentTo === 'jane@example.com'
                && str_contains($payload['subject'], $appointment->reference)
                && $hasIcs;
        });
    }

    public function test_awaiting_confirmation_email_has_no_ics(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeAppointment(['status' => 'requested']);

        app(AppointmentEmailService::class)->sendForCreation($appointment);

        Http::assertSent(function ($request) {
            $payload = $request->data();
            return empty($payload['attachment']);
        });
    }

    public function test_completed_and_no_show_send_no_email(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeAppointment(['status' => 'completed']);

        $service = app(AppointmentEmailService::class);
        $this->assertFalse($service->sendForTransition($appointment, 'completed'));
        $this->assertFalse($service->sendForTransition($appointment, 'no_show'));
        Http::assertNothingSent();
    }

    // ── ICS generation (RFC5545 correctness) ────────────────────────────────

    public function test_ics_contains_required_fields_and_crlf_line_endings(): void
    {
        $appointment = $this->makeAppointment();
        $ics = app(AppointmentIcsService::class)->generate($appointment);

        foreach (['BEGIN:VCALENDAR', 'VERSION:2.0', 'METHOD:PUBLISH', 'BEGIN:VEVENT', 'UID:', 'DTSTAMP:', 'DTSTART:', 'DTEND:', 'SUMMARY:', 'ORGANIZER', 'ATTENDEE', 'STATUS:', 'SEQUENCE:', 'END:VEVENT', 'END:VCALENDAR'] as $required) {
            $this->assertStringContainsString($required, $ics);
        }
        $this->assertStringContainsString("\r\n", $ics);
        $this->assertStringNotContainsString("\n\n", str_replace("\r\n", '', $ics)); // no bare LF survives outside CRLF pairs
    }

    public function test_ics_uid_is_stable_across_regenerations(): void
    {
        $appointment = $this->makeAppointment();
        $service = app(AppointmentIcsService::class);

        preg_match('/UID:(.+)/', $service->generate($appointment), $first);
        preg_match('/UID:(.+)/', $service->generate($appointment), $second);

        $this->assertSame(trim($first[1]), trim($second[1]));
    }

    public function test_ics_uid_is_stable_across_reschedule_even_though_token_rotates(): void
    {
        // public_token deliberately rotates on reschedule (invalidates old
        // email links) — the UID must NOT be derived from it, or every
        // reschedule would make calendar clients treat the update as a
        // brand-new event instead of replacing the existing one.
        $appointment = $this->makeAppointment();
        $service = app(AppointmentIcsService::class);
        $tokenBefore = $appointment->public_token;

        preg_match('/UID:(.+)/', $service->generate($appointment), $before);

        $appointment->update(['public_token' => 'a-completely-different-rotated-token', 'schedule_version' => 1]);
        $this->assertNotSame($tokenBefore, $appointment->public_token);

        preg_match('/UID:(.+)/', $service->generate($appointment->fresh()), $after);

        $this->assertSame(trim($before[1]), trim($after[1]));
        $this->assertStringContainsString($appointment->reference, trim($before[1]));
    }

    public function test_ics_sequence_increments_after_reschedule(): void
    {
        $appointment = $this->makeAppointment(['schedule_version' => 0]);
        $service = app(AppointmentIcsService::class);
        $before = $service->generate($appointment);

        $appointment->update(['schedule_version' => 1]);
        $after = $service->generate($appointment->fresh());

        $this->assertStringContainsString('SEQUENCE:0', $before);
        $this->assertStringContainsString('SEQUENCE:1', $after);
    }

    public function test_ics_escapes_special_characters_in_description(): void
    {
        SuresignSetting::instance()->update(['appointment_default_meeting_instructions' => "Bring; your, laptop\nand notes"]);
        $appointment = $this->makeAppointment();
        $ics = app(AppointmentIcsService::class)->generate($appointment);
        // Unfold per RFC5545 §3.1 before asserting — a CRLF followed by a
        // single space is a folding artifact, not real content, and the
        // escaped text may legitimately be split across a fold boundary.
        $unfolded = str_replace("\r\n ", '', $ics);

        $this->assertStringContainsString('Bring\\; your\\, laptop\\nand notes', $unfolded);
    }

    public function test_ics_status_reflects_appointment_status(): void
    {
        $service = app(AppointmentIcsService::class);
        $confirmed = $this->makeAppointment(['status' => 'confirmed']);
        $cancelled = $this->makeAppointment(['status' => 'cancelled']);
        $pending   = $this->makeAppointment(['status' => 'pending_confirmation']);

        $this->assertStringContainsString('STATUS:CONFIRMED', $service->generate($confirmed));
        $this->assertStringContainsString('STATUS:CANCELLED', $service->generate($cancelled));
        $this->assertStringContainsString('STATUS:TENTATIVE', $service->generate($pending));
    }

    public function test_ics_cancellation_uses_method_cancel_with_same_uid_and_status(): void
    {
        $appointment = $this->makeAppointment(['status' => 'confirmed']);
        $service = app(AppointmentIcsService::class);

        $confirmation = $service->generate($appointment);
        $cancellation = $service->generateCancellation($appointment);

        $this->assertStringContainsString('METHOD:PUBLISH', $confirmation);
        $this->assertStringContainsString('METHOD:CANCEL', $cancellation);
        $this->assertStringContainsString('STATUS:CANCELLED', $cancellation);

        preg_match('/UID:(.+)/', $confirmation, $confirmedUid);
        preg_match('/UID:(.+)/', $cancellation, $cancelledUid);
        $this->assertSame(trim($confirmedUid[1]), trim($cancelledUid[1]));
    }

    public function test_cancelled_email_attaches_cancellation_ics_when_enabled(): void
    {
        $this->fakeBrevo();
        SuresignSetting::instance()->update(['appointment_ics_enabled' => true]);
        $appointment = $this->makeAppointment(['status' => 'confirmed']);

        app(\App\Services\AppointmentEmailService::class)->sendForTransition($appointment, 'cancelled');

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $ics = collect($payload['attachment'] ?? [])->first(fn ($a) => str_ends_with($a['name'], '.ics'));
            if (!$ics) {
                return false;
            }
            $content = base64_decode($ics['content']);

            return str_contains($content, 'METHOD:CANCEL') && str_contains($content, 'STATUS:CANCELLED');
        });
    }

    // ── Signed link expiry formula ──────────────────────────────────────────

    public function test_link_expiry_uses_ttl_when_appointment_is_far_in_the_future(): void
    {
        SuresignSetting::instance()->update(['appointment_cancel_link_ttl_hours' => 10, 'appointment_cancellation_cutoff_hours' => 2]);
        $appointment = $this->makeAppointment(['starts_at' => now()->addDays(30), 'ends_at' => now()->addDays(30)->addMinutes(30)]);

        $url = app(AppointmentPublicLinkService::class)->cancelApiUrl($appointment);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        // Expiry should be ~10 hours out (TTL), not ~30 days minus 2 hours (cutoff).
        $expiresAt = (int) $params['expires'];
        $this->assertEqualsWithDelta(now()->addHours(10)->timestamp, $expiresAt, 5);
    }

    public function test_link_expiry_uses_cutoff_when_appointment_is_imminent(): void
    {
        SuresignSetting::instance()->update(['appointment_cancel_link_ttl_hours' => 720, 'appointment_cancellation_cutoff_hours' => 2]);
        $appointment = $this->makeAppointment(['starts_at' => now()->addHours(3), 'ends_at' => now()->addHours(3)->addMinutes(30)]);

        $url = app(AppointmentPublicLinkService::class)->cancelApiUrl($appointment);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        // Expiry should be ~1 hour out (starts_at - cutoff), not 720 hours.
        $expiresAt = (int) $params['expires'];
        $this->assertEqualsWithDelta(now()->addHours(1)->timestamp, $expiresAt, 5);
    }

    // ── Public signed cancel/reschedule security ────────────────────────────

    public function test_valid_signed_cancel_link_works(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeAppointment(['status' => 'confirmed']);
        $url = app(AppointmentPublicLinkService::class)->cancelApiUrl($appointment);
        $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);

        $show = $this->getJson($path);
        $show->assertStatus(200)->assertJsonPath('status', 'confirmed');

        $cancel = $this->postJson($path);
        $cancel->assertStatus(200)->assertJsonPath('status', 'cancelled');
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => 'cancelled']);
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $appointment = $this->makeAppointment();
        $url = app(AppointmentPublicLinkService::class)->cancelApiUrl($appointment);
        $tampered = preg_replace('/signature=[a-f0-9]+/', 'signature=0000000000000000000000000000000000000000000000000000000000000000', $url);
        $path = parse_url($tampered, PHP_URL_PATH) . '?' . parse_url($tampered, PHP_URL_QUERY);

        $this->getJson($path)->assertStatus(403);
    }

    public function test_expired_link_is_rejected(): void
    {
        $appointment = $this->makeAppointment();
        $expired = URL::temporarySignedRoute('public.appointments.cancel', now()->subMinute(), ['token' => $appointment->public_token]);
        $path = parse_url($expired, PHP_URL_PATH) . '?' . parse_url($expired, PHP_URL_QUERY);

        $this->getJson($path)->assertStatus(403);
    }

    public function test_cancel_link_cannot_be_reused_for_reschedule_route(): void
    {
        $appointment = $this->makeAppointment();
        $cancelUrl = app(AppointmentPublicLinkService::class)->cancelApiUrl($appointment);
        $query = parse_url($cancelUrl, PHP_URL_QUERY);

        // Same token/expiry/signature values, but against the reschedule path.
        $response = $this->getJson("/api/public/appointments/{$appointment->public_token}/reschedule?{$query}");
        $response->assertStatus(403);
    }

    public function test_reschedule_slots_url_is_signed_and_ignores_date_param(): void
    {
        $admin = $this->makeUser('Admin');
        $this->grantOpenAvailability($admin);
        $appointment = $this->makeAppointment(['assigned_user_id' => $admin->id, 'status' => 'confirmed']);
        $url = app(AppointmentPublicLinkService::class)->rescheduleSlotsApiUrl($appointment);
        $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);

        // The base signed URL carries no `date` — appending one freely must
        // still validate, since the route ignores `date` via `signed:date`.
        $withDate = $path . '&date=' . now()->addDays(3)->toDateString();
        $response = $this->getJson($withDate);
        $response->assertStatus(200)->assertJsonPath('scheduling_mode', 'fixed');
    }

    public function test_reschedule_slots_rejects_tampered_signature(): void
    {
        $admin = $this->makeUser('Admin');
        $appointment = $this->makeAppointment(['assigned_user_id' => $admin->id, 'status' => 'confirmed']);
        $url = app(AppointmentPublicLinkService::class)->rescheduleSlotsApiUrl($appointment);
        $tampered = preg_replace('/signature=[a-f0-9]+/', 'signature=0000000000000000000000000000000000000000000000000000000000000000', $url);
        $path = parse_url($tampered, PHP_URL_PATH) . '?' . parse_url($tampered, PHP_URL_QUERY);

        $this->getJson($path . '&date=' . now()->addDays(3)->toDateString())->assertStatus(403);
    }

    public function test_reschedule_slots_rejects_expired_link(): void
    {
        $admin = $this->makeUser('Admin');
        $appointment = $this->makeAppointment(['assigned_user_id' => $admin->id, 'status' => 'confirmed']);
        $expired = URL::temporarySignedRoute('public.appointments.reschedule.slots', now()->subMinute(), ['token' => $appointment->public_token]);
        $path = parse_url($expired, PHP_URL_PATH) . '?' . parse_url($expired, PHP_URL_QUERY);

        $this->getJson($path . '&date=' . now()->addDays(3)->toDateString())->assertStatus(403);
    }

    public function test_reschedule_slots_rejects_wrong_token(): void
    {
        $admin = $this->makeUser('Admin');
        $appointmentA = $this->makeAppointment(['assigned_user_id' => $admin->id, 'status' => 'confirmed']);
        $appointmentB = $this->makeAppointment(['assigned_user_id' => $admin->id, 'status' => 'confirmed']);
        $url = app(AppointmentPublicLinkService::class)->rescheduleSlotsApiUrl($appointmentA);
        $query = parse_url($url, PHP_URL_QUERY);

        // Same signature/expiry query string, but against a different token in the path.
        $response = $this->getJson("/api/public/appointments/{$appointmentB->public_token}/reschedule/slots?{$query}&date=" . now()->addDays(3)->toDateString());
        $response->assertStatus(403);
    }

    public function test_public_view_exposes_reschedule_slots_url_only_when_reschedulable(): void
    {
        $admin = $this->makeUser('Admin');
        $reschedulable = $this->makeAppointment(['assigned_user_id' => $admin->id, 'status' => 'confirmed']);
        $terminal = $this->makeAppointment(['assigned_user_id' => $admin->id, 'status' => 'cancelled']);

        $cancelUrl = app(AppointmentPublicLinkService::class)->cancelApiUrl($reschedulable);
        $path = parse_url($cancelUrl, PHP_URL_PATH) . '?' . parse_url($cancelUrl, PHP_URL_QUERY);
        $this->getJson($path)->assertStatus(200)->assertJsonPath('can_reschedule', true)
            ->assertJsonPath('reschedule_slots_url', fn ($url) => is_string($url) && str_contains($url, 'reschedule/slots'));

        $terminalCancelUrl = app(AppointmentPublicLinkService::class)->cancelApiUrl($terminal);
        $terminalPath = parse_url($terminalCancelUrl, PHP_URL_PATH) . '?' . parse_url($terminalCancelUrl, PHP_URL_QUERY);
        $this->getJson($terminalPath)->assertStatus(200)->assertJsonPath('reschedule_slots_url', null);
    }

    public function test_cancellation_is_idempotent(): void
    {
        $this->fakeBrevo();
        $appointment = $this->makeAppointment(['status' => 'cancelled']);
        $url = app(AppointmentPublicLinkService::class)->cancelApiUrl($appointment);
        $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);

        $response = $this->postJson($path);
        $response->assertStatus(200)->assertJsonPath('status', 'cancelled');
    }

    public function test_cancelled_appointment_cannot_be_rescheduled_via_signed_link(): void
    {
        $appointment = $this->makeAppointment(['status' => 'cancelled']);
        $url = app(AppointmentPublicLinkService::class)->rescheduleApiUrl($appointment);
        $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);

        $response = $this->postJson($path, ['date' => now()->addDays(5)->toDateString(), 'start_time' => '10:00', 'timezone' => 'Europe/London']);
        $response->assertStatus(422);
    }

    public function test_successful_reschedule_rotates_token_and_invalidates_old_links(): void
    {
        $this->fakeBrevo();
        $admin = $this->makeUser('Admin');
        $this->grantOpenAvailability($admin);
        $appointment = $this->makeAppointment(['assigned_user_id' => $admin->id, 'status' => 'confirmed']);
        $oldToken = $appointment->public_token;
        $oldCancelUrl = app(AppointmentPublicLinkService::class)->cancelApiUrl($appointment);

        $rescheduleUrl = app(AppointmentPublicLinkService::class)->rescheduleApiUrl($appointment);
        $path = parse_url($rescheduleUrl, PHP_URL_PATH) . '?' . parse_url($rescheduleUrl, PHP_URL_QUERY);
        $response = $this->postJson($path, ['date' => now()->addDays(4)->toDateString(), 'start_time' => '14:00', 'timezone' => 'Europe/London']);
        $response->assertStatus(200);

        $appointment->refresh();
        $this->assertNotSame($oldToken, $appointment->public_token);

        // The OLD cancel link (built from the old token) must now 404 —
        // no appointment exists with that stale token any more.
        $oldPath = parse_url($oldCancelUrl, PHP_URL_PATH) . '?' . parse_url($oldCancelUrl, PHP_URL_QUERY);
        $this->getJson($oldPath)->assertStatus(404);
    }

    // ── Reminder scheduling ──────────────────────────────────────────────────

    public function test_reminder_command_sends_due_reminder_and_records_it(): void
    {
        $this->fakeBrevo();
        $admin = $this->makeUser('Admin');
        SuresignSetting::instance()->update(['appointment_reminder_offsets_minutes' => [1440, 60]]);

        $appointment = $this->makeAppointment([
            'assigned_user_id' => $admin->id,
            'starts_at' => now()->addMinutes(50), 'ends_at' => now()->addMinutes(80),
            'status' => 'confirmed',
        ]);

        Artisan::call('suresign:send-appointment-reminders');

        $this->assertDatabaseHas('appointment_reminder_sends', [
            'appointment_id' => $appointment->id, 'offset_minutes' => 60, 'status' => 'sent',
        ]);
        Http::assertSent(fn ($request) => str_contains($request->data()['subject'] ?? '', 'Reminder'));
    }

    public function test_reminder_command_dispatches_queued_job_not_direct_send(): void
    {
        Bus::fake();
        $admin = $this->makeUser('Admin');
        SuresignSetting::instance()->update(['appointment_reminder_offsets_minutes' => [60]]);
        $appointment = $this->makeAppointment(['assigned_user_id' => $admin->id, 'starts_at' => now()->addMinutes(50), 'ends_at' => now()->addMinutes(80), 'status' => 'confirmed']);

        Artisan::call('suresign:send-appointment-reminders');

        // The row is claimed (pending) synchronously by the command itself —
        // sending is deferred entirely to the queued job.
        $send = AppointmentReminderSend::where('appointment_id', $appointment->id)->where('offset_minutes', 60)->first();
        $this->assertNotNull($send);
        $this->assertSame('pending', $send->status);

        Bus::assertDispatched(SendAppointmentEmailJob::class, fn ($job) => $job->kind === 'reminder'
            && $job->appointmentId === $appointment->id
            && $job->context['offset_minutes'] === 60
            && $job->context['reminder_send_id'] === $send->id);
    }

    public function test_reminder_job_failure_marks_reminder_send_row_failed(): void
    {
        $admin = $this->makeUser('Admin');
        $appointment = $this->makeAppointment(['assigned_user_id' => $admin->id, 'status' => 'confirmed']);
        $send = AppointmentReminderSend::create([
            'appointment_id' => $appointment->id, 'offset_minutes' => 60,
            'schedule_version' => $appointment->schedule_version ?? 0, 'scheduled_for' => now(), 'status' => 'pending',
        ]);

        $job = new SendAppointmentEmailJob($appointment->id, 'reminder', ['offset_minutes' => 60, 'reminder_send_id' => $send->id]);
        $job->failed(new \RuntimeException('Brevo unreachable'));

        $send->refresh();
        $this->assertSame('failed', $send->status);
        $this->assertSame('Brevo unreachable', $send->failure_message);
    }

    public function test_reminder_job_success_marks_reminder_send_row_sent(): void
    {
        $this->fakeBrevo();
        $admin = $this->makeUser('Admin');
        $appointment = $this->makeAppointment(['assigned_user_id' => $admin->id, 'status' => 'confirmed']);
        $send = AppointmentReminderSend::create([
            'appointment_id' => $appointment->id, 'offset_minutes' => 60,
            'schedule_version' => $appointment->schedule_version ?? 0, 'scheduled_for' => now(), 'status' => 'pending',
        ]);

        $job = new SendAppointmentEmailJob($appointment->id, 'reminder', ['offset_minutes' => 60, 'reminder_send_id' => $send->id]);
        $job->handle(app(AppointmentEmailService::class));

        $send->refresh();
        $this->assertSame('sent', $send->status);
        $this->assertNotNull($send->sent_at);
    }

    public function test_reminder_command_does_not_duplicate_on_rerun(): void
    {
        $this->fakeBrevo();
        $admin = $this->makeUser('Admin');
        SuresignSetting::instance()->update(['appointment_reminder_offsets_minutes' => [60]]);
        $this->makeAppointment(['assigned_user_id' => $admin->id, 'starts_at' => now()->addMinutes(50), 'ends_at' => now()->addMinutes(80), 'status' => 'confirmed']);

        Artisan::call('suresign:send-appointment-reminders');
        Artisan::call('suresign:send-appointment-reminders');

        $this->assertDatabaseCount('appointment_reminder_sends', 1);
    }

    public function test_reminder_not_sent_for_cancelled_declined_completed_or_no_show(): void
    {
        $this->fakeBrevo();
        $admin = $this->makeUser('Admin');
        SuresignSetting::instance()->update(['appointment_reminder_offsets_minutes' => [60]]);

        foreach (['cancelled', 'declined', 'completed', 'no_show'] as $status) {
            $this->makeAppointment(['assigned_user_id' => $admin->id, 'starts_at' => now()->addMinutes(50), 'ends_at' => now()->addMinutes(80), 'status' => $status]);
        }

        Artisan::call('suresign:send-appointment-reminders');

        $this->assertDatabaseCount('appointment_reminder_sends', 0);
    }

    public function test_reminders_disabled_setting_suppresses_all_reminders(): void
    {
        $this->fakeBrevo();
        $admin = $this->makeUser('Admin');
        SuresignSetting::instance()->update(['appointment_reminders_enabled' => false, 'appointment_reminder_offsets_minutes' => [60]]);
        $this->makeAppointment(['assigned_user_id' => $admin->id, 'starts_at' => now()->addMinutes(50), 'ends_at' => now()->addMinutes(80), 'status' => 'confirmed']);

        Artisan::call('suresign:send-appointment-reminders');

        $this->assertDatabaseCount('appointment_reminder_sends', 0);
    }

    public function test_reschedule_makes_reminders_due_again_via_schedule_version(): void
    {
        $this->fakeBrevo();
        $admin = $this->makeUser('Admin');
        $this->grantOpenAvailability($admin);
        SuresignSetting::instance()->update(['appointment_reminder_offsets_minutes' => [60]]);

        $appointment = $this->makeAppointment(['assigned_user_id' => $admin->id, 'starts_at' => now()->addMinutes(50), 'ends_at' => now()->addMinutes(80), 'status' => 'confirmed', 'schedule_version' => 0]);
        Artisan::call('suresign:send-appointment-reminders');
        $this->assertDatabaseHas('appointment_reminder_sends', ['appointment_id' => $appointment->id, 'schedule_version' => 0]);

        // Reschedule to another time still within the 60-min reminder window.
        // Rounded to a clean minute boundary with margin so H:i's
        // minute-granularity truncation can never land it in the past
        // relative to the real current instant (min_notice_hours is 0 here).
        Sanctum::actingAs($this->makeUser('Super Admin'));
        // 'UTC' here (not 'Europe/London') deliberately matches now()'s own
        // default app timezone — this environment runs UTC, and July is
        // BST (UTC+1) for Europe/London, so labelling a UTC clock reading
        // as Europe/London would silently shift the instant an hour
        // earlier, which is exactly the kind of bug this test is not
        // trying to exercise.
        $newStart = now()->addMinutes(45)->startOfMinute()->addMinute();
        $this->postJson("/api/appointments/{$appointment->id}/reschedule", [
            'date' => $newStart->toDateString(), 'start_time' => $newStart->format('H:i'), 'timezone' => 'UTC',
        ])->assertStatus(200);

        Artisan::call('suresign:send-appointment-reminders');

        $this->assertDatabaseHas('appointment_reminder_sends', ['appointment_id' => $appointment->id, 'schedule_version' => 1]);
        $this->assertDatabaseCount('appointment_reminder_sends', 2);
    }

    // ── Settings validation ──────────────────────────────────────────────────

    public function test_appointment_settings_reject_duplicate_reminder_offsets(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        Sanctum::actingAs($superAdmin);

        $response = $this->putJson('/api/admin/suresign-settings/appointments', [
            'appointment_reminder_offsets_minutes' => [60, 60],
        ]);
        $response->assertStatus(422);
    }

    public function test_appointment_settings_reject_out_of_bound_offsets(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        Sanctum::actingAs($superAdmin);

        $response = $this->putJson('/api/admin/suresign-settings/appointments', [
            'appointment_reminder_offsets_minutes' => [99999],
        ]);
        $response->assertStatus(422);
    }

    public function test_appointment_settings_save_successfully(): void
    {
        $superAdmin = $this->makeUser('Super Admin');
        Sanctum::actingAs($superAdmin);

        $response = $this->putJson('/api/admin/suresign-settings/appointments', [
            'appointment_reminders_enabled' => true,
            'appointment_reminder_offsets_minutes' => [1440, 60],
            'appointment_cancel_link_ttl_hours' => 500,
            'appointment_reschedule_link_ttl_hours' => 500,
            'appointment_cancellation_cutoff_hours' => 3,
            'appointment_reschedule_cutoff_hours' => 3,
            'appointment_ics_enabled' => true,
            'appointment_default_meeting_instructions' => 'Please join 5 minutes early.',
        ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('suresign_settings', ['appointment_cancellation_cutoff_hours' => 3]);
    }

    public function test_admin_can_manage_appointment_settings_matching_existing_suresign_settings_convention(): void
    {
        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        // suresign-settings/* is role:Super Admin|Admin per existing convention
        // (unlike Appointment Types, which are deliberately Super-Admin-only) —
        // this endpoint deliberately follows the platform-settings precedent,
        // not the Appointment Types one.
        $response = $this->putJson('/api/admin/suresign-settings/appointments', [
            'appointment_reminders_enabled' => false,
        ]);
        $response->assertStatus(200);
    }
}
