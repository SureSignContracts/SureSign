<?php

namespace Tests\Feature;

use App\Jobs\SendConsultationEmailJob;
use App\Models\ConsultancyService;
use App\Models\ConsultationEnquiry;
use App\Models\Organization;
use App\Models\User;
use App\Services\Consultancy\ConsultancyCatalogueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Consultancy — Phase C2, Batch 4 (Operational Write Actions). See
 * internal-docs/commercial/suresign-consultancy-phase-c2-specification-v1.md §16.
 */
class ConsultancyPhaseC2Batch4Test extends TestCase
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
            'code'                             => "batch4-service-{$n}",
            'display_name'                     => "Batch 4 Service {$n}",
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

    /** Books a consultation, assigned to $assignedAdmin (the configured consultant), returns the fresh ConsultationEnquiry. */
    private function makeAssignedConsultation(User $assignedAdmin, int $weekday): ConsultationEnquiry
    {
        \App\Models\AppointmentAvailability::create([
            'user_id' => $assignedAdmin->id, 'context' => \App\Support\Appointments\AvailabilityContext::CONSULTANCY, 'weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '17:00', 'is_active' => true,
        ]);
        // Consultancy Live Booking Upgrade, Stage 1 — the consultant is a
        // platform-wide setting, not a per-service field.
        \App\Models\SuresignSetting::instance()->update(['consultancy_consultant_user_id' => $assignedAdmin->id]);
        $service = $this->makeService();
        [, $client] = $this->makeOrgAndUser('Client');
        $date = $this->nextDateForWeekday($weekday);

        $booking = $this->actingAs($client)->postJson('/api/consultations', [
            'consultancy_service_code' => $service->code,
            'attendee_name'      => 'Jane Client',
            'attendee_email'     => 'jane@client.example.com',
            'attendee_timezone'  => 'Europe/London',
            'date'               => $date,
            'start_time'         => '10:00',
            'timezone'           => 'Europe/London',
            'title'              => 'A query',
            'description'        => 'A description.',
        ])->assertStatus(201)->json();

        return ConsultationEnquiry::where('appointment_id', $booking['id'])->firstOrFail();
    }

    // ── Authorization ────────────────────────────────────────────────────────

    public function test_assigned_admin_can_write_every_action(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 0);
        $id = $enquiry->appointment_id;

        $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$id}/notes", ['internal_notes' => 'A note.'])->assertStatus(200);
        $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$id}/summary", ['customer_summary_draft' => 'A draft.'])->assertStatus(200);
        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/awaiting-customer")->assertStatus(200);
        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/awaiting-consultant")->assertStatus(200);
        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/summary/publish")->assertStatus(200);
    }

    public function test_super_admin_can_write_every_action_including_reopen(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 1);
        $id = $enquiry->appointment_id;

        $this->actingAs($superAdmin)->putJson("/api/admin/consultancy/consultations/{$id}/notes", ['internal_notes' => 'SA note.'])->assertStatus(200);
        $this->actingAs($superAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/complete")->assertStatus(200);
        $this->actingAs($superAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/reopen")->assertStatus(200);
    }

    public function test_unassigned_admin_receives_403_on_every_write_endpoint(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        [, $unassignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 2);
        $id = $enquiry->appointment_id;

        $this->actingAs($unassignedAdmin)->putJson("/api/admin/consultancy/consultations/{$id}/notes", ['internal_notes' => 'x'])->assertStatus(403);
        $this->actingAs($unassignedAdmin)->putJson("/api/admin/consultancy/consultations/{$id}/summary", ['customer_summary_draft' => 'x'])->assertStatus(403);
        $this->actingAs($unassignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/summary/publish")->assertStatus(403);
        $this->actingAs($unassignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/awaiting-customer")->assertStatus(403);
        $this->actingAs($unassignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/awaiting-consultant")->assertStatus(403);
        $this->actingAs($unassignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/complete")->assertStatus(403);
        $this->actingAs($unassignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/reopen")->assertStatus(403);
    }

    public function test_assigned_admin_cannot_reopen_only_super_admin_can(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 3);
        $id = $enquiry->appointment_id;
        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/complete")->assertStatus(200);

        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/reopen")->assertStatus(403);
    }

    public function test_client_receives_403_on_write_endpoints(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        [, $client] = $this->makeOrgAndUser('Client');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 4);
        $id = $enquiry->appointment_id;

        $this->actingAs($client)->putJson("/api/admin/consultancy/consultations/{$id}/notes", ['internal_notes' => 'x'])->assertStatus(403);
        $this->actingAs($client)->postJson("/api/admin/consultancy/consultations/{$id}/status/awaiting-customer")->assertStatus(403);
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────

    public function test_manual_transitions_round_trip_via_http(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 5);
        $id = $enquiry->appointment_id;

        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/awaiting-customer")
            ->assertStatus(200)->assertJsonPath('consultation_enquiry.engagement_status', 'awaiting_customer');

        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/awaiting-consultant")
            ->assertStatus(200)->assertJsonPath('consultation_enquiry.engagement_status', 'awaiting_consultant');
    }

    public function test_repeating_the_same_transition_fails(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 6);
        $id = $enquiry->appointment_id;

        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/awaiting-consultant")->assertStatus(422);
    }

    public function test_mark_completed_twice_fails(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 0);
        $id = $enquiry->appointment_id;

        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/complete")->assertStatus(200);
        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/complete")->assertStatus(422);
    }

    public function test_reopen_fails_from_a_non_completed_state(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 1);
        $id = $enquiry->appointment_id;

        $this->actingAs($superAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/reopen")->assertStatus(422);
    }

    public function test_cancelled_engagement_remains_terminal_to_every_write_action(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 2);
        $id = $enquiry->appointment_id;

        $this->actingAs($assignedAdmin)->postJson("/api/appointments/{$id}/cancel", ['reason' => 'Test'])->assertStatus(200);
        $this->assertSame('cancelled', $enquiry->fresh()->engagement_status);

        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/awaiting-customer")->assertStatus(422);
        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/complete")->assertStatus(422);
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        $this->actingAs($superAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/reopen")->assertStatus(422);
    }

    public function test_completed_engagement_locks_notes_and_summary_for_non_super_admin(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 3);
        $id = $enquiry->appointment_id;
        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/complete")->assertStatus(200);

        $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$id}/notes", ['internal_notes' => 'x'])->assertStatus(422);
        $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$id}/summary", ['customer_summary_draft' => 'x'])->assertStatus(422);

        // Super Admin retains write access even when completed.
        $this->actingAs($superAdmin)->putJson("/api/admin/consultancy/consultations/{$id}/notes", ['internal_notes' => 'SA override'])->assertStatus(200);
    }

    // ── Customer summary publishing workflow ────────────────────────────────

    public function test_publish_copies_draft_to_published_and_completes_the_engagement(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 4);
        $id = $enquiry->appointment_id;

        $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$id}/summary", ['customer_summary_draft' => 'Here is the summary.'])->assertStatus(200);
        $response = $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/summary/publish");

        $response->assertStatus(200)
            ->assertJsonPath('consultation_enquiry.customer_summary_published', 'Here is the summary.')
            ->assertJsonPath('consultation_enquiry.engagement_status', 'completed')
            ->assertJsonPath('consultation_enquiry.customer_summary_needs_republish', false);
        $this->assertNotNull($response->json('consultation_enquiry.customer_summary_published_at'));
    }

    public function test_draft_edit_after_publish_sets_needs_republish_without_changing_published_value(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 5);
        $id = $enquiry->appointment_id;
        $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$id}/summary", ['customer_summary_draft' => 'Version 1'])->assertStatus(200);
        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/summary/publish")->assertStatus(200);

        // Reopen (Super Admin) so notes/summary aren't locked by completion, then edit the draft.
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        $this->actingAs($superAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/reopen")->assertStatus(200);
        $editResponse = $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$id}/summary", ['customer_summary_draft' => 'Version 2']);

        $editResponse->assertJsonPath('consultation_enquiry.customer_summary_needs_republish', true)
            ->assertJsonPath('consultation_enquiry.customer_summary_published', 'Version 1');

        // Customer-facing endpoint must still show the published version, never the edited draft.
        $customerCheck = $this->getCustomerFacingSummary($enquiry->appointment_id, $enquiry);
        $this->assertSame('Version 1', $customerCheck);
    }

    private function getCustomerFacingSummary(int $appointmentId, ConsultationEnquiry $enquiry): ?string
    {
        // The booking client is the authorised viewer — re-derive via the appointment's own organization.
        $appointment = \App\Models\Appointment::find($appointmentId);
        $client = User::where('organization_id', $appointment->organization_id)->first();
        $response = $this->actingAs($client)->getJson("/api/consultations/{$appointmentId}");
        return $response->json('consultation_enquiry.customer_summary_published');
    }

    public function test_republish_updates_the_published_value_and_clears_needs_republish(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 6);
        $id = $enquiry->appointment_id;
        $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$id}/summary", ['customer_summary_draft' => 'Version 1'])->assertStatus(200);
        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/summary/publish")->assertStatus(200);

        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        $this->actingAs($superAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/reopen")->assertStatus(200);
        $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$id}/summary", ['customer_summary_draft' => 'Version 2'])->assertStatus(200);

        $republish = $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/summary/publish");
        $republish->assertJsonPath('consultation_enquiry.customer_summary_published', 'Version 2')
            ->assertJsonPath('consultation_enquiry.customer_summary_needs_republish', false);
    }

    public function test_customer_never_receives_internal_notes_or_draft_via_api(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 0);
        $id = $enquiry->appointment_id;
        $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$id}/notes", ['internal_notes' => 'Sensitive internal note.'])->assertStatus(200);
        $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$id}/summary", ['customer_summary_draft' => 'A private draft.'])->assertStatus(200);

        $summary = $this->getCustomerFacingSummary($id, $enquiry);
        $this->assertNull($summary); // not published yet

        $appointment = \App\Models\Appointment::find($id);
        $client = User::where('organization_id', $appointment->organization_id)->first();
        $response = $this->actingAs($client)->getJson("/api/consultations/{$id}");
        $json = $response->getContent();
        $this->assertStringNotContainsString('Sensitive internal note.', $json);
        $this->assertStringNotContainsString('A private draft.', $json);
        $this->assertArrayNotHasKey('internal_notes', $response->json('consultation_enquiry'));
    }

    // ── Notification ─────────────────────────────────────────────────────────

    public function test_awaiting_customer_notification_sent_exactly_once(): void
    {
        Bus::fake();
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 1);
        $id = $enquiry->appointment_id;

        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/awaiting-customer")->assertStatus(200);

        Bus::assertDispatched(SendConsultationEmailJob::class, fn ($job) => $job->consultationEnquiryId === $enquiry->id && $job->kind === 'awaiting_customer');
        Bus::assertDispatchedTimes(SendConsultationEmailJob::class, 1);
    }

    public function test_repeating_the_same_awaiting_customer_request_does_not_send_a_second_notification(): void
    {
        Bus::fake();
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 2);
        $id = $enquiry->appointment_id;

        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/awaiting-customer")->assertStatus(200);
        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/awaiting-customer")->assertStatus(422);

        Bus::assertDispatchedTimes(SendConsultationEmailJob::class, 1);
    }

    public function test_failed_transition_never_sends_a_notification(): void
    {
        Bus::fake();
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 3);
        $id = $enquiry->appointment_id;
        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/complete")->assertStatus(200);

        // Cannot go from completed straight to awaiting_customer.
        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/awaiting-customer")->assertStatus(422);

        Bus::assertNotDispatched(SendConsultationEmailJob::class);
    }

    public function test_unauthorised_request_never_sends_a_notification(): void
    {
        Bus::fake();
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        [, $unassignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 4);
        $id = $enquiry->appointment_id;

        $this->actingAs($unassignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/awaiting-customer")->assertStatus(403);

        Bus::assertNotDispatched(SendConsultationEmailJob::class);
    }

    /**
     * Communications Upgrade Batch 3 moved this dispatch from
     * SendConsultationEmailJob/'summary_published' to
     * SendConsultationCommunicationJob/'summary_published' (a premium,
     * idempotent, correctly-linked email — see
     * ConsultationCommunicationService's own docblock) — this assertion
     * was updated in place, not duplicated, since the underlying business
     * event (one notification per publish) is unchanged.
     */
    public function test_publish_sends_summary_published_notification(): void
    {
        Bus::fake();
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 5);
        $id = $enquiry->appointment_id;
        $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$id}/summary", ['customer_summary_draft' => 'A summary.'])->assertStatus(200);

        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/summary/publish")->assertStatus(200);

        Bus::assertDispatched(\App\Jobs\SendConsultationCommunicationJob::class, fn ($job) => $job->appointmentId === $id && $job->kind === 'summary_published');
    }

    // ── Activity logging ─────────────────────────────────────────────────────

    public function test_every_write_action_is_logged_without_storing_raw_content(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 6);
        $id = $enquiry->appointment_id;

        $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$id}/notes", ['internal_notes' => 'A very sensitive note about the client.'])->assertStatus(200);
        $this->assertDatabaseHas('activity_logs', ['action' => 'consultation.internal_notes_updated']);

        $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$id}/summary", ['customer_summary_draft' => 'A very sensitive draft body.'])->assertStatus(200);
        $this->assertDatabaseHas('activity_logs', ['action' => 'consultation.summary_draft_updated']);

        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/summary/publish")->assertStatus(200);
        $this->assertDatabaseHas('activity_logs', ['action' => 'consultation.summary_published']);

        foreach (\App\Models\ActivityLog::where('subject_id', $id)->where('subject_type', \App\Models\Appointment::class)->get() as $log) {
            $this->assertStringNotContainsString('sensitive', json_encode($log->metadata) ?: '');
            $this->assertStringNotContainsString('sensitive', $log->description);
        }
    }

    // ── Regression ───────────────────────────────────────────────────────────

    public function test_existing_c1_customer_cancellation_still_works(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 0);
        $appointment = \App\Models\Appointment::find($enquiry->appointment_id);
        $client = User::where('organization_id', $appointment->organization_id)->first();

        $this->actingAs($client)->postJson("/api/consultations/{$appointment->id}/cancel", [])->assertStatus(200);
        $this->assertSame('cancelled', $enquiry->fresh()->engagement_status);
    }

    public function test_batch_3_queue_still_reflects_engagement_status_after_a_write_action(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin, 1);
        $id = $enquiry->appointment_id;
        $this->actingAs($assignedAdmin)->postJson("/api/admin/consultancy/consultations/{$id}/status/awaiting-customer")->assertStatus(200);

        $queue = $this->actingAs($superAdmin)->getJson('/api/admin/consultancy/consultations?engagement_status=awaiting_customer');
        $ids = collect($queue->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($id));
    }
}
