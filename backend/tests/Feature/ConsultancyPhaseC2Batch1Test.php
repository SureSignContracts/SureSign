<?php

namespace Tests\Feature;

use App\Models\ConsultancyService;
use App\Models\ConsultationEnquiry;
use App\Models\Organization;
use App\Models\User;
use App\Services\Consultancy\ConsultancyCatalogueService;
use App\Services\Consultancy\EngagementLifecycleService;
use App\Services\AppointmentPublicLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Consultancy — Phase C2, Batch 1 (Engagement Lifecycle Foundation). See
 * internal-docs/commercial/suresign-consultancy-phase-c2-specification-v1.md.
 *
 * Strictly scoped to this batch: the engagement_status representation,
 * EngagementLifecycleService, the cancellation observer, and the backfill
 * migration. No controllers/routes/UI/notes/summary/project-linkage exist
 * yet — those are later batches.
 */
class ConsultancyPhaseC2Batch1Test extends TestCase
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
            'code'                             => "batch1-service-{$n}",
            'display_name'                     => "Batch 1 Service {$n}",
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

    /** Books a real consultation via the existing public C1 endpoint — returns the fresh ConsultationEnquiry. */
    private function bookConsultation(ConsultancyService $service, int $weekday): ConsultationEnquiry
    {
        $date = $this->nextDateForWeekday($weekday);

        $response = $this->postJson("/api/public/consultancy-services/{$service->code}/book", [
            'attendee_name'      => 'Jane Prospect',
            'attendee_email'     => 'jane@prospect.example.com',
            'attendee_timezone'  => 'Europe/London',
            'date'               => $date,
            'start_time'         => '10:00',
            'timezone'           => 'Europe/London',
            'title'              => 'A query',
            'description'        => 'A description.',
            'consent'            => true,
        ]);
        $response->assertStatus(201);

        return ConsultationEnquiry::where('title', 'A query')->latest('id')->firstOrFail();
    }

    // ── Migration / backfill ────────────────────────────────────────────────

    public function test_new_consultation_enquiry_defaults_to_awaiting_consultant(): void
    {
        $service = $this->makeService();
        $enquiry = $this->bookConsultation($service, 1);

        $this->assertSame('awaiting_consultant', $enquiry->engagement_status);
    }

    #[DataProvider('derivationCases')]
    public function test_derive_initial_status_from_appointment_status(string $appointmentStatus, string $expected): void
    {
        $this->assertSame($expected, EngagementLifecycleService::deriveInitialStatusFromAppointmentStatus($appointmentStatus));
    }

    public static function derivationCases(): array
    {
        return [
            'cancelled maps to cancelled'                    => ['cancelled', 'cancelled'],
            'completed maps to completed'                    => ['completed', 'completed'],
            'requested maps to awaiting_consultant'           => ['requested', 'awaiting_consultant'],
            'pending_confirmation maps to awaiting_consultant' => ['pending_confirmation', 'awaiting_consultant'],
            'confirmed maps to awaiting_consultant'           => ['confirmed', 'awaiting_consultant'],
            'no_show maps to awaiting_consultant'             => ['no_show', 'awaiting_consultant'],
            'declined maps to awaiting_consultant'            => ['declined', 'awaiting_consultant'],
        ];
    }

    public function test_backfill_migration_derives_correct_status_for_preexisting_rows(): void
    {
        // Simulates what the Batch 1 migration's backfill step does for a
        // genuinely pre-existing C1 row: apply deriveInitialStatusFromAppointmentStatus()
        // to the row's already-existing Appointment status. The column is
        // NOT NULL after this migration (by design — see §1.5), so this
        // exercises the exact same derivation call the migration itself
        // makes, rather than literally reconstructing a pre-migration
        // nullable-column database state.
        $service = $this->makeService();
        $enquiry = $this->bookConsultation($service, 2);
        $enquiry->appointment->update(['status' => 'cancelled']);

        $derived = EngagementLifecycleService::deriveInitialStatusFromAppointmentStatus($enquiry->appointment->fresh()->status);
        \DB::table('consultation_enquiries')->where('id', $enquiry->id)->update(['engagement_status' => $derived]);

        $this->assertSame('cancelled', $enquiry->fresh()->engagement_status);
    }

    // ── EngagementLifecycleService: valid/invalid transitions ───────────────

    public function test_manual_transition_between_awaiting_states_succeeds(): void
    {
        [, $admin] = $this->makeOrgAndUser('Admin');
        $service = $this->makeService();
        $enquiry = $this->bookConsultation($service, 3);
        $lifecycle = app(EngagementLifecycleService::class);

        $updated = $lifecycle->transitionManual($enquiry, 'awaiting_customer', $admin);
        $this->assertSame('awaiting_customer', $updated->engagement_status);

        $updated = $lifecycle->transitionManual($updated, 'awaiting_consultant', $admin);
        $this->assertSame('awaiting_consultant', $updated->engagement_status);
    }

    public function test_manual_transition_to_the_same_status_is_rejected(): void
    {
        [, $admin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->bookConsultation($this->makeService(), 4);

        $this->expectException(\InvalidArgumentException::class);
        app(EngagementLifecycleService::class)->transitionManual($enquiry, 'awaiting_consultant', $admin);
    }

    public function test_manual_transition_to_completed_is_rejected(): void
    {
        [, $admin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->bookConsultation($this->makeService(), 5);

        $this->expectException(\InvalidArgumentException::class);
        app(EngagementLifecycleService::class)->transitionManual($enquiry, 'completed', $admin);
    }

    public function test_manual_transition_to_cancelled_is_rejected(): void
    {
        [, $admin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->bookConsultation($this->makeService(), 6);

        $this->expectException(\InvalidArgumentException::class);
        app(EngagementLifecycleService::class)->transitionManual($enquiry, 'cancelled', $admin);
    }

    public function test_mark_completed_succeeds_from_either_non_terminal_state(): void
    {
        [, $admin] = $this->makeOrgAndUser('Admin');
        $lifecycle = app(EngagementLifecycleService::class);

        $enquiryA = $this->bookConsultation($this->makeService(), 0);
        $updated = $lifecycle->markCompleted($enquiryA, $admin, viaSummaryPublish: false);
        $this->assertSame('completed', $updated->engagement_status);

        $enquiryB = $this->bookConsultation($this->makeService(), 1);
        $lifecycle->transitionManual($enquiryB, 'awaiting_customer', $admin);
        $updated = $lifecycle->markCompleted($enquiryB->fresh(), $admin, viaSummaryPublish: true);
        $this->assertSame('completed', $updated->engagement_status);
    }

    public function test_mark_completed_twice_is_rejected(): void
    {
        [, $admin] = $this->makeOrgAndUser('Admin');
        $lifecycle = app(EngagementLifecycleService::class);
        $enquiry = $this->bookConsultation($this->makeService(), 2);
        $lifecycle->markCompleted($enquiry, $admin, viaSummaryPublish: false);

        $this->expectException(\InvalidArgumentException::class);
        $lifecycle->markCompleted($enquiry->fresh(), $admin, viaSummaryPublish: false);
    }

    public function test_reopen_succeeds_from_completed(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        $lifecycle = app(EngagementLifecycleService::class);
        $enquiry = $this->bookConsultation($this->makeService(), 3);
        $lifecycle->markCompleted($enquiry, $superAdmin, viaSummaryPublish: false);

        $updated = $lifecycle->reopen($enquiry->fresh(), $superAdmin);
        $this->assertSame('awaiting_consultant', $updated->engagement_status);
    }

    public function test_reopen_from_a_non_completed_state_is_rejected(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        $enquiry = $this->bookConsultation($this->makeService(), 4);

        $this->expectException(\InvalidArgumentException::class);
        app(EngagementLifecycleService::class)->reopen($enquiry, $superAdmin);
    }

    public function test_reopen_does_not_clear_a_previously_published_summary(): void
    {
        [, $admin] = $this->makeOrgAndUser('Admin');
        $lifecycle = app(EngagementLifecycleService::class);
        $enquiry = $this->bookConsultation($this->makeService(), 5);
        $enquiry->update([
            'customer_summary_published'    => 'Here is what we discussed.',
            'customer_summary_published_at' => now(),
        ]);
        $lifecycle->markCompleted($enquiry->fresh(), $admin, viaSummaryPublish: true);

        $updated = $lifecycle->reopen($enquiry->fresh(), $admin);
        $this->assertSame('Here is what we discussed.', $updated->customer_summary_published);
        $this->assertNotNull($updated->customer_summary_published_at);
    }

    public function test_service_exposes_exactly_the_four_specified_public_methods(): void
    {
        // Defense-in-depth: the service's public API surface has exactly
        // one path to 'cancelled' (syncFromAppointmentCancellation) and no
        // extra method that could be used to bypass the transition rules
        // above.
        $reflection = new \ReflectionClass(EngagementLifecycleService::class);
        $publicMethods = array_values(array_diff(
            array_map(fn ($m) => $m->getName(), $reflection->getMethods(\ReflectionMethod::IS_PUBLIC)),
            ['__construct'],
        ));

        $this->assertEqualsCanonicalizing(
            ['deriveInitialStatusFromAppointmentStatus', 'transitionManual', 'markCompleted', 'reopen', 'syncFromAppointmentCancellation'],
            $publicMethods,
        );
    }

    // ── Cancellation observer: all three real cancellation entry points ────

    public function test_admin_cancellation_syncs_engagement_status_to_cancelled(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        $enquiry = $this->bookConsultation($this->makeService(), 0);

        $this->actingAs($superAdmin)
            ->postJson("/api/appointments/{$enquiry->appointment_id}/cancel", ['reason' => 'Test'])
            ->assertStatus(200);

        $this->assertSame('cancelled', $enquiry->fresh()->engagement_status);
        $this->assertSame('cancelled', $enquiry->appointment->fresh()->status);
    }

    public function test_client_cancellation_syncs_engagement_status_to_cancelled(): void
    {
        [$org, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService(['available_to_existing_customers' => true]);
        $date = $this->nextDateForWeekday(1);

        $booking = $this->actingAs($client)->postJson('/api/consultations', [
            'consultancy_service_code' => $service->code,
            'attendee_name'      => 'Jane Client',
            'attendee_email'     => 'jane@client.example.com',
            'attendee_timezone'  => 'Europe/London',
            'date'               => $date,
            'start_time'         => '11:00',
            'timezone'           => 'Europe/London',
            'title'              => 'Client booking',
            'description'        => 'A client query.',
        ])->assertStatus(201)->json();

        $enquiry = ConsultationEnquiry::where('appointment_id', $booking['id'])->firstOrFail();
        $this->assertSame('awaiting_consultant', $enquiry->engagement_status);

        $this->actingAs($client)
            ->postJson("/api/consultations/{$booking['id']}/cancel", [])
            ->assertStatus(200);

        $this->assertSame('cancelled', $enquiry->fresh()->engagement_status);
    }

    public function test_public_signed_link_cancellation_syncs_engagement_status_to_cancelled(): void
    {
        $enquiry = $this->bookConsultation($this->makeService(), 2);
        $appointment = $enquiry->appointment;

        $url = app(AppointmentPublicLinkService::class)->cancelApiUrl($appointment);
        $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);

        $this->postJson($path)->assertStatus(200);

        $this->assertSame('cancelled', $enquiry->fresh()->engagement_status);
        $this->assertSame('cancelled', $appointment->fresh()->status);
    }

    public function test_cancellation_sync_is_idempotent_if_observer_fires_twice(): void
    {
        $enquiry = $this->bookConsultation($this->makeService(), 3);
        $lifecycle = app(EngagementLifecycleService::class);

        $lifecycle->syncFromAppointmentCancellation($enquiry->fresh());
        $enquiry->appointment->update(['status' => 'cancelled']);
        $lifecycle->syncFromAppointmentCancellation($enquiry->fresh());

        $this->assertSame('cancelled', $enquiry->fresh()->engagement_status);
    }

    // ── Activity log ─────────────────────────────────────────────────────────

    public function test_manual_transition_and_reopen_are_recorded_distinctly_in_activity_log(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        $lifecycle = app(EngagementLifecycleService::class);
        $enquiry = $this->bookConsultation($this->makeService(), 4);

        $lifecycle->transitionManual($enquiry, 'awaiting_customer', $superAdmin);
        $this->assertDatabaseHas('activity_logs', ['action' => 'consultation.engagement_status_changed']);

        $lifecycle->markCompleted($enquiry->fresh(), $superAdmin, viaSummaryPublish: false);
        $lifecycle->reopen($enquiry->fresh(), $superAdmin);
        $this->assertDatabaseHas('activity_logs', ['action' => 'consultation.engagement_reopened']);
    }
}
