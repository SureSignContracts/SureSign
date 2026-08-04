<?php

namespace Tests\Feature;

use App\Models\AppointmentAvailability;
use App\Models\ConsultancyService;
use App\Models\Organization;
use App\Models\User;
use App\Services\Consultancy\ConsultancyCatalogueService;
use App\Support\Appointments\AvailabilityContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Consultancy — Phase C1 (Foundation). See
 * internal-docs/commercial/suresign-consultancy-specification-v1.md.
 */
class ConsultancyPhase1Test extends TestCase
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
            'code'                             => "test-service-{$n}",
            'display_name'                     => "Test Service {$n}",
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

    private function grantOpenAvailability(User $staff): void
    {
        for ($weekday = 0; $weekday <= 6; $weekday++) {
            AppointmentAvailability::create([
                'user_id' => $staff->id, 'context' => AvailabilityContext::CONSULTANCY, 'weekday' => $weekday,
                'start_time' => '09:00', 'end_time' => '17:00', 'is_active' => true,
            ]);
        }
    }

    /**
     * Consultancy Live Booking Upgrade, Stage 1 — the Consultancy
     * consultant is now a platform-wide setting
     * (App\Services\Consultancy\ConsultancyConsultantResolver), never a
     * per-service field. Tests that need Consultancy scheduling to be
     * "fixed" configure it here instead of passing assignment_mode/
     * default_consultant_user_id to makeService().
     */
    private function configureConsultant(User $staff): void
    {
        \App\Models\SuresignSetting::instance()->update(['consultancy_consultant_user_id' => $staff->id]);
    }

    // ── Catalogue / AppointmentType sync ────────────────────────────────────

    public function test_creating_a_consultancy_service_creates_a_linked_appointment_type(): void
    {
        $service = $this->makeService(['display_name' => 'Standard Consultation', 'duration_minutes' => 45]);

        $this->assertNotNull($service->appointment_type_id);
        $this->assertSame('Standard Consultation', $service->appointmentType->name);
        $this->assertSame(45, $service->appointmentType->duration_minutes);
        $this->assertTrue($service->appointmentType->is_active);
        $this->assertTrue($service->appointmentType->is_public);
    }

    public function test_updating_a_consultancy_service_keeps_the_linked_appointment_type_in_sync(): void
    {
        $service = $this->makeService();

        app(ConsultancyCatalogueService::class)->update($service, [
            'display_name'      => 'Renamed Service',
            'duration_minutes'  => 60,
            'enabled'           => false,
        ]);

        $service->refresh();
        $this->assertSame('Renamed Service', $service->display_name);
        $this->assertSame('Renamed Service', $service->appointmentType->name);
        $this->assertSame(60, $service->appointmentType->duration_minutes);
        $this->assertFalse($service->enabled);
        $this->assertFalse($service->appointmentType->is_active);
    }

    public function test_seeded_default_services_exist_with_expected_pricing_and_duration(): void
    {
        $this->seed(\Database\Seeders\ConsultancyServiceSeeder::class);

        $quick = ConsultancyService::where('code', 'quick-consultation')->firstOrFail();
        $this->assertTrue($quick->is_introductory);
        $this->assertSame(100, $quick->price_minor_units);
        $this->assertSame(15, $quick->appointmentType->duration_minutes);

        $standard = ConsultancyService::where('code', 'standard-consultation')->firstOrFail();
        $this->assertFalse($standard->is_introductory);
        $this->assertSame(4000, $standard->price_minor_units);
        $this->assertSame(30, $standard->appointmentType->duration_minutes);

        $extended = ConsultancyService::where('code', 'extended-consultation')->firstOrFail();
        $this->assertSame(7500, $extended->price_minor_units);
        $this->assertSame(60, $extended->appointmentType->duration_minutes);
    }

    // ── Admin catalogue authorization ───────────────────────────────────────

    public function test_admin_can_manage_the_consultancy_catalogue(): void
    {
        [, $admin] = $this->makeOrgAndUser('Admin');

        $response = $this->actingAs($admin)->postJson('/api/consultancy-services', [
            'code' => 'admin-created', 'display_name' => 'Admin Created', 'duration_minutes' => 30,
        ]);

        $response->assertStatus(201)->assertJsonPath('code', 'admin-created');
    }

    // ── Authenticated (Client) booking — new authorization boundary ────────

    public function test_client_can_list_bookable_services_for_their_organisation(): void
    {
        [, $client] = $this->makeOrgAndUser('Client');
        $available = $this->makeService(['available_to_existing_customers' => true]);
        $this->makeService(['available_to_existing_customers' => false]);

        $response = $this->actingAs($client)->getJson('/api/consultations/bookable-services');

        $response->assertStatus(200);
        $codes = collect($response->json())->pluck('code');
        $this->assertTrue($codes->contains($available->code));
        $this->assertCount(1, $codes);
    }

    public function test_client_can_book_a_consultation_for_their_own_organisation(): void
    {
        [$org, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService(['available_to_existing_customers' => true]);
        $date = $this->nextDateForWeekday(2);

        $response = $this->actingAs($client)->postJson('/api/consultations', [
            'consultancy_service_code' => $service->code,
            'attendee_name'      => 'Jane Client',
            'attendee_email'     => 'jane@client.example.com',
            'attendee_timezone'  => 'Europe/London',
            'date'               => $date,
            'start_time'         => '10:00',
            'timezone'           => 'Europe/London',
            'title'              => 'Payment notice question',
            'description'        => 'We have a dispute over a pay less notice.',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('appointments', ['organization_id' => $org->id, 'status' => 'confirmed']);
        $this->assertDatabaseHas('consultation_enquiries', ['title' => 'Payment notice question', 'submitted_by' => 'authenticated']);
    }

    public function test_client_cannot_view_another_organisations_consultation(): void
    {
        [$orgA, $clientA] = $this->makeOrgAndUser('Client');
        [, $clientB] = $this->makeOrgAndUser('Client');
        $service = $this->makeService(['available_to_existing_customers' => true]);
        $date = $this->nextDateForWeekday(3);

        $booking = $this->actingAs($clientA)->postJson('/api/consultations', [
            'consultancy_service_code' => $service->code,
            'attendee_name'      => 'Jane A',
            'attendee_email'     => 'jane@a.example.com',
            'attendee_timezone'  => 'Europe/London',
            'date'               => $date,
            'start_time'         => '11:00',
            'timezone'           => 'Europe/London',
            'title'              => 'Query',
            'description'        => 'A query.',
        ])->json();

        $this->actingAs($clientB)->getJson("/api/consultations/{$booking['id']}")->assertStatus(403);
    }

    public function test_client_cannot_book_a_service_not_available_to_existing_customers(): void
    {
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService(['available_to_existing_customers' => false]);
        $date = $this->nextDateForWeekday(4);

        $response = $this->actingAs($client)->postJson('/api/consultations', [
            'consultancy_service_code' => $service->code,
            'attendee_name'      => 'Jane Client',
            'attendee_email'     => 'jane@client.example.com',
            'attendee_timezone'  => 'Europe/London',
            'date'               => $date,
            'start_time'         => '10:00',
            'timezone'           => 'Europe/London',
            'title'              => 'Query',
            'description'        => 'A query.',
        ]);

        $response->assertStatus(404);
    }

    // ── Public booking ───────────────────────────────────────────────────────

    public function test_public_service_info_is_visible(): void
    {
        $service = $this->makeService();
        $response = $this->getJson("/api/public/consultancy-services/{$service->code}");
        $response->assertStatus(200)->assertJsonPath('code', $service->code);
    }

    public function test_non_publicly_bookable_service_returns_generic_404(): void
    {
        $service = $this->makeService(['publicly_bookable' => false]);
        $response = $this->getJson("/api/public/consultancy-services/{$service->code}");
        $response->assertStatus(404);
    }

    public function test_public_visitor_can_book_a_consultation(): void
    {
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(5);

        $response = $this->postJson("/api/public/consultancy-services/{$service->code}/book", [
            'attendee_name'      => 'Jane Prospect',
            'attendee_email'     => 'jane@prospect.example.com',
            'attendee_timezone'  => 'Europe/London',
            'date'               => $date,
            'start_time'         => '14:00',
            'timezone'           => 'Europe/London',
            'title'              => 'General enquiry',
            'description'        => 'Interested in a consultation about NEC variations.',
            'consent'            => true,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('consultation_enquiries', ['title' => 'General enquiry', 'submitted_by' => 'public']);
    }

    public function test_public_booking_honeypot_silently_succeeds_without_creating_anything(): void
    {
        $service = $this->makeService();
        $date = $this->nextDateForWeekday(6);

        $response = $this->postJson("/api/public/consultancy-services/{$service->code}/book", [
            'attendee_name'      => 'Bot',
            'attendee_email'     => 'bot@example.com',
            'attendee_timezone'  => 'Europe/London',
            'date'               => $date,
            'start_time'         => '15:00',
            'timezone'           => 'Europe/London',
            'title'              => 'x',
            'description'        => 'x',
            'consent'            => true,
            'website'            => 'http://spam.example.com',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseMissing('consultation_enquiries', ['title' => 'x']);
    }

    // ── Authenticated scheduling-mode alignment ─────────────────────────────
    //
    // The scheduling UI the frontend renders must be driven entirely by the
    // linked AppointmentType's assignment_mode — never inferred from the
    // Consultancy Service's code, price, duration, or is_introductory flag.
    // See internal-docs/commercial/suresign-consultancy-specification-v1.md.

    public function test_authenticated_service_detail_reports_manual_scheduling_mode_by_default(): void
    {
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService(['available_to_existing_customers' => true]);

        $response = $this->actingAs($client)->getJson("/api/consultations/services/{$service->code}");

        $response->assertStatus(200)->assertJsonPath('scheduling_mode', 'manual');
    }

    public function test_authenticated_service_detail_reports_fixed_scheduling_mode_when_type_is_fixed(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->configureConsultant($staff);
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService(['available_to_existing_customers' => true]);

        $response = $this->actingAs($client)->getJson("/api/consultations/services/{$service->code}");

        $response->assertStatus(200)->assertJsonPath('scheduling_mode', 'fixed');
    }

    public function test_authenticated_slots_endpoint_returns_manual_with_no_slots_for_a_manual_service(): void
    {
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService(['available_to_existing_customers' => true]);
        $date = $this->nextDateForWeekday(1);

        $response = $this->actingAs($client)->getJson("/api/consultations/services/{$service->code}/slots?date={$date}&timezone=Europe/London");

        $response->assertStatus(200)->assertJsonPath('scheduling_mode', 'manual')->assertJsonPath('slots', []);
    }

    public function test_authenticated_slots_endpoint_returns_real_slots_for_a_fixed_service(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->grantOpenAvailability($staff);
        $this->configureConsultant($staff);
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService(['available_to_existing_customers' => true]);
        $date = $this->nextDateForWeekday(2);

        $response = $this->actingAs($client)->getJson("/api/consultations/services/{$service->code}/slots?date={$date}&timezone=Europe/London");

        $response->assertStatus(200)->assertJsonPath('scheduling_mode', 'fixed');
        $this->assertNotEmpty($response->json('slots'));
    }

    public function test_authenticated_slots_endpoint_never_exposes_assigned_staff_identity(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->grantOpenAvailability($staff);
        $this->configureConsultant($staff);
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService(['available_to_existing_customers' => true]);
        $date = $this->nextDateForWeekday(3);

        $response = $this->actingAs($client)->getJson("/api/consultations/services/{$service->code}/slots?date={$date}&timezone=Europe/London");

        $response->assertJsonMissingPath('assigned_user_id')->assertJsonMissingPath('staff');
        $this->assertStringNotContainsString($staff->name, $response->getContent());
    }

    public function test_client_booking_a_fixed_mode_service_is_assigned_to_the_configured_staff_member(): void
    {
        [, $staff] = $this->makeOrgAndUser('Admin');
        $this->grantOpenAvailability($staff);
        $this->configureConsultant($staff);
        [$org, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService(['available_to_existing_customers' => true]);
        $date = $this->nextDateForWeekday(4);

        $response = $this->actingAs($client)->postJson('/api/consultations', [
            'consultancy_service_code' => $service->code,
            'attendee_name'      => 'Jane Client',
            'attendee_email'     => 'jane@client.example.com',
            'attendee_timezone'  => 'Europe/London',
            'date'               => $date,
            'start_time'         => '10:00',
            'timezone'           => 'Europe/London',
            'title'              => 'Fixed-mode booking',
            'description'        => 'Testing fixed-mode assignment.',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('appointments', [
            'organization_id'  => $org->id,
            'assigned_user_id' => $staff->id,
        ]);
    }

    public function test_authenticated_service_endpoints_are_not_org_scoped_but_booking_remains_org_isolated(): void
    {
        // Consultancy Services are a global catalogue, not per-organisation
        // data — any authenticated user may read scheduling info for any
        // bookable service. Organisation isolation is enforced at the
        // Appointment/consultation level (see the cross-organisation-access
        // test above), never by hiding the catalogue itself.
        [, $clientA] = $this->makeOrgAndUser('Client');
        [, $clientB] = $this->makeOrgAndUser('Client');
        $service = $this->makeService(['available_to_existing_customers' => true]);

        $this->actingAs($clientA)->getJson("/api/consultations/services/{$service->code}")->assertStatus(200);
        $this->actingAs($clientB)->getJson("/api/consultations/services/{$service->code}")->assertStatus(200);
    }
}
