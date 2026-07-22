<?php

namespace Tests\Feature;

use App\Models\AppointmentAvailability;
use App\Models\AppointmentBlockedPeriod;
use App\Models\AppointmentType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Appointments & Scheduling — Phase 3 (Public Booking).
 */
class AppointmentsPhase3PublicBookingTest extends TestCase
{
    use RefreshDatabase;

    private function makeStaff(): User
    {
        static $n = 0;
        $n++;
        $org = Organization::create(['name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));

        return $user;
    }

    private function grantOpenAvailability(User $staff): void
    {
        for ($weekday = 0; $weekday <= 6; $weekday++) {
            AppointmentAvailability::create([
                'user_id' => $staff->id, 'weekday' => $weekday,
                'start_time' => '09:00', 'end_time' => '17:00', 'is_active' => true,
            ]);
        }
    }

    private function makeType(array $overrides = []): AppointmentType
    {
        static $n = 0;
        $n++;

        return AppointmentType::create(array_merge([
            'name' => "Public Type {$n}", 'slug' => "public-type-{$n}",
            'duration_minutes' => 30, 'is_active' => true, 'is_public' => true,
            'assignment_mode' => 'manual', 'meeting_method' => 'tbc', 'requires_confirmation' => false,
            'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'min_notice_hours' => 0, 'max_advance_days' => 60,
        ], $overrides));
    }

    private function nextDateForWeekday(int $weekday): string
    {
        $date = now()->addDays(3);
        while ($date->dayOfWeek !== $weekday) {
            $date = $date->addDay();
        }
        return $date->toDateString();
    }

    private function publicPayload(AppointmentType $type, string $date, string $startTime, array $overrides = []): array
    {
        return array_merge([
            'appointment_type_slug' => $type->slug,
            'attendee_name'  => 'Jane Prospect',
            'attendee_email' => 'jane@prospect.example.com',
            'attendee_timezone' => 'Europe/London',
            'date'       => $date,
            'start_time' => $startTime,
            'timezone'   => 'Europe/London',
            'consent'    => true,
        ], $overrides);
    }

    // ── Type visibility ────────────────────────────────────────────────────

    public function test_public_type_info_is_visible(): void
    {
        $type = $this->makeType();
        $response = $this->getJson("/api/public/appointment-types/{$type->slug}");
        $response->assertStatus(200)->assertJsonPath('slug', $type->slug);
    }

    public function test_non_public_type_returns_generic_404(): void
    {
        $type = $this->makeType(['is_public' => false]);
        $this->getJson("/api/public/appointment-types/{$type->slug}")->assertStatus(404);
    }

    public function test_inactive_type_returns_generic_404(): void
    {
        $type = $this->makeType(['is_active' => false]);
        $this->getJson("/api/public/appointment-types/{$type->slug}")->assertStatus(404);
    }

    public function test_unknown_slug_returns_the_same_404_as_a_private_type(): void
    {
        $privateType = $this->makeType(['is_public' => false]);
        $unknown = $this->getJson('/api/public/appointment-types/does-not-exist');
        $private = $this->getJson("/api/public/appointment-types/{$privateType->slug}");

        $unknown->assertStatus(404);
        $private->assertStatus(404);
        $this->assertSame($unknown->json(), $private->json());
    }

    // ── Manual vs fixed scheduling mode ─────────────────────────────────────

    public function test_manual_mode_type_reports_manual_scheduling_and_no_slots(): void
    {
        $type = $this->makeType(['assignment_mode' => 'manual']);
        $info = $this->getJson("/api/public/appointment-types/{$type->slug}");
        $info->assertStatus(200)->assertJsonPath('scheduling_mode', 'manual');

        $slots = $this->getJson("/api/public/appointment-types/{$type->slug}/slots?date=" . now()->addDays(3)->toDateString());
        $slots->assertStatus(200)->assertJsonPath('scheduling_mode', 'manual')->assertJsonPath('slots', []);
    }

    public function test_fixed_mode_type_with_eligible_staff_generates_real_slots(): void
    {
        $staff = $this->makeStaff();
        $this->grantOpenAvailability($staff);
        $type = $this->makeType(['assignment_mode' => 'fixed', 'default_assigned_user_id' => $staff->id]);
        $date = $this->nextDateForWeekday(1);

        $response = $this->getJson("/api/public/appointment-types/{$type->slug}/slots?date={$date}");
        $response->assertStatus(200)->assertJsonPath('scheduling_mode', 'fixed');
        $this->assertNotEmpty($response->json('slots'));
        $this->assertContains(['date' => $date, 'time' => '09:00'], $response->json('slots'));
    }

    public function test_fixed_mode_falls_back_to_manual_when_default_assignee_is_ineligible(): void
    {
        $staff = $this->makeStaff();
        $staff->update(['banned_at' => now()]);
        $type = $this->makeType(['assignment_mode' => 'fixed', 'default_assigned_user_id' => $staff->id]);

        $info = $this->getJson("/api/public/appointment-types/{$type->slug}");
        $info->assertStatus(200)->assertJsonPath('scheduling_mode', 'manual');
    }

    public function test_slots_exclude_already_booked_and_buffered_time(): void
    {
        $staff = $this->makeStaff();
        $this->grantOpenAvailability($staff);
        $type = $this->makeType([
            'assignment_mode' => 'fixed', 'default_assigned_user_id' => $staff->id,
            'duration_minutes' => 30, 'buffer_before_minutes' => 15, 'buffer_after_minutes' => 15,
        ]);
        $date = $this->nextDateForWeekday(2);

        $this->postJson("/api/public/appointment-types/{$type->slug}/book", $this->publicPayload($type, $date, '10:00'))
            ->assertStatus(201);

        $slots = $this->getJson("/api/public/appointment-types/{$type->slug}/slots?date={$date}");
        $slots->assertStatus(200);
        $times = array_column($slots->json('slots'), 'time');
        // Existing 10:00-10:30 with buffer 15/15 -> effective 09:45-10:45.
        // A candidate slot carries the SAME type's own 15-minute
        // buffer_before, so its effective interval starts 15 minutes before
        // its own start_time — the first candidate whose effective start
        // (candidate_start - 15) reaches 10:45 is 11:00.
        $this->assertNotContains('09:45', $times);
        $this->assertNotContains('10:00', $times);
        $this->assertNotContains('10:15', $times);
        $this->assertNotContains('10:45', $times);
        $this->assertContains('11:00', $times);
        $this->assertContains('09:00', $times);
    }

    // ── Monthly availability (public booking calendar) ──────────────────────

    public function test_availability_endpoint_reports_manual_scheduling_and_no_dates(): void
    {
        $type = $this->makeType(['assignment_mode' => 'manual']);
        $now = now();

        $response = $this->getJson("/api/public/appointment-types/{$type->slug}/availability?year={$now->year}&month={$now->month}");
        $response->assertStatus(200)->assertJsonPath('scheduling_mode', 'manual')->assertJsonPath('dates', []);
    }

    public function test_availability_endpoint_lists_bookable_dates_for_fixed_mode(): void
    {
        $staff = $this->makeStaff();
        $this->grantOpenAvailability($staff);
        $type = $this->makeType(['assignment_mode' => 'fixed', 'default_assigned_user_id' => $staff->id]);
        $date = $this->nextDateForWeekday(1);
        $year = (int) substr($date, 0, 4);
        $month = (int) substr($date, 5, 2);

        $response = $this->getJson("/api/public/appointment-types/{$type->slug}/availability?year={$year}&month={$month}");
        $response->assertStatus(200)->assertJsonPath('scheduling_mode', 'fixed');
        $this->assertContains($date, $response->json('dates'));
    }

    public function test_availability_endpoint_excludes_a_fully_blocked_day(): void
    {
        $staff = $this->makeStaff();
        $this->grantOpenAvailability($staff);
        $type = $this->makeType(['assignment_mode' => 'fixed', 'default_assigned_user_id' => $staff->id]);
        $date = $this->nextDateForWeekday(3);
        $year = (int) substr($date, 0, 4);
        $month = (int) substr($date, 5, 2);

        AppointmentBlockedPeriod::create([
            'user_id' => $staff->id,
            'starts_at' => "{$date} 00:00:00",
            'ends_at' => "{$date} 23:59:59",
            'timezone' => 'Europe/London',
            'reason' => 'All-day block for test',
        ]);

        $response = $this->getJson("/api/public/appointment-types/{$type->slug}/availability?year={$year}&month={$month}");
        $response->assertStatus(200);
        $this->assertNotContains($date, $response->json('dates'));
    }

    public function test_availability_endpoint_requires_year_and_month(): void
    {
        $type = $this->makeType();
        $this->getJson("/api/public/appointment-types/{$type->slug}/availability")->assertStatus(422);
    }

    public function test_availability_endpoint_unknown_slug_returns_generic_404(): void
    {
        $now = now();
        $this->getJson("/api/public/appointment-types/does-not-exist/availability?year={$now->year}&month={$now->month}")
            ->assertStatus(404);
    }

    // ── Visitor-timezone-aware slot labels (production hardening) ───────────
    //
    // Slots are always GENERATED against the staff member's own availability
    // window in their own timezone (unchanged) — these tests only cover the
    // PRESENTATION conversion: which timezone a bookable slot's date/time is
    // labelled under, and that the underlying canonical UTC instant never
    // changes because of it.

    public function test_slots_are_labelled_in_staff_timezone_when_no_visitor_timezone_given(): void
    {
        $staff = $this->makeStaff();
        $this->grantOpenAvailability($staff);
        $type = $this->makeType(['assignment_mode' => 'fixed', 'default_assigned_user_id' => $staff->id]);
        $date = $this->nextDateForWeekday(1);

        $response = $this->getJson("/api/public/appointment-types/{$type->slug}/slots?date={$date}");
        $response->assertStatus(200)->assertJsonPath('timezone', 'Europe/London');
        $this->assertContains(['date' => $date, 'time' => '09:00'], $response->json('slots'));
    }

    public function test_slots_are_labelled_in_the_visitors_timezone_when_provided(): void
    {
        $staff = $this->makeStaff(); // Europe/London
        $this->grantOpenAvailability($staff);
        $type = $this->makeType(['assignment_mode' => 'fixed', 'default_assigned_user_id' => $staff->id]);
        $date = $this->nextDateForWeekday(1);

        // Mid-summer: Europe/London is BST (UTC+1). Asia/Manila is UTC+8
        // year-round (no DST) — a constant +7h difference.
        $response = $this->getJson("/api/public/appointment-types/{$type->slug}/slots?date={$date}&timezone=Asia/Manila");
        $response->assertStatus(200)->assertJsonPath('timezone', 'Asia/Manila');
        // Staff-local 09:00 BST -> 08:00 UTC -> 16:00 Manila, same calendar date.
        $this->assertContains(['date' => $date, 'time' => '16:00'], $response->json('slots'));
    }

    public function test_slot_label_shifts_to_the_next_calendar_day_for_a_far_ahead_timezone(): void
    {
        $staff = $this->makeStaff(); // Europe/London
        $this->grantOpenAvailability($staff);
        $type = $this->makeType(['assignment_mode' => 'fixed', 'default_assigned_user_id' => $staff->id]);
        $date = $this->nextDateForWeekday(2);
        $nextDate = now()->parse($date)->addDay()->toDateString();

        // Pacific/Auckland is far enough ahead of Europe/London (BST) that
        // the STAFF's own late-afternoon slots land on the NEXT calendar day
        // for the visitor.
        $response = $this->getJson("/api/public/appointment-types/{$type->slug}/slots?date={$date}&timezone=Pacific/Auckland");
        $response->assertStatus(200);
        $slots = $response->json('slots');

        $shifted = array_filter($slots, fn (array $s) => $s['date'] === $nextDate);
        $this->assertNotEmpty($shifted, 'Expected at least one slot to shift onto the next calendar day for a far-ahead timezone.');
    }

    public function test_slot_label_shifts_to_the_previous_calendar_day_for_a_far_behind_timezone(): void
    {
        $staff = $this->makeStaff(); // Europe/London
        $this->grantOpenAvailability($staff);
        $type = $this->makeType(['assignment_mode' => 'fixed', 'default_assigned_user_id' => $staff->id]);
        $date = $this->nextDateForWeekday(2);
        $previousDate = now()->parse($date)->subDay()->toDateString();

        // Pacific/Niue (UTC-11) is far enough behind Europe/London (BST) that
        // the STAFF's own early-morning slots land on the PREVIOUS calendar
        // day for the visitor.
        $response = $this->getJson("/api/public/appointment-types/{$type->slug}/slots?date={$date}&timezone=Pacific/Niue");
        $response->assertStatus(200);
        $slots = $response->json('slots');

        $shifted = array_filter($slots, fn (array $s) => $s['date'] === $previousDate);
        $this->assertNotEmpty($shifted, 'Expected at least one slot to shift onto the previous calendar day for a far-behind timezone.');
    }

    public function test_availability_endpoint_reflects_visitor_timezone_shift_across_a_month_boundary(): void
    {
        $staff = $this->makeStaff();
        $this->grantOpenAvailability($staff);
        $type = $this->makeType([
            'assignment_mode' => 'fixed', 'default_assigned_user_id' => $staff->id,
            'max_advance_days' => 120,
        ]);

        $lastDayOfMonth = now('Europe/London')->addMonth()->endOfMonth();
        $firstOfNextMonth = $lastDayOfMonth->copy()->addDay();

        // The staff's last day of the month, viewed from far-ahead
        // Pacific/Auckland, should contribute at least one bookable date to
        // the FOLLOWING month's availability view (the visitor's own late
        // slots on that staff-day fall on the 1st, not the 30th/31st).
        $response = $this->getJson(
            "/api/public/appointment-types/{$type->slug}/availability"
            . "?year={$firstOfNextMonth->year}&month={$firstOfNextMonth->month}&timezone=Pacific/Auckland"
        );
        $response->assertStatus(200);
        $this->assertContains($firstOfNextMonth->toDateString(), $response->json('dates'));
    }

    public function test_booking_submission_using_the_returned_slot_value_matches_the_canonical_utc_instant(): void
    {
        $staff = $this->makeStaff(); // Europe/London
        $this->grantOpenAvailability($staff);
        $type = $this->makeType(['assignment_mode' => 'fixed', 'default_assigned_user_id' => $staff->id]);
        $date = $this->nextDateForWeekday(4);

        $slots = $this->getJson("/api/public/appointment-types/{$type->slug}/slots?date={$date}&timezone=Asia/Manila")
            ->json('slots');
        $chosen = $slots[0]; // ['date' => ..., 'time' => '16:00', ...] — staff-local 09:00 BST.

        $booking = $this->postJson("/api/public/appointment-types/{$type->slug}/book", $this->publicPayload(
            $type, $chosen['date'], $chosen['time'], ['timezone' => 'Asia/Manila', 'attendee_timezone' => 'Asia/Manila'],
        ));
        $booking->assertStatus(201);

        $appointment = \App\Models\Appointment::where('reference', $booking->json('reference'))->firstOrFail();
        // 16:00 Asia/Manila (UTC+8) must resolve to the SAME canonical UTC
        // instant as the staff's own 09:00 BST (UTC+1) — the visitor-timezone
        // label is purely presentational and must never shift what actually
        // gets stored/booked.
        $this->assertSame('08:00:00', $appointment->starts_at->copy()->setTimezone('UTC')->format('H:i:s'));
        $this->assertSame($date, $appointment->starts_at->copy()->setTimezone('Europe/London')->toDateString());
    }

    public function test_slot_label_conversion_is_dst_aware(): void
    {
        $staff = $this->makeStaff(); // Europe/London
        $this->grantOpenAvailability($staff);
        $type = $this->makeType([
            'assignment_mode' => 'fixed', 'default_assigned_user_id' => $staff->id,
            'max_advance_days' => 3650,
        ]);

        // Both fixed, far-future, non-relative dates so the test is
        // deterministic regardless of when the suite runs.
        // 2027-01-11: Europe/London is GMT (UTC+0); staff-local 14:00 -> UTC 14:00.
        // 2027-07-12: Europe/London is BST (UTC+1); staff-local 15:00 -> UTC 14:00.
        // Converting that SAME UTC instant to America/New_York must differ by
        // exactly the EST (UTC-5) vs EDT (UTC-4) DST offset — proving the
        // conversion isn't using a hardcoded fixed offset.
        $winter = $this->getJson("/api/public/appointment-types/{$type->slug}/slots?date=2027-01-11&timezone=America/New_York")->json('slots');
        $summer = $this->getJson("/api/public/appointment-types/{$type->slug}/slots?date=2027-07-12&timezone=America/New_York")->json('slots');

        $this->assertContains(['date' => '2027-01-11', 'time' => '09:00'], $winter);
        $this->assertContains(['date' => '2027-07-12', 'time' => '10:00'], $summer);
    }

    // ── Booking creation ────────────────────────────────────────────────────

    public function test_auto_confirm_type_creates_a_confirmed_appointment(): void
    {
        $type = $this->makeType(['requires_confirmation' => false]);
        $date = now()->addDays(3)->toDateString();

        $response = $this->postJson("/api/public/appointment-types/{$type->slug}/book", $this->publicPayload($type, $date, '10:00'));
        $response->assertStatus(201)->assertJsonPath('status', 'confirmed');
        $this->assertMatchesRegularExpression('/^APT-\d{6}$/', $response->json('reference'));
    }

    public function test_manual_confirmation_type_creates_a_requested_appointment(): void
    {
        $type = $this->makeType(['requires_confirmation' => true]);
        $date = now()->addDays(3)->toDateString();

        $response = $this->postJson("/api/public/appointment-types/{$type->slug}/book", $this->publicPayload($type, $date, '10:00'));
        $response->assertStatus(201)->assertJsonPath('status', 'requested');
    }

    public function test_booking_a_fixed_mode_type_assigns_the_configured_staff_member(): void
    {
        $staff = $this->makeStaff();
        $this->grantOpenAvailability($staff);
        $type = $this->makeType(['assignment_mode' => 'fixed', 'default_assigned_user_id' => $staff->id]);
        $date = $this->nextDateForWeekday(3);

        $response = $this->postJson("/api/public/appointment-types/{$type->slug}/book", $this->publicPayload($type, $date, '10:00'));
        $response->assertStatus(201);
        $this->assertDatabaseHas('appointments', ['reference' => $response->json('reference'), 'assigned_user_id' => $staff->id]);
    }

    public function test_booking_a_manual_mode_type_creates_an_unassigned_appointment(): void
    {
        $type = $this->makeType(['assignment_mode' => 'manual']);
        $date = now()->addDays(3)->toDateString();

        $response = $this->postJson("/api/public/appointment-types/{$type->slug}/book", $this->publicPayload($type, $date, '10:00'));
        $response->assertStatus(201);
        $this->assertDatabaseHas('appointments', ['reference' => $response->json('reference'), 'assigned_user_id' => null]);
    }

    public function test_response_does_not_leak_assigned_staff_or_internal_fields(): void
    {
        $staff = $this->makeStaff();
        $this->grantOpenAvailability($staff);
        $type = $this->makeType(['assignment_mode' => 'fixed', 'default_assigned_user_id' => $staff->id]);
        $date = $this->nextDateForWeekday(4);

        $response = $this->postJson("/api/public/appointment-types/{$type->slug}/book", $this->publicPayload($type, $date, '10:00'));
        $response->assertStatus(201);
        $json = $response->json();
        $this->assertArrayNotHasKey('assigned_user_id', $json);
        $this->assertArrayNotHasKey('assigned_user', $json);
        $this->assertArrayNotHasKey('internal_notes', $json);
        $this->assertArrayNotHasKey('id', $json);
    }

    public function test_cannot_book_a_non_public_or_inactive_type(): void
    {
        $privateType = $this->makeType(['is_public' => false]);
        $inactiveType = $this->makeType(['is_active' => false]);
        $date = now()->addDays(3)->toDateString();

        $this->postJson("/api/public/appointment-types/{$privateType->slug}/book", $this->publicPayload($privateType, $date, '10:00'))->assertStatus(404);
        $this->postJson("/api/public/appointment-types/{$inactiveType->slug}/book", $this->publicPayload($inactiveType, $date, '10:00'))->assertStatus(404);
    }

    public function test_double_booking_a_fixed_staff_member_via_public_form_is_rejected(): void
    {
        $staff = $this->makeStaff();
        $this->grantOpenAvailability($staff);
        $type = $this->makeType(['assignment_mode' => 'fixed', 'default_assigned_user_id' => $staff->id, 'duration_minutes' => 60]);
        $date = $this->nextDateForWeekday(5);

        $this->postJson("/api/public/appointment-types/{$type->slug}/book", $this->publicPayload($type, $date, '10:00'))->assertStatus(201);
        $response = $this->postJson("/api/public/appointment-types/{$type->slug}/book", $this->publicPayload($type, $date, '10:30'));
        $response->assertStatus(409);
    }

    public function test_minimum_notice_is_enforced_on_public_bookings(): void
    {
        $staff = $this->makeStaff();
        $this->grantOpenAvailability($staff);
        $type = $this->makeType(['assignment_mode' => 'fixed', 'default_assigned_user_id' => $staff->id, 'min_notice_hours' => 72]);
        $date = now()->addDay()->toDateString();

        $response = $this->postJson("/api/public/appointment-types/{$type->slug}/book", $this->publicPayload($type, $date, '10:00'));
        $response->assertStatus(409);
    }

    public function test_consent_is_required(): void
    {
        $type = $this->makeType();
        $date = now()->addDays(3)->toDateString();

        $response = $this->postJson("/api/public/appointment-types/{$type->slug}/book", $this->publicPayload($type, $date, '10:00', ['consent' => false]));
        $response->assertStatus(422);
    }

    // ── Security ────────────────────────────────────────────────────────────

    public function test_honeypot_field_silently_rejects_without_creating_a_record(): void
    {
        $type = $this->makeType();
        $date = now()->addDays(3)->toDateString();

        $response = $this->postJson("/api/public/appointment-types/{$type->slug}/book", $this->publicPayload($type, $date, '10:00', [
            'website' => 'http://spam.example.com',
        ]));
        $response->assertStatus(201);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_public_booking_endpoint_is_rate_limited(): void
    {
        $type = $this->makeType();
        $date = now()->addDays(3)->toDateString();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/public/appointment-types/{$type->slug}/book", $this->publicPayload($type, $date, sprintf('%02d:00', 8 + $i)));
        }

        $response = $this->postJson("/api/public/appointment-types/{$type->slug}/book", $this->publicPayload($type, $date, '15:00'));
        $response->assertStatus(429);
    }

    public function test_unknown_booking_source_falls_back_to_default(): void
    {
        $type = $this->makeType();
        $date = now()->addDays(3)->toDateString();

        $response = $this->postJson("/api/public/appointment-types/{$type->slug}/book", $this->publicPayload($type, $date, '10:00', [
            'source' => 'some_untrusted_value',
        ]));
        $response->assertStatus(201);
        $this->assertDatabaseHas('appointments', ['reference' => $response->json('reference'), 'booking_source' => 'public_booking_page']);
    }

    public function test_recognised_booking_source_is_stored_verbatim(): void
    {
        $type = $this->makeType();
        $date = now()->addDays(3)->toDateString();

        $response = $this->postJson("/api/public/appointment-types/{$type->slug}/book", $this->publicPayload($type, $date, '10:00', [
            'source' => 'marketing_homepage',
        ]));
        $response->assertStatus(201);
        $this->assertDatabaseHas('appointments', ['reference' => $response->json('reference'), 'booking_source' => 'marketing_homepage']);
    }

    public function test_activity_log_records_public_booking_with_no_actor(): void
    {
        $type = $this->makeType();
        $date = now()->addDays(3)->toDateString();

        $response = $this->postJson("/api/public/appointment-types/{$type->slug}/book", $this->publicPayload($type, $date, '10:00'));
        $response->assertStatus(201);
        $this->assertDatabaseHas('activity_logs', ['action' => 'appointment.created', 'user_id' => null]);
    }
}
