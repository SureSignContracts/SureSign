<?php

namespace Tests\Feature;

use App\Models\ConsultancyService;
use App\Models\Organization;
use App\Models\User;
use App\Services\Consultancy\ConsultancyCatalogueService;
use App\Support\Consultancy\ConsultationPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Consultancy — Phase C2, Batch 2 (Customer Presenter Wiring). See
 * internal-docs/commercial/suresign-consultancy-phase-c2-specification-v1.md §16.
 *
 * Strictly scoped to the customerFacing() presentation boundary — no
 * operator presenter, no write actions, no notifications exist yet.
 */
class ConsultancyPhaseC2Batch2Test extends TestCase
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
            'code'                             => "batch2-service-{$n}",
            'display_name'                     => "Batch 2 Service {$n}",
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

    private function bookAsClient(User $client, ConsultancyService $service, int $weekday): array
    {
        $date = $this->nextDateForWeekday($weekday);

        return $this->actingAs($client)->postJson('/api/consultations', [
            'consultancy_service_code' => $service->code,
            'attendee_name'      => 'Jane Client',
            'attendee_email'     => 'jane@client.example.com',
            'attendee_timezone'  => 'Europe/London',
            'date'               => $date,
            'start_time'         => '10:00',
            'timezone'           => 'Europe/London',
            'title'              => 'A payment notice question',
            'description'        => 'We have a dispute over a pay less notice.',
            'project_stage'      => 'Construction',
            'contract_form'      => 'NEC4',
            'preferred_outcome'  => 'Clarity on the deadline',
        ])->assertStatus(201)->json();
    }

    private const SENSITIVE_KEYS_ANYWHERE = [
        'internal_notes', 'engagement_status', 'customer_summary_draft',
        'customer_summary_needs_republish', 'customer_summary_published_by',
        'organization_id', 'linked_user_id', 'project_id', 'created_by_user_id',
        'assigned_user_id', 'public_token', 'schedule_version', 'metadata',
        'cancellation_reason', 'completion_notes',
    ];

    private function assertNoSensitiveKeysAnywhere(array $payload): void
    {
        $json = json_encode($payload);
        foreach (self::SENSITIVE_KEYS_ANYWHERE as $key) {
            $this->assertStringNotContainsString("\"{$key}\"", $json, "Response leaked sensitive key: {$key}");
        }
    }

    // ── Presenter unit behaviour ─────────────────────────────────────────────

    public function test_presenter_includes_expected_customer_facing_fields(): void
    {
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService();
        $booking = $this->bookAsClient($client, $service, 0);

        foreach (['id', 'reference', 'status', 'starts_at', 'ends_at', 'booking_timezone', 'attendee_name', 'attendee_email', 'appointment_type', 'assigned_user', 'consultation_enquiry'] as $key) {
            $this->assertArrayHasKey($key, $booking);
        }
        $this->assertSame('Batch 2 Service 1', $booking['appointment_type']['name'] ?? null);
        $this->assertSame('A payment notice question', $booking['consultation_enquiry']['title']);
        $this->assertSame('We have a dispute over a pay less notice.', $booking['consultation_enquiry']['description']);
        $this->assertSame('Construction', $booking['consultation_enquiry']['project_stage']);
        $this->assertSame('NEC4', $booking['consultation_enquiry']['contract_form']);
        $this->assertSame($service->display_name, $booking['consultation_enquiry']['consultancy_service']['display_name']);
    }

    public function test_unpublished_summary_is_present_and_null_not_omitted(): void
    {
        [, $client] = $this->makeOrgAndUser('Client');
        $booking = $this->bookAsClient($client, $this->makeService(), 1);

        $this->assertArrayHasKey('customer_summary_published', $booking['consultation_enquiry']);
        $this->assertNull($booking['consultation_enquiry']['customer_summary_published']);
        $this->assertArrayHasKey('customer_summary_published_at', $booking['consultation_enquiry']);
        $this->assertNull($booking['consultation_enquiry']['customer_summary_published_at']);
    }

    public function test_published_summary_appears_verbatim_once_set(): void
    {
        [, $client] = $this->makeOrgAndUser('Client');
        $booking = $this->bookAsClient($client, $this->makeService(), 2);

        $enquiry = \App\Models\ConsultationEnquiry::where('appointment_id', $booking['id'])->firstOrFail();
        $enquiry->update([
            'customer_summary_draft'     => 'An internal draft, never shown to the customer.',
            'customer_summary_published' => 'Here is a summary of what we discussed.',
            'customer_summary_published_at' => now(),
        ]);

        $response = $this->actingAs($client)->getJson("/api/consultations/{$booking['id']}");
        $response->assertStatus(200)
            ->assertJsonPath('consultation_enquiry.customer_summary_published', 'Here is a summary of what we discussed.');
        $this->assertNotNull($response->json('consultation_enquiry.customer_summary_published_at'));
    }

    public function test_draft_edit_after_publication_does_not_change_what_the_customer_sees(): void
    {
        [, $client] = $this->makeOrgAndUser('Client');
        $booking = $this->bookAsClient($client, $this->makeService(), 3);

        $enquiry = \App\Models\ConsultationEnquiry::where('appointment_id', $booking['id'])->firstOrFail();
        $enquiry->update([
            'customer_summary_published'      => 'Published version.',
            'customer_summary_published_at'   => now(),
            'customer_summary_draft'          => 'Edited after publish — should stay invisible.',
            'customer_summary_needs_republish' => true,
        ]);

        $response = $this->actingAs($client)->getJson("/api/consultations/{$booking['id']}");
        $response->assertJsonPath('consultation_enquiry.customer_summary_published', 'Published version.');
        $this->assertNoSensitiveKeysAnywhere($response->json());
    }

    // ── Negative / leakage tests ─────────────────────────────────────────────

    public function test_index_response_never_contains_sensitive_fields(): void
    {
        [, $client] = $this->makeOrgAndUser('Client');
        $this->bookAsClient($client, $this->makeService(), 4);

        $response = $this->actingAs($client)->getJson('/api/consultations');
        $response->assertStatus(200);
        $this->assertNoSensitiveKeysAnywhere($response->json());
    }

    public function test_show_response_never_contains_sensitive_fields(): void
    {
        [, $client] = $this->makeOrgAndUser('Client');
        $booking = $this->bookAsClient($client, $this->makeService(), 5);

        $enquiry = \App\Models\ConsultationEnquiry::where('appointment_id', $booking['id'])->firstOrFail();
        $enquiry->update(['internal_notes' => 'A private consultant note.', 'engagement_status' => 'awaiting_customer']);

        $response = $this->actingAs($client)->getJson("/api/consultations/{$booking['id']}");
        $response->assertStatus(200);
        $this->assertNoSensitiveKeysAnywhere($response->json());
        $this->assertStringNotContainsString('A private consultant note.', $response->getContent());
    }

    public function test_store_response_never_contains_sensitive_fields(): void
    {
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(0);

        $response = $this->actingAs($client)->postJson('/api/consultations', [
            'consultancy_service_code' => $service->code,
            'attendee_name'      => 'Jane Client',
            'attendee_email'     => 'jane@client.example.com',
            'attendee_timezone'  => 'Europe/London',
            'date'               => $date,
            'start_time'         => '12:00',
            'timezone'           => 'Europe/London',
            'title'              => 'Enquiry',
            'description'        => 'Description.',
        ]);
        $response->assertStatus(201);
        $this->assertNoSensitiveKeysAnywhere($response->json());
    }

    public function test_cancel_response_never_contains_sensitive_fields(): void
    {
        [, $client] = $this->makeOrgAndUser('Client');
        $booking = $this->bookAsClient($client, $this->makeService(), 1);

        $response = $this->actingAs($client)->postJson("/api/consultations/{$booking['id']}/cancel", []);
        $response->assertStatus(200);
        $this->assertNoSensitiveKeysAnywhere($response->json());
    }

    public function test_assigned_consultant_exposes_name_only_never_id(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        \App\Models\SuresignSetting::instance()->update(['consultancy_consultant_user_id' => $staff->id]);
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService();
        \App\Models\AppointmentAvailability::create([
            'user_id' => $staff->id, 'context' => \App\Support\Appointments\AvailabilityContext::CONSULTANCY, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00', 'is_active' => true,
        ]);
        $booking = $this->bookAsClient($client, $service, 1);

        $this->assertSame($staff->name, $booking['assigned_user']['name']);
        $this->assertCount(1, $booking['assigned_user']);
        $this->assertArrayNotHasKey('id', $booking['assigned_user']);
        $this->assertArrayNotHasKey('email', $booking['assigned_user']);
    }

    // ── Authorization is preserved, not replaced by the presenter ───────────

    public function test_client_from_another_organisation_still_gets_403_never_a_shaped_response(): void
    {
        [, $clientA] = $this->makeOrgAndUser('Client');
        [, $clientB] = $this->makeOrgAndUser('Client');
        $booking = $this->bookAsClient($clientA, $this->makeService(), 2);

        $this->actingAs($clientB)->getJson("/api/consultations/{$booking['id']}")->assertStatus(403);
    }

    public function test_presenter_alone_cannot_be_reached_without_passing_authorization_first(): void
    {
        // The presenter has no authorization logic of its own — this test
        // confirms the controller's authorizeOwnOrganization() still runs
        // BEFORE ConsultationPresenter::customerFacing() is ever called for
        // a cross-organisation request (a 403, not a 200 with fields removed).
        [, $clientA] = $this->makeOrgAndUser('Client');
        [, $clientB] = $this->makeOrgAndUser('Client');
        $booking = $this->bookAsClient($clientA, $this->makeService(), 3);

        $response = $this->actingAs($clientB)->getJson("/api/consultations/{$booking['id']}");
        $response->assertStatus(403);
        $this->assertArrayNotHasKey('consultation_enquiry', $response->json());
    }
}
