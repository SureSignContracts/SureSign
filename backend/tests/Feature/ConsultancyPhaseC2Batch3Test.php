<?php

namespace Tests\Feature;

use App\Models\ConsultancyService;
use App\Models\ConsultationEnquiry;
use App\Models\Organization;
use App\Models\User;
use App\Services\Consultancy\ConsultancyCatalogueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Consultancy — Phase C2, Batch 3 (Consultant Queue & Read-Only Operator
 * Workspace). See
 * internal-docs/commercial/suresign-consultancy-phase-c2-specification-v1.md §16.
 *
 * Strictly scoped to read-only operator access — no write action, no
 * dashboard/summary-count endpoint, exists yet.
 */
class ConsultancyPhaseC2Batch3Test extends TestCase
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

    private function makeService(array $overrides = []): ConsultancyService
    {
        static $n = 0;
        $n++;

        return app(ConsultancyCatalogueService::class)->create(array_merge([
            'code'                             => "batch3-service-{$n}",
            'display_name'                     => "Batch 3 Service {$n}",
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

    /**
     * Consultancy Live Booking Upgrade, Stage 1 — the Consultancy
     * consultant is a platform-wide setting, not a per-service field.
     */
    private function configureConsultant(User $staff): void
    {
        \App\Models\SuresignSetting::instance()->update(['consultancy_consultant_user_id' => $staff->id]);
    }

    private function nextDateForWeekday(int $weekday): string
    {
        $date = now()->addDays(3);
        while ($date->dayOfWeek !== $weekday) {
            $date = $date->addDay();
        }
        return $date->toDateString();
    }

    private function bookAsClient(User $client, ConsultancyService $service, int $weekday, array $overrides = []): array
    {
        $date = $this->nextDateForWeekday($weekday);

        return $this->actingAs($client)->postJson('/api/consultations', array_merge([
            'consultancy_service_code' => $service->code,
            'attendee_name'      => 'Jane Client',
            'attendee_email'     => 'jane@client.example.com',
            'attendee_timezone'  => 'Europe/London',
            'date'               => $date,
            'start_time'         => '10:00',
            'timezone'           => 'Europe/London',
            'title'              => 'A payment notice question',
            'description'        => 'We have a dispute over a pay less notice.',
        ], $overrides))->assertStatus(201)->json();
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    public function test_operator_response_contains_expected_operational_fields(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService();
        $booking = $this->bookAsClient($client, $service, 0);

        $enquiry = ConsultationEnquiry::where('appointment_id', $booking['id'])->firstOrFail();
        $enquiry->update(['internal_notes' => 'A private consultant note.']);

        $response = $this->actingAs($superAdmin)->getJson("/api/admin/consultancy/consultations/{$booking['id']}");
        $response->assertStatus(200);
        $data = $response->json();

        foreach (['id', 'reference', 'status', 'starts_at', 'ends_at', 'booking_timezone', 'created_at', 'updated_at', 'attendee_name', 'attendee_email', 'attendee_phone', 'attendee_company', 'attendee_job_title', 'organization', 'appointment_type', 'assigned_consultant', 'consultation_enquiry', 'activity', 'permissions'] as $key) {
            $this->assertArrayHasKey($key, $data, "Missing operator field: {$key}");
        }
        $this->assertSame('A private consultant note.', $data['consultation_enquiry']['internal_notes']);
        $this->assertSame('awaiting_consultant', $data['consultation_enquiry']['engagement_status']);
        $this->assertArrayHasKey('customer_summary_draft', $data['consultation_enquiry']);
        $this->assertArrayHasKey('customer_summary_needs_republish', $data['consultation_enquiry']);
    }

    /**
     * Confirmed real gap, fixed alongside the Communications Platform
     * theme work: the operator detail response never included Meet status
     * at all — ConsultationPresenter::operator()'s own docblock had gone
     * stale claiming Meet was still "unpopulated until C4" long after
     * Stage 4B actually shipped it. `show()` now appends
     * ConsultationMeetingPresenter::customerFacing() under a `meeting` key,
     * reusing the exact same customer-safe presenter the authenticated
     * customer page already uses.
     */
    public function test_operator_response_includes_meeting_status_and_join_url_when_available(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService();
        $booking = $this->bookAsClient($client, $service, 0);

        // Booking already queues Calendar sync, which creates its own
        // AppointmentExternalSync row (pending) — update it in place
        // rather than colliding with its unique constraint.
        \App\Models\AppointmentExternalSync::updateOrCreate(
            ['appointment_id' => $booking['id'], 'provider' => 'google', 'external_resource_type' => 'calendar_event'],
            [
                'state' => \App\Support\Google\CalendarSyncState::SYNCED,
                'meeting_state' => \App\Support\Google\MeetConferenceState::AVAILABLE,
                'provider_event_id' => 'evt_test',
                'meeting_join_url' => 'https://meet.google.com/abc-defg-hij',
                'correlation_key' => 'corr_test',
                'payload_version' => 'v1',
            ],
        );

        $response = $this->actingAs($superAdmin)->getJson("/api/admin/consultancy/consultations/{$booking['id']}");

        $response->assertStatus(200)
            ->assertJsonPath('meeting.status', 'available')
            ->assertJsonPath('meeting.join_url', 'https://meet.google.com/abc-defg-hij');
    }

    public function test_operator_response_reports_pending_meeting_status_with_no_url(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService();
        $booking = $this->bookAsClient($client, $service, 0);

        $response = $this->actingAs($superAdmin)->getJson("/api/admin/consultancy/consultations/{$booking['id']}");

        // No AppointmentExternalSync row created at all yet — the Calendar
        // sync job hasn't run — so this is the "temporarily_unavailable"
        // state, not "available", and never leaks a join URL that doesn't
        // exist.
        $response->assertStatus(200)
            ->assertJsonPath('meeting.join_url', null);
        $this->assertContains($response->json('meeting.status'), ['pending', 'temporarily_unavailable', 'unavailable']);
    }

    public function test_nested_relations_are_recursively_whitelisted_not_raw_models(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService();
        \App\Models\AppointmentAvailability::create([
            'user_id' => $staff->id, 'context' => \App\Support\Appointments\AvailabilityContext::CONSULTANCY, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00', 'is_active' => true,
        ]);
        $booking = $this->bookAsClient($client, $service, 1);

        $response = $this->actingAs($superAdmin)->getJson("/api/admin/consultancy/consultations/{$booking['id']}");
        $data = $response->json();

        // Assigned consultant: id + name only, never email/password/etc.
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($data['assigned_consultant']));
        $this->assertSame($staff->id, $data['assigned_consultant']['id']);

        // Organisation: name only.
        $this->assertEqualsCanonicalizing(['name'], array_keys($data['organization']));

        // Service: code + display_name only.
        $this->assertEqualsCanonicalizing(['code', 'display_name'], array_keys($data['consultation_enquiry']['consultancy_service']));

        // Appointment type: name only.
        $this->assertEqualsCanonicalizing(['name'], array_keys($data['appointment_type']));

        $json = $response->getContent();
        $this->assertStringNotContainsString($staff->email, $json);
    }

    public function test_permissions_block_reflects_assignment_and_role_correctly(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($assignedAdmin);
        [, $otherAdmin] = $this->makeOrgAndUser('Admin');
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService();
        \App\Models\AppointmentAvailability::create([
            'user_id' => $assignedAdmin->id, 'context' => \App\Support\Appointments\AvailabilityContext::CONSULTANCY, 'weekday' => 2, 'start_time' => '09:00', 'end_time' => '17:00', 'is_active' => true,
        ]);
        $booking = $this->bookAsClient($client, $service, 2);

        $asAssigned = $this->actingAs($assignedAdmin)->getJson("/api/admin/consultancy/consultations/{$booking['id']}")->json('permissions');
        $this->assertTrue($asAssigned['can_edit_notes']);
        $this->assertTrue($asAssigned['can_publish_summary']);
        $this->assertTrue($asAssigned['can_change_status']);
        $this->assertTrue($asAssigned['can_link_project']);
        $this->assertFalse($asAssigned['can_reassign']);
        $this->assertFalse($asAssigned['can_reopen']);

        $asOther = $this->actingAs($otherAdmin)->getJson("/api/admin/consultancy/consultations/{$booking['id']}")->json('permissions');
        $this->assertFalse($asOther['can_edit_notes']);
        $this->assertFalse($asOther['can_publish_summary']);
        $this->assertFalse($asOther['can_change_status']);
        $this->assertFalse($asOther['can_link_project']);
        $this->assertFalse($asOther['can_reassign']);
        $this->assertFalse($asOther['can_reopen']);

        $asSuperAdmin = $this->actingAs($superAdmin)->getJson("/api/admin/consultancy/consultations/{$booking['id']}")->json('permissions');
        foreach ($asSuperAdmin as $flag) {
            $this->assertTrue($flag);
        }
    }

    // ── Authorization: the confirmed four-tier visibility model ────────────

    public function test_super_admin_can_view_any_consultation(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        [, $client] = $this->makeOrgAndUser('Client');
        $booking = $this->bookAsClient($client, $this->makeService(), 3);

        $this->actingAs($superAdmin)->getJson("/api/admin/consultancy/consultations/{$booking['id']}")->assertStatus(200);
    }

    public function test_assigned_admin_can_view_their_own(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService();
        \App\Models\AppointmentAvailability::create(['user_id' => $staff->id, 'context' => \App\Support\Appointments\AvailabilityContext::CONSULTANCY, 'weekday' => 4, 'start_time' => '09:00', 'end_time' => '17:00', 'is_active' => true]);
        $booking = $this->bookAsClient($client, $service, 4);

        $this->actingAs($staff)->getJson("/api/admin/consultancy/consultations/{$booking['id']}")->assertStatus(200);
    }

    public function test_unassigned_admin_can_view_any_consultation_including_one_assigned_to_a_different_admin(): void
    {
        // The confirmed broader rule — strictly wider than "can view
        // unassigned ones," which is the (unchanged) generic Appointments
        // rule. This is the batch's central security-relevant assertion.
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($assignedAdmin);
        [, $unassignedAdmin] = $this->makeOrgAndUser('Admin');
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService();
        \App\Models\AppointmentAvailability::create(['user_id' => $assignedAdmin->id, 'context' => \App\Support\Appointments\AvailabilityContext::CONSULTANCY, 'weekday' => 5, 'start_time' => '09:00', 'end_time' => '17:00', 'is_active' => true]);
        $booking = $this->bookAsClient($client, $service, 5);

        $response = $this->actingAs($unassignedAdmin)->getJson("/api/admin/consultancy/consultations/{$booking['id']}");
        $response->assertStatus(200);
        $this->assertFalse($response->json('permissions.can_edit_notes'));
    }

    public function test_unassigned_admin_sees_the_same_operator_data_as_assigned_admin(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($assignedAdmin);
        [, $unassignedAdmin] = $this->makeOrgAndUser('Admin');
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService();
        \App\Models\AppointmentAvailability::create(['user_id' => $assignedAdmin->id, 'context' => \App\Support\Appointments\AvailabilityContext::CONSULTANCY, 'weekday' => 6, 'start_time' => '09:00', 'end_time' => '17:00', 'is_active' => true]);
        $booking = $this->bookAsClient($client, $service, 6);
        ConsultationEnquiry::where('appointment_id', $booking['id'])->update(['internal_notes' => 'Sensitive continuity note.']);

        $asAssigned = $this->actingAs($assignedAdmin)->getJson("/api/admin/consultancy/consultations/{$booking['id']}")->json();
        $asUnassigned = $this->actingAs($unassignedAdmin)->getJson("/api/admin/consultancy/consultations/{$booking['id']}")->json();

        unset($asAssigned['permissions'], $asUnassigned['permissions']);
        $this->assertEquals($asAssigned, $asUnassigned);
    }

    public function test_client_gets_403_on_queue_and_detail_endpoints(): void
    {
        [, $client] = $this->makeOrgAndUser('Client');
        $booking = $this->bookAsClient($client, $this->makeService(), 0);

        $this->actingAs($client)->getJson('/api/admin/consultancy/consultations')->assertStatus(403);
        $this->actingAs($client)->getJson("/api/admin/consultancy/consultations/{$booking['id']}")->assertStatus(403);
    }

    public function test_unauthenticated_request_is_denied(): void
    {
        $this->getJson('/api/admin/consultancy/consultations')->assertStatus(401);
    }

    // ── Queue: search / filter / sort / pagination ──────────────────────────

    public function test_queue_search_matches_reference_customer_organisation_and_service(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        [$orgA, $clientA] = $this->makeOrgAndUser('Client');
        $serviceA = $this->makeService(['display_name' => 'Findable Service Name']);
        $bookingA = $this->bookAsClient($clientA, $serviceA, 1);

        [, $clientB] = $this->makeOrgAndUser('Client');
        $this->bookAsClient($clientB, $this->makeService(), 2, ['attendee_name' => 'Totally Different']);

        $response = $this->actingAs($superAdmin)->getJson('/api/admin/consultancy/consultations?search=Findable');
        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($bookingA['id']));
        $this->assertCount(1, $ids);

        $byOrg = $this->actingAs($superAdmin)->getJson('/api/admin/consultancy/consultations?search=' . urlencode($orgA->name));
        $this->assertTrue(collect($byOrg->json('data'))->pluck('id')->contains($bookingA['id']));

        $byReference = $this->actingAs($superAdmin)->getJson('/api/admin/consultancy/consultations?search=' . $bookingA['reference']);
        $this->assertTrue(collect($byReference->json('data'))->pluck('id')->contains($bookingA['id']));
    }

    public function test_queue_filters_by_engagement_status_appointment_status_assignee_and_service(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService();
        \App\Models\AppointmentAvailability::create(['user_id' => $staff->id, 'context' => \App\Support\Appointments\AvailabilityContext::CONSULTANCY, 'weekday' => 3, 'start_time' => '09:00', 'end_time' => '17:00', 'is_active' => true]);
        $booking = $this->bookAsClient($client, $service, 3);

        // Consultancy Live Booking Upgrade, Stage 1 — every Consultancy
        // booking now resolves to the same single configured consultant
        // (no per-service assignment any more), so an "unassigned" queue
        // entry can no longer arise from the live booking flow itself. The
        // queue-filtering feature under test is still real and still
        // matters (an operator filtering by assignee), so this books
        // normally (same consultant, a second availability window) and
        // then directly clears assigned_user_id to simulate the
        // historical-unassigned case the filter must still handle.
        \App\Models\AppointmentAvailability::create(['user_id' => $staff->id, 'context' => \App\Support\Appointments\AvailabilityContext::CONSULTANCY, 'weekday' => 4, 'start_time' => '09:00', 'end_time' => '17:00', 'is_active' => true]);
        [, $client2] = $this->makeOrgAndUser('Client');
        $other = $this->bookAsClient($client2, $this->makeService(), 4);
        \App\Models\Appointment::where('id', $other['id'])->update(['assigned_user_id' => null]);

        $byEngagement = $this->actingAs($superAdmin)->getJson('/api/admin/consultancy/consultations?engagement_status=awaiting_consultant');
        $this->assertGreaterThanOrEqual(2, count($byEngagement->json('data')));

        $byAssignee = $this->actingAs($superAdmin)->getJson("/api/admin/consultancy/consultations?assigned_user_id={$staff->id}");
        $ids = collect($byAssignee->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($booking['id']));
        $this->assertFalse($ids->contains($other['id']));

        $byService = $this->actingAs($superAdmin)->getJson("/api/admin/consultancy/consultations?consultancy_service_id={$service->id}");
        $this->assertTrue(collect($byService->json('data'))->pluck('id')->contains($booking['id']));
    }

    public function test_queue_sort_by_reference_never_uses_an_arbitrary_column(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        [, $client] = $this->makeOrgAndUser('Client');
        $this->bookAsClient($client, $this->makeService(), 0);

        // An unrecognised sort_by must never be passed through to orderBy() —
        // it should silently fall back to the safe default, not error.
        $response = $this->actingAs($superAdmin)->getJson('/api/admin/consultancy/consultations?sort_by=' . urlencode('id); DROP TABLE users;--'));
        $response->assertStatus(200);
    }

    public function test_queue_response_is_paginated(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService();
        for ($i = 0; $i < 3; $i++) {
            $this->bookAsClient($client, $service, $i);
        }

        $response = $this->actingAs($superAdmin)->getJson('/api/admin/consultancy/consultations?per_page=2');
        $response->assertStatus(200);
        $this->assertArrayHasKey('current_page', $response->json());
        $this->assertArrayHasKey('per_page', $response->json());
        $this->assertArrayHasKey('total', $response->json());
        $this->assertCount(2, $response->json('data'));
    }

    // ── Performance ──────────────────────────────────────────────────────────

    public function test_queue_query_count_does_not_scale_with_row_count(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService();

        for ($i = 0; $i < 3; $i++) {
            $this->bookAsClient($client, $service, $i);
        }
        DB::enableQueryLog();
        $this->actingAs($superAdmin)->getJson('/api/admin/consultancy/consultations')->assertStatus(200);
        $smallCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        for ($i = 3; $i < 6; $i++) {
            $this->bookAsClient($client, $service, $i);
        }
        DB::enableQueryLog();
        $this->actingAs($superAdmin)->getJson('/api/admin/consultancy/consultations')->assertStatus(200);
        $largerCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // A tolerance of 2, not exact equality: an incidental global
        // settings-cache lookup (unrelated middleware, e.g. branding/usage
        // tracking) can vary by a query or two depending on cache-warm
        // timing between runs. What this test actually guards against is
        // LINEAR scaling with row count (a genuine per-row N+1 in the
        // presenter/controller) — doubling the row count must not double
        // the query count.
        $this->assertLessThanOrEqual($smallCount + 2, $largerCount, 'Query count scaled with row count — likely an N+1.');
    }
}
