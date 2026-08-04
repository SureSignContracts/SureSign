<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Appointment;
use App\Models\AppointmentAvailability;
use App\Models\ConsultationEnquiry;
use App\Models\Organization;
use App\Models\User;
use App\Services\Consultancy\ConsultancyCatalogueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Consultancy — Phase C2, Batch 6A (Operator Dashboard). See
 * internal-docs/commercial/suresign-consultancy-phase-c2-specification-v1.md §16.
 */
class ConsultancyPhaseC2Batch6ADashboardTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgAndUser(string $role): array
    {
        static $n = 0;
        $n++;
        $org = Organization::create(['name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

        return [$org, $user];
    }

    private function makeService(array $overrides = []): \App\Models\ConsultancyService
    {
        static $n = 0;
        $n++;

        return app(ConsultancyCatalogueService::class)->create(array_merge([
            'code'                             => "batch6a-service-{$n}",
            'display_name'                     => "Batch 6A Service {$n}",
            'enabled'                          => true,
            'publicly_bookable'                => true,
            'available_to_existing_customers'  => true,
            'price_minor_units'                => 4000,
            'currency'                         => 'GBP',
            'duration_minutes'                 => 30,
            'requires_confirmation'            => false,
            'assignment_mode'                  => 'manual',
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

    /** Books a consultation for $org's own Client, assigned (or not) to $assignee. */
    private function makeConsultation(Organization $org, ?User $assignee, int $weekday): ConsultationEnquiry
    {
        static $n = 0;
        $n++;

        if ($assignee) {
            AppointmentAvailability::create([
                'user_id' => $assignee->id, 'context' => \App\Support\Appointments\AvailabilityContext::CONSULTANCY, 'weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '17:00', 'is_active' => true,
            ]);
            // Consultancy Live Booking Upgrade, Stage 1 — the consultant is
            // a platform-wide setting, not a per-service field. Every test
            // using this helper only ever passes one consistent $assignee
            // (never two different ones in the same test), so configuring
            // it here is safe and matches each test's own intent.
            \App\Models\SuresignSetting::instance()->update(['consultancy_consultant_user_id' => $assignee->id]);
        }
        $service = $this->makeService();
        $client = User::factory()->create(['organization_id' => $org->id]);
        $client->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        $date = $this->nextDateForWeekday($weekday);

        $booking = $this->actingAs($client)->postJson('/api/consultations', [
            'consultancy_service_code' => $service->code,
            'attendee_name'      => "Client {$n}",
            'attendee_email'     => "client{$n}@example.com",
            'attendee_timezone'  => 'Europe/London',
            'date'               => $date,
            'start_time'         => '10:00',
            'timezone'           => 'Europe/London',
            'title'              => 'A query',
            'description'        => 'A description.',
        ])->assertStatus(201)->json();

        return ConsultationEnquiry::where('appointment_id', $booking['id'])->firstOrFail();
    }

    public function test_unauthenticated_and_client_roles_are_denied(): void
    {
        [, $client] = $this->makeOrgAndUser('Client');
        $this->actingAs($client)->getJson('/api/admin/consultancy/dashboard')->assertStatus(403);
    }

    public function test_totals_count_correctly_across_engagement_statuses(): void
    {
        [$org, $admin] = $this->makeOrgAndUser('Admin');

        $awaitingConsultant = $this->makeConsultation($org, null, 0); // manual assignment -> stays awaiting_consultant, unassigned
        $awaitingCustomer = $this->makeConsultation($org, $admin, 1);
        $awaitingCustomer->update(['engagement_status' => 'awaiting_customer']);

        $response = $this->actingAs($admin)->getJson('/api/admin/consultancy/dashboard');

        $response->assertStatus(200);
        $totals = $response->json('totals');
        $this->assertSame(2, $totals['all']);
        $this->assertSame(1, $totals['awaiting_consultant']);
        $this->assertSame(1, $totals['awaiting_customer']);
        $this->assertSame(0, $totals['completed']);
        $this->assertSame(0, $totals['cancelled']);
    }

    public function test_unassigned_count_reflects_appointments_with_no_assigned_user(): void
    {
        [$org, $admin] = $this->makeOrgAndUser('Admin');

        $this->makeConsultation($org, null, 2); // manual assignment_mode -> unassigned
        $this->makeConsultation($org, $admin, 3); // fixed -> assigned to $admin

        $response = $this->actingAs($admin)->getJson('/api/admin/consultancy/dashboard');

        $this->assertSame(1, $response->json('totals.unassigned'));
    }

    public function test_totals_are_platform_wide_across_organisations_matching_queue_visibility(): void
    {
        [$orgA, $adminA] = $this->makeOrgAndUser('Admin');
        [$orgB] = $this->makeOrgAndUser('Client');

        $this->makeConsultation($orgA, null, 4);
        $this->makeConsultation($orgB, null, 5);

        $response = $this->actingAs($adminA)->getJson('/api/admin/consultancy/dashboard');

        // Matches ConsultancyOperationsController::index()'s own confirmed
        // platform-wide visibility rule — an Admin sees both organisations'
        // counts, not just their own.
        $this->assertSame(2, $response->json('totals.all'));
    }

    public function test_ageing_buckets_are_derived_from_activity_log_not_updated_at(): void
    {
        [$org, $admin] = $this->makeOrgAndUser('Admin');

        $under3 = $this->makeConsultation($org, $admin, 0);
        $under3->update(['engagement_status' => 'awaiting_customer']);
        ActivityLog::where('subject_id', $under3->appointment_id)->delete();
        ActivityLog::create([
            'action' => 'consultation.engagement_status_changed', 'description' => 'x',
            'subject_type' => Appointment::class, 'subject_id' => $under3->appointment_id,
            'metadata' => ['from' => 'awaiting_consultant', 'to' => 'awaiting_customer'],
        ])->forceFill(['created_at' => now()->subHours(12)])->save();

        $midRange = $this->makeConsultation($org, $admin, 1);
        $midRange->update(['engagement_status' => 'awaiting_customer']);
        ActivityLog::where('subject_id', $midRange->appointment_id)->delete();
        ActivityLog::create([
            'action' => 'consultation.engagement_status_changed', 'description' => 'x',
            'subject_type' => Appointment::class, 'subject_id' => $midRange->appointment_id,
            'metadata' => ['from' => 'awaiting_consultant', 'to' => 'awaiting_customer'],
        ])->forceFill(['created_at' => now()->subDays(5)])->save();

        $overdue = $this->makeConsultation($org, $admin, 2);
        $overdue->update(['engagement_status' => 'awaiting_customer']);
        ActivityLog::where('subject_id', $overdue->appointment_id)->delete();
        ActivityLog::create([
            'action' => 'consultation.engagement_status_changed', 'description' => 'x',
            'subject_type' => Appointment::class, 'subject_id' => $overdue->appointment_id,
            'metadata' => ['from' => 'awaiting_consultant', 'to' => 'awaiting_customer'],
        ])->forceFill(['created_at' => now()->subDays(10)])->save();

        // A record whose updated_at is fresh (simulating an unrelated notes
        // edit) must NOT be treated as "recently transitioned" — proves the
        // ageing metric ignores updated_at entirely.
        $overdue->touch();

        $response = $this->actingAs($admin)->getJson('/api/admin/consultancy/dashboard');
        $attention = $response->json('attention');

        $this->assertSame(1, $attention['awaiting_customer_under_3_days']);
        $this->assertSame(1, $attention['awaiting_customer_3_to_7_days']);
        $this->assertSame(1, $attention['awaiting_customer_over_7_days']);
        $this->assertSame(0, $attention['awaiting_customer_unknown_age']);
    }

    public function test_legacy_awaiting_customer_with_no_matching_activity_log_event_is_unknown_age(): void
    {
        [$org, $admin] = $this->makeOrgAndUser('Admin');

        $legacy = $this->makeConsultation($org, $admin, 3);
        $legacy->update(['engagement_status' => 'awaiting_customer']);
        // Simulate older/migrated data: no matching
        // 'consultation.engagement_status_changed' ActivityLog row exists
        // for this transition at all.
        ActivityLog::where('subject_id', $legacy->appointment_id)->delete();

        $response = $this->actingAs($admin)->getJson('/api/admin/consultancy/dashboard');
        $attention = $response->json('attention');

        $this->assertSame(1, $attention['awaiting_customer_unknown_age']);
        $this->assertSame(0, $attention['awaiting_customer_under_3_days']);
        $this->assertSame(0, $attention['awaiting_customer_3_to_7_days']);
        $this->assertSame(0, $attention['awaiting_customer_over_7_days']);
    }

    public function test_ageing_ignores_activity_log_rows_transitioning_to_a_different_status(): void
    {
        [$org, $admin] = $this->makeOrgAndUser('Admin');

        $enquiry = $this->makeConsultation($org, $admin, 4);
        $enquiry->update(['engagement_status' => 'awaiting_customer']);
        ActivityLog::where('subject_id', $enquiry->appointment_id)->delete();
        // Only a stale awaiting_consultant transition exists (e.g. its
        // matching "to: awaiting_customer" row was somehow never written) —
        // must not be mistaken for the awaiting_customer transition.
        ActivityLog::create([
            'action' => 'consultation.engagement_status_changed', 'description' => 'x',
            'subject_type' => Appointment::class, 'subject_id' => $enquiry->appointment_id,
            'metadata' => ['from' => 'awaiting_customer', 'to' => 'awaiting_consultant'],
        ])->forceFill(['created_at' => now()->subHour()])->save();

        $response = $this->actingAs($admin)->getJson('/api/admin/consultancy/dashboard');
        $this->assertSame(1, $response->json('attention.awaiting_customer_unknown_age'));
    }

    public function test_recent_created_last_7_days_counts_appointment_creation(): void
    {
        [$org, $admin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeConsultation($org, $admin, 5);
        $enquiry->appointment->forceFill(['created_at' => now()->subDays(10)])->save();

        $enquiry2 = $this->makeConsultation($org, $admin, 6);
        // freshly created, within last 7 days by default

        $response = $this->actingAs($admin)->getJson('/api/admin/consultancy/dashboard');
        $this->assertSame(1, $response->json('recent.created_last_7_days'));
    }

    public function test_recent_completed_last_7_days_is_derived_from_activity_log_and_deduplicated(): void
    {
        [$org, $admin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeConsultation($org, $admin, 0);

        // Two completion events for the SAME consultation within the
        // window (completed, reopened, completed again) — must count once.
        ActivityLog::create([
            'action' => 'consultation.engagement_status_changed', 'description' => 'x',
            'subject_type' => Appointment::class, 'subject_id' => $enquiry->appointment_id,
            'metadata' => ['from' => 'awaiting_customer', 'to' => 'completed'],
        ])->forceFill(['created_at' => now()->subDays(1)])->save();
        ActivityLog::create([
            'action' => 'consultation.engagement_status_changed', 'description' => 'x',
            'subject_type' => Appointment::class, 'subject_id' => $enquiry->appointment_id,
            'metadata' => ['from' => 'awaiting_customer', 'to' => 'completed'],
        ])->forceFill(['created_at' => now()->subHours(2)])->save();

        // An older completion outside the 7-day window for a different consultation.
        $old = $this->makeConsultation($org, $admin, 1);
        ActivityLog::create([
            'action' => 'consultation.engagement_status_changed', 'description' => 'x',
            'subject_type' => Appointment::class, 'subject_id' => $old->appointment_id,
            'metadata' => ['from' => 'awaiting_customer', 'to' => 'completed'],
        ])->forceFill(['created_at' => now()->subDays(9)])->save();

        $response = $this->actingAs($admin)->getJson('/api/admin/consultancy/dashboard');
        $this->assertSame(1, $response->json('recent.completed_last_7_days'));
    }

    public function test_queue_unassigned_filter_matches_dashboard_unassigned_total(): void
    {
        [$org, $admin] = $this->makeOrgAndUser('Admin');
        $this->makeConsultation($org, null, 0); // manual -> unassigned
        $this->makeConsultation($org, $admin, 1); // fixed -> assigned

        $response = $this->actingAs($admin)->getJson('/api/admin/consultancy/consultations?unassigned=1');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertNull($response->json('data.0.assigned_consultant'));
    }

    public function test_queue_overdue_awaiting_customer_filter_matches_dashboard_over_7_days_bucket(): void
    {
        [$org, $admin] = $this->makeOrgAndUser('Admin');

        $overdue = $this->makeConsultation($org, $admin, 2);
        $overdue->update(['engagement_status' => 'awaiting_customer']);
        ActivityLog::where('subject_id', $overdue->appointment_id)->delete();
        ActivityLog::create([
            'action' => 'consultation.engagement_status_changed', 'description' => 'x',
            'subject_type' => Appointment::class, 'subject_id' => $overdue->appointment_id,
            'metadata' => ['from' => 'awaiting_consultant', 'to' => 'awaiting_customer'],
        ])->forceFill(['created_at' => now()->subDays(10)])->save();

        $recent = $this->makeConsultation($org, $admin, 3);
        $recent->update(['engagement_status' => 'awaiting_customer']);
        ActivityLog::where('subject_id', $recent->appointment_id)->delete();
        ActivityLog::create([
            'action' => 'consultation.engagement_status_changed', 'description' => 'x',
            'subject_type' => Appointment::class, 'subject_id' => $recent->appointment_id,
            'metadata' => ['from' => 'awaiting_consultant', 'to' => 'awaiting_customer'],
        ])->forceFill(['created_at' => now()->subHours(2)])->save();

        $response = $this->actingAs($admin)->getJson('/api/admin/consultancy/consultations?overdue_awaiting_customer=1');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertSame([$overdue->appointment_id], $ids->all());

        $dashboard = $this->actingAs($admin)->getJson('/api/admin/consultancy/dashboard');
        $this->assertSame(1, $dashboard->json('attention.awaiting_customer_over_7_days'));
    }

    public function test_response_shape_matches_the_approved_contract(): void
    {
        [$org, $admin] = $this->makeOrgAndUser('Admin');
        $this->makeConsultation($org, $admin, 2);

        $response = $this->actingAs($admin)->getJson('/api/admin/consultancy/dashboard');

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing(['totals', 'attention', 'recent'], array_keys($response->json()));
        $this->assertEqualsCanonicalizing(
            ['all', 'awaiting_consultant', 'awaiting_customer', 'completed', 'cancelled', 'unassigned'],
            array_keys($response->json('totals')),
        );
        $this->assertEqualsCanonicalizing(
            ['awaiting_customer_under_3_days', 'awaiting_customer_3_to_7_days', 'awaiting_customer_over_7_days', 'awaiting_customer_unknown_age'],
            array_keys($response->json('attention')),
        );
        $this->assertEqualsCanonicalizing(['created_last_7_days', 'completed_last_7_days'], array_keys($response->json('recent')));
    }
}
